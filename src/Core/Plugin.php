<?php
/**
 * Plugin kernel.
 *
 * Boots the container, registers providers and lazily initializes
 * subsystems through hooks. Frontend requests only execute `boot()`
 * which registers hook callbacks — no real work happens on the
 * frontend (spec §38).
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

final class Plugin {

	private const HOOK = 'ai_os/booted';

	private static ?Plugin $instance = null;

	private Container $container;

	private bool $booted = false;

	public function __construct( ?Container $container = null ) {
		$this->container = $container ?? new Container();
		$this->container->instance( self::class, $this );
	}

	public static function instance(): ?self {
		return self::$instance;
	}

	public function container(): Container {
		return $this->container;
	}

	/**
	 * Boot order:
	 *   1. load textdomain
	 *   2. register services (bind only, no resolution)
	 *   3. boot services (resolve + hook) — deferred to plugins_loaded
	 *      so integrations can hook ai_os_container_build first.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		add_action( 'init', array( $this, 'loadTextdomain' ), 5 );

		$provider = new CoreServiceProvider();
		$provider->register( $this->container );

		add_action(
			'plugins_loaded',
			function () use ( $provider ): void {
				try {
					$provider->boot( $this->container );
				} catch ( \Throwable $e ) {
					$this->failSoft( $e );
					return;
				}
				$this->booted = true;
				/**
				 * Action: AI WordPress OS fully booted. Safe to
				 * resolve services and add late integrations.
				 */
				do_action( self::HOOK, $this );
			},
			5
		);

		self::$instance = $this;
	}

	public function loadTextdomain(): void {
		load_plugin_textdomain( 'ai-wordpress-os', false, dirname( AI_WP_OS_BASENAME ) . '/languages' );
	}

	public function isBooted(): bool {
		return $this->booted;
	}

	/**
	 * Never take a site down over an internal boot error: log it,
	 * surface to admins, keep the site alive.
	 */
	private function failSoft( \Throwable $e ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[AI WordPress OS] boot error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
		add_action(
			'admin_notices',
			static function () use ( $e ): void {
				if ( ! current_user_can( 'manage_options' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>AI WordPress OS:</strong> boot error — %s</p></div>',
					esc_html( $e->getMessage() )
				);
			}
		);
	}
}
