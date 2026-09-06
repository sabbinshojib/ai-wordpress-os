<?php
/**
 * Admin pages: menu, screens, asset pipeline (spec §34/§35).
 *
 * Professional developer-console experience delivered by a React
 * application (built file in assets/js) that talks to the plugin's
 * REST endpoints. The PHP side only mounts the app with a bootstrap
 * config — no business logic here.
 *
 * @package AIOS\Admin
 */

declare( strict_types=1 );

namespace AIOS\Admin;

use AIOS\Core\Container;
use AIOS\Database\Repositories\ApprovalRepository;

final class AdminPages {

	private const SLUG = 'ai-wordpress-os';

	private Container $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function hook(): void {
		add_action( 'admin_menu', array( $this, 'registerMenu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueueAssets' ) );
		add_action( 'admin_notices', array( $this, 'activationErrorNotice' ) );
		add_action( 'admin_notices', array( $this, 'environmentWarningNotice' ) );

		// First-run onboarding redirect.
		add_action( 'admin_init', array( $this, 'maybeRedirectOnboarding' ) );

		// Pending approvals bubble.
		add_action( 'admin_menu', array( $this, 'approvalBadge' ), 20 );
	}

	/**
	 * Menu structure (spec §34): one console app + onboarding.
	 */
	public function registerMenu(): void {
		add_menu_page(
			__( 'AI WordPress OS', 'ai-wordpress-os' ),
			__( 'AI OS', 'ai-wordpress-os' ),
			'manage_options',
			self::SLUG,
			array( $this, 'renderApp' ),
			'dashicons-welcome-view-site',
			59
		);

		add_submenu_page(
			self::SLUG,
			__( 'Onboarding', 'ai-wordpress-os' ),
			__( 'Onboarding', 'ai-wordpress-os' ),
			'manage_options',
			self::SLUG . '-onboarding',
			array( $this, 'renderOnboarding' )
		);
	}

	/**
	 * Pending-approval count in the menu title.
	 */
	public function approvalBadge(): void {
		global $menu;

		try {
			/** @var ApprovalRepository $approvals */
			$approvals = $this->container->get( ApprovalRepository::class );
			$pending   = $approvals->pendingCount();
		} catch ( \Throwable $e ) {
			return;
		}

		if ( 0 === $pending ) {
			return;
		}

		foreach ( (array) $menu as $index => $item ) {
			if ( isset( $item[2] ) && self::SLUG === $item[2] ) {
				$menu[ $index ][0] = sprintf(
					/* translators: %s: pending approvals count */
					_x( 'AI OS %s', 'menu title with pending badge', 'ai-wordpress-os' ),
					'<span class="awaiting-mod count-' . (int) $pending . '"><span class="pending-count">' . (int) $pending . '</span></span>'
				);
				break;
			}
		}
	}

	/**
	 * Enqueue the built React app (only on our screens).
	 */
	public function enqueueAssets( string $hook ): void {
		$screen_prefix = 'toplevel_page_' . self::SLUG;
		if ( ! str_starts_with( $hook, $screen_prefix ) ) {
			return;
		}

		wp_enqueue_style(
			'ai-os-admin',
			AI_WP_OS_URL . 'assets/css/admin.css',
			array(),
			AI_WP_OS_VERSION
		);

		wp_enqueue_script(
			'ai-os-dashboard',
			AI_WP_OS_URL . 'assets/js/admin-dashboard.js',
			array(),
			AI_WP_OS_VERSION,
			true
		);

		wp_localize_script(
			'ai-os-dashboard',
			'AIOS_BOOT',
			$this->bootstrapConfig()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function bootstrapConfig(): array {
		return array(
			'version'   => AI_WP_OS_VERSION,
			'restBase'  => rest_url( AI_WP_OS_REST_NAMESPACE ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'mcp'       => array(
				'endpoint' => rest_url( AI_WP_OS_REST_NAMESPACE . '/mcp' ),
				'protocol' => AI_WP_OS_MCP_PROTOCOL_VERSION,
			),
			'adminUrl'  => admin_url( 'admin.php?page=' . self::SLUG ),
			'canManage' => current_user_can( 'manage_options' ),
			'onboarded' => (bool) get_option( 'ai_os_onboarded', false ),
			'phases'    => array(
				'phase1' => 'Foundation (shipped)',
				'phase2' => 'AI Developer — file engine, rollback, tasks (in development)',
				'phase3' => 'Builder intelligence — Elementor, WooCommerce, SEO (planned)',
				'phase4' => 'Multi-agent, visual browser, sandbox (planned)',
			),
		);
	}

	/**
	 * The console application mount point.
	 */
	public function renderApp(): void {
		$this->renderShell( 'ai-os-app', __( 'AI WordPress OS — Console', 'ai-wordpress-os' ) );
	}

	/**
	 * PHP-rendered onboarding wizard (works even without the JS app;
	 * posts to admin-post with nonce).
	 */
	public function renderOnboarding(): void {
		( new Onboarding( $this->container ) )->render();
	}

	/**
	 * @param string $mount_id React mount node id.
	 */
	private function renderShell( string $mount_id, string $title ): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access AI WordPress OS.', 'ai-wordpress-os' ) );
		}

		printf(
			'<div class="wrap ai-os-wrap"><h1 class="ai-os-screen-reader">%s</h1><div id="%s" class="ai-os-root"></div></div>',
			esc_html( $title ),
			esc_attr( $mount_id )
		);
	}

	/**
	 * First visit: redirect to onboarding.
	 */
	public function maybeRedirectOnboarding(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( get_option( 'ai_os_onboarded', false ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( null === $screen || 'dashboard' !== $screen->base ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG . '-onboarding' ) );
		exit;
	}

	/**
	 * Migration failure notice (activation-time issue surfaced).
	 */
	public function activationErrorNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$error = get_transient( 'ai_os_activation_error' );
		if ( ! is_array( $error ) ) {
			return;
		}
		delete_transient( 'ai_os_activation_error' );

		printf(
			'<div class="notice notice-error"><p><strong>AI WordPress OS:</strong> database migration <code>%s</code> failed: %s. Run <code>wp ai-os migrate</code> or contact support.</p></div>',
			esc_html( (string) ( $error['version'] ?? '' ) ),
			esc_html( (string) ( $error['error'] ?? '' ) )
		);
	}

	/**
	 * Non-blocking notice for a degraded (but never fatal) optional
	 * capability — e.g. a missing PHP extension that a graceful
	 * fallback compensates for. This is informational (notice-warning),
	 * unlike activationErrorNotice() above, which reports an actual
	 * failure (notice-error).
	 */
	public function environmentWarningNotice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$warnings = get_transient( 'ai_os_environment_warnings' );
		if ( ! is_array( $warnings ) || array() === $warnings ) {
			return;
		}

		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'AI WordPress OS — reduced capability on this host:', 'ai-wordpress-os' ) . '</strong></p><ul style="list-style:disc;margin-left:1.5em;">';
		foreach ( $warnings as $warning ) {
			printf( '<li>%s</li>', esc_html( (string) $warning ) );
		}
		echo '</ul></div>';
	}
}
