<?php
/**
 * Core service provider: binds every subsystem and hooks the
 * context-aware bootstrapping.
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

use AIOS\Abilities\AbilityRegistry;
use AIOS\Audit\AuditIntegrity;
use AIOS\Audit\AuditLogger;
use AIOS\Context\ContextEngine;
use AIOS\Database\Database;
use AIOS\Database\Migrator;
use AIOS\Database\Repositories\ApiKeyRepository;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Database\Repositories\AuditLogRepository;
use AIOS\Database\Repositories\RateLimitRepository;
use AIOS\Database\Repositories\ToolExecutionRepository;
use AIOS\Mcp\Protocol\JsonRpcRequest;
use AIOS\Mcp\Server;
use AIOS\Mcp\Transports\RestTransport;
use AIOS\Mutation\ChangeSetRepository;
use AIOS\Mutation\DurableMutationCoordinator;
use AIOS\Mutation\MutationEngine;
use AIOS\Rest\Controllers\ToolsController;
use AIOS\Rest\RestApi;
use AIOS\Security\ApiKeyManager;
use AIOS\Security\Authenticator;
use AIOS\Security\CapabilityManager;
use AIOS\Security\PermissionEngine;
use AIOS\Security\RateLimiter;
use AIOS\Settings\Settings;
use AIOS\Support\Crypto;
use AIOS\Tools\Catalog\CatalogProviderInterface;
use AIOS\Tools\Catalog\ContextTools;
use AIOS\Tools\Catalog\ContentTools;
use AIOS\Tools\Catalog\LogTools;
use AIOS\Tools\Catalog\MediaTools;
use AIOS\Tools\Catalog\MenuTools;
use AIOS\Tools\Catalog\PluginTools;
use AIOS\Tools\Catalog\SiteTools;
use AIOS\Tools\Catalog\SystemTools;
use AIOS\Tools\Catalog\ThemeTools;
use AIOS\Tools\Catalog\UserTools;
use AIOS\Tools\ToolExecutor;
use AIOS\Tools\ToolRegistry;

final class CoreServiceProvider implements ServiceProviderInterface {

	/**
	 * Catalog providers, in registration order.
	 *
	 * @var array<int, class-string<CatalogProviderInterface>>
	 */
	private const CATALOG = array(
		SiteTools::class,
		ContentTools::class,
		MediaTools::class,
		ThemeTools::class,
		PluginTools::class,
		UserTools::class,
		MenuTools::class,
		SystemTools::class,
		LogTools::class,
		ContextTools::class,
	);

	public function register( Container $container ): void {
		// Settings (bound first; many services depend on it).
		$container->bind( Settings::class, static fn(): Settings => new Settings() );

		// Database + repositories.
		$container->bind( Database::class, static fn(): Database => new Database() );
		$container->bind( AuditIntegrity::class, static fn(): AuditIntegrity => new AuditIntegrity() );
		$container->bind( AuditLogRepository::class, static fn( Container $c ): AuditLogRepository => new AuditLogRepository( $c->get( Database::class ), $c->get( AuditIntegrity::class ) ) );
		$container->bind( ToolExecutionRepository::class, static fn( Container $c ): ToolExecutionRepository => new ToolExecutionRepository( $c->get( Database::class ) ) );
		$container->bind( ApprovalRepository::class, static fn( Container $c ): ApprovalRepository => new ApprovalRepository( $c->get( Database::class ) ) );
		$container->bind( ApiKeyRepository::class, static fn( Container $c ): ApiKeyRepository => new ApiKeyRepository( $c->get( Database::class ) ) );
		$container->bind( RateLimitRepository::class, static fn( Container $c ): RateLimitRepository => new RateLimitRepository( $c->get( Database::class ) ) );
		$container->bind( Migrator::class, static fn(): Migrator => new Migrator() );

		// Security.
		$container->bind( PermissionEngine::class, static fn( Container $c ): PermissionEngine => new PermissionEngine( $c->get( Settings::class ) ) );
		$container->bind( ApiKeyManager::class, static fn( Container $c ): ApiKeyManager => new ApiKeyManager( $c->get( ApiKeyRepository::class ) ) );
		$container->bind( RateLimiter::class, static fn( Container $c ): RateLimiter => new RateLimiter( $c->get( RateLimitRepository::class ) ) );

		// Audit.
		$container->bind( AuditLogger::class, static fn( Container $c ): AuditLogger => new AuditLogger( $c->get( AuditLogRepository::class ), $c->get( Settings::class ), $c->get( AuditIntegrity::class ) ) );

		// Capability grant/revoke (depends on AuditLogger; bound after it above).
		$container->bind( CapabilityManager::class, static fn( Container $c ): CapabilityManager => new CapabilityManager( $c->get( AuditLogger::class ) ) );

		// Phase 2 mutation pipeline foundation (docs/ARCHITECTURE.md §13).
		// Bound here so it is resolvable/testable via the container like
		// every other service, but NOT registered with RestApi, ToolRegistry,
		// or the MCP server — nothing wires an AI-facing caller to it yet.
		$container->bind(
			MutationEngine::class,
			static fn( Container $c ): MutationEngine => new MutationEngine(
				$c->get( PermissionEngine::class ),
				$c->get( ApprovalRepository::class ),
				$c->get( AuditLogger::class )
			)
		);

		// Phase 2 durable persistence. No dedicated key configured: uses
		// the same environment-derived key every other Crypto call site
		// would default to (SEC-M5) — no new secret-management service.
		$container->bind( Crypto::class, static fn(): Crypto => new Crypto() );
		$container->bind( \AIOS\Mutation\OperationJournalRepository::class, static fn( Container $c ): \AIOS\Mutation\OperationJournalRepository => new \AIOS\Mutation\OperationJournalRepository( $c->get( Database::class ), $c->get( Crypto::class ) ) );
		$container->bind(
			ChangeSetRepository::class,
			static fn( Container $c ): ChangeSetRepository => new ChangeSetRepository(
				$c->get( Database::class ),
				$c->get( Crypto::class ),
				$c->get( \AIOS\Mutation\OperationJournalRepository::class )
			)
		);
		$container->bind(
			DurableMutationCoordinator::class,
			static fn( Container $c ): DurableMutationCoordinator => new DurableMutationCoordinator(
				$c->get( ChangeSetRepository::class ),
				$c->get( MutationEngine::class ),
				$c->get( \AIOS\Mutation\OperationJournalRepository::class ),
				$c->get( AuditLogger::class )
			)
		);
		$container->bind( \AIOS\Mutation\TypedChangeSetBuilder::class, static fn(): \AIOS\Mutation\TypedChangeSetBuilder => new \AIOS\Mutation\TypedChangeSetBuilder() );

		// Registries.
		$container->bind( AbilityRegistry::class, static fn(): AbilityRegistry => new AbilityRegistry() );
		$container->bind( ToolRegistry::class, static fn(): ToolRegistry => new ToolRegistry() );

		// Execution pipeline.
		$container->bind(
			ToolExecutor::class,
			static fn( Container $c ): ToolExecutor => new ToolExecutor(
				$c->get( ToolRegistry::class ),
				$c->get( AbilityRegistry::class ),
				$c->get( PermissionEngine::class ),
				$c->get( AuditLogger::class ),
				$c->get( ToolExecutionRepository::class ),
				$c->get( ApprovalRepository::class ),
				$c->get( Settings::class ),
				$c->get( RateLimiter::class )
			)
		);

		// Context.
		$container->bind( ContextEngine::class, static fn( Container $c ): ContextEngine => new ContextEngine( $c->get( Settings::class ) ) );

		// MCP.
		$container->bind(
			Server::class,
			static fn( Container $c ): Server => new Server(
				$c->get( ToolRegistry::class ),
				$c->get( ToolExecutor::class ),
				$c->get( Settings::class ),
				$c->get( PermissionEngine::class )
			)
		);
		$container->bind(
			RestTransport::class,
			static fn( Container $c ): RestTransport => new RestTransport(
				$c->get( Server::class ),
				$c->get( Settings::class )
			)
		);

		/**
		 * Filter: integrations may REPLACE core services after
		 * registration (e.g. a decorated PermissionEngine). The
		 * container is still mutable at this point.
		 */
		do_action( 'ai_os_container_build', $container );
	}

	public function boot( Container $container ): void {
		$abilities = $container->get( AbilityRegistry::class );
		$tools     = $container->get( ToolRegistry::class );

		// Core catalog (always registers; availability conditions are
		// evaluated lazily per request).
		foreach ( self::CATALOG as $provider ) {
			$provider::register( $abilities, $tools );
		}

		// Seal registries with third-party filters.
		$abilities->boot();
		$tools->boot();

		// REST API (includes the MCP transport route).
		$rest = $container->get( RestApi::class );
		if ( $rest instanceof RestApi ) {
			$rest->hook();
		}

		// Context cache invalidation hooks.
		ContextEngine::hookInvalidation();

		// Admin UI (admin context only).
		if ( is_admin() ) {
			( new \AIOS\Admin\AdminPages( $container ) )->hook();
		}

		// WP-CLI commands (CLI context only).
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\AIOS\Cli\CliCommands::register();
		}

		// Maintenance cron.
		add_action( 'ai_os_daily_maintenance', array( $this, 'runMaintenance' ) );
		if ( ! wp_next_scheduled( 'ai_os_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ai_os_daily_maintenance' );
		}

		// Provision a site created after network activation (BUG-005):
		// WordPress fires this once, with the new WP_Site, whenever a
		// site is added to the network — regardless of which site's
		// request happened to trigger the creation.
		add_action( 'wp_insert_site', array( new Activator(), 'provisionNewSite' ) );
	}

	/**
	 * Daily maintenance: audit retention purge + execution stats purge.
	 */
	public function runMaintenance(): void {
		$container = Plugin::instance()?->container();
		if ( null === $container ) {
			return;
		}

		/** @var Settings $settings */
		$settings = $container->get( Settings::class );
		$days     = $settings->auditRetentionDays();

		/** @var AuditLogRepository $logs */
		$logs = $container->get( AuditLogRepository::class );
		$logs->purgeOlderThan( $days );

		/** @var ToolExecutionRepository $execs */
		$execs = $container->get( ToolExecutionRepository::class );
		$execs->purgeOlderThan( max( 7, $days ) );

		/** @var RateLimitRepository $rate_limits */
		$rate_limits = $container->get( RateLimitRepository::class );
		$rate_limits->purgeExpired();

		// Terminal ChangeSets only (never pending/active/manual-recovery-
		// required, regardless of age — see ChangeSetRepository::
		// purgeTerminalOlderThan()). Reuses the same retention window as
		// the audit log; batch-limited per run.
		/** @var \AIOS\Mutation\ChangeSetRepository $change_sets */
		$change_sets = $container->get( \AIOS\Mutation\ChangeSetRepository::class );
		$change_sets->purgeTerminalOlderThan( $days );
	}
}
