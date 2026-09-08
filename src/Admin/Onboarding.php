<?php
/**
 * First-run onboarding wizard (spec §59).
 *
 * Steps: security mode → API key (optional) → site scan confirmation.
 * PHP-rendered form (no JS dependency), POSTs to itself with nonce,
 * writes settings through the Settings service (never raw options).
 *
 * @package AIOS\Admin
 */

declare( strict_types=1 );

namespace AIOS\Admin;

use AIOS\Audit\AuditLogger;
use AIOS\Core\Container;
use AIOS\Context\ContextEngine;
use AIOS\Security\ApiKeyManager;
use AIOS\Settings\Settings;

final class Onboarding {

	private const ACTION = 'ai_os_onboarding';

	private Container $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access AI WordPress OS onboarding.', 'ai-wordpress-os' ) );
		}

		$submitted = isset( $_POST['ai_os_onboarding_submit'] ) ? $this->handleSubmission() : null;

		/** @var Settings $settings */
		$settings = $this->container->get( Settings::class );

		$mode = $submitted['mode'] ?? $settings->mode();
		?>
		<div class="wrap ai-os-onboarding-wrap">
			<h1><?php esc_html_e( 'Welcome to AI WordPress OS', 'ai-wordpress-os' ); ?></h1>
			<p class="ai-os-onboarding-intro">
				<?php esc_html_e( 'Turn your WordPress site into a secure, AI-operable platform. This one-time setup takes about two minutes: choose a security posture, optionally create an API key for external AI clients, and run the first site scan.', 'ai-wordpress-os' ); ?>
			</p>

			<?php if ( null !== $submitted && ! empty( $submitted['api_key'] ) ) : ?>
				<div class="notice notice-success ai-os-key-reveal">
					<p><strong><?php esc_html_e( 'Your API key (shown once, copy it now):', 'ai-wordpress-os' ); ?></strong></p>
					<code class="ai-os-key-code"><?php echo esc_html( $submitted['api_key'] ); ?></code>
					<p><?php esc_html_e( 'Send it as the X-AI-OS-Key header on every MCP request. It is stored only as a hash and cannot be recovered later.', 'ai-wordpress-os' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( null !== $submitted && empty( $submitted['api_key'] ) ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Settings saved. AI WordPress OS is ready.', 'ai-wordpress-os' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=ai-wordpress-os-onboarding' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<h2><?php esc_html_e( '1. Security mode', 'ai-wordpress-os' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Controls what AI clients may execute without asking you first.', 'ai-wordpress-os' ); ?></p>
				<table class="form-table" role="presentation">
					<tbody>
						<?php foreach ( $this->modes() as $value => $mode_info ) : ?>
						<tr>
							<th scope="row">
								<label>
									<input type="radio" name="ai_os_mode" value="<?php echo esc_attr( $value ); ?>" <?php checked( $mode, $value ); ?> />
									<strong><?php echo esc_html( $mode_info['label'] ); ?></strong>
									<?php if ( 'safe' === $value ) : ?><span class="ai-os-badge ai-os-badge-recommended"><?php esc_html_e( 'Recommended', 'ai-wordpress-os' ); ?></span><?php endif; ?>
								</label>
							</th>
							<td>
								<?php echo esc_html( $mode_info['description'] ); ?>
							</td>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( '2. API key for external AI clients (optional)', 'ai-wordpress-os' ); ?></h2>
				<p class="description">
					<?php esc_html_e( 'ChatGPT, Claude, Claude Code and Cursor can also connect with a WordPress Application Password instead. An AI OS API key is bound to your account, carries a permission ceiling, and can be revoked at any time.', 'ai-wordpress-os' ); ?>
				</p>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="ai_os_key_label"><?php esc_html_e( 'Create key', 'ai-wordpress-os' ); ?></label></th>
							<td>
								<input type="text" id="ai_os_key_label" name="ai_os_key_label" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. Claude Code laptop', 'ai-wordpress-os' ); ?>" />
								<p class="description">
									<?php esc_html_e( 'Leave empty to skip. Maximum level: 1 (safe writes) — you can raise it later in Security settings.', 'ai-wordpress-os' ); ?>
								</p>
							</td>
						</tr>
					</tbody>
				</table>

				<h2><?php esc_html_e( '3. First site scan', 'ai-wordpress-os' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Builds the structured site knowledge map (theme, plugins, content types, menus) that AI agents use to understand your site. Runs now, takes seconds.', 'ai-wordpress-os' ); ?></p>

				<p>
					<button type="submit" name="ai_os_onboarding_submit" value="1" class="button button-primary button-hero">
						<?php esc_html_e( 'Finish setup and scan site', 'ai-wordpress-os' ); ?>
					</button>
					<a class="button button-hero" href="<?php echo esc_url( admin_url( 'admin.php?page=ai-wordpress-os' ) ); ?>">
						<?php esc_html_e( 'Skip and open the console', 'ai-wordpress-os' ); ?>
					</a>
				</p>
			</form>
		</div>
		<style>
			.ai-os-onboarding-intro { max-width: 640px; font-size: 14px; }
			.ai-os-badge-recommended { margin-left: 8px; padding: 2px 8px; background: #2271b1; color: #fff; border-radius: 10px; font-weight: 600; font-size: 11px; }
			.ai-os-key-reveal code.ai-os-key-code { display: block; font-size: 15px; padding: 10px 12px; word-break: break-all; user-select: all; }
		</style>
		<?php
	}

	/**
	 * @return array<string, array{label: string, description: string}>
	 */
	private function modes(): array {
		return array(
			Settings::MODE_SAFE => array(
				'label'       => __( 'Safe mode', 'ai-wordpress-os' ),
				'description' => __( 'AI can inspect the site and make safe writes (create/update posts, pages, media). Everything sensitive or destructive requires your explicit approval first.', 'ai-wordpress-os' ),
			),
			Settings::MODE_BALANCED => array(
				'label'       => __( 'Balanced mode', 'ai-wordpress-os' ),
				'description' => __( 'Level 2 actions (trashing content, cache flush) run directly; destructive and deployment actions still require approval.', 'ai-wordpress-os' ),
			),
			Settings::MODE_ADVANCED => array(
				'label'       => __( 'Advanced mode', 'ai-wordpress-os' ),
				'description' => __( 'Destructive actions execute without approval (deployment-level actions still gated). Only choose this on development sites.', 'ai-wordpress-os' ),
			),
		);
	}

	/**
	 * Handle the POST: mode → settings, optional key issue, scan, mark
	 * onboarded, audit.
	 *
	 * @return array{mode: string, api_key?: string}
	 */
	private function handleSubmission(): array {
		if ( ! check_admin_referer( self::ACTION ) ) {
			return array( 'mode' => '' );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return array( 'mode' => '' );
		}

		/** @var Settings $settings */
		$settings = $this->container->get( Settings::class );

		$mode = isset( $_POST['ai_os_mode'] ) ? sanitize_key( wp_unslash( $_POST['ai_os_mode'] ) ) : '';
		if ( ! isset( Settings::MODE_PRESETS[ $mode ] ) ) {
			$mode = Settings::MODE_SAFE;
		}

		$settings->update( array( 'mode' => $mode ) );

		$issued_key = '';

		$label = isset( $_POST['ai_os_key_label'] ) ? sanitize_text_field( wp_unslash( $_POST['ai_os_key_label'] ) ) : '';
		if ( '' !== trim( $label ) ) {
			/** @var ApiKeyManager $keys */
			$keys = $this->container->get( ApiKeyManager::class );
			try {
				$issued = $keys->issue(
					array(
						'label'     => $label,
						'user_id'   => get_current_user_id(),
						'max_level' => 1,
					)
				);
				$issued_key = (string) ( $issued['key'] ?? '' );
			} catch ( \Throwable $e ) {
				$issued_key = '';
			}
		}

		// First site scan (cache warm).
		try {
			/** @var ContextEngine $context */
			$context = $this->container->get( ContextEngine::class );
			$context->siteMap( true );
		} catch ( \Throwable $e ) {
			// Non-fatal.
		}

		update_option( 'ai_os_onboarded', true, true );

		/** @var AuditLogger $audit */
		$audit = $this->container->get( AuditLogger::class );
		$audit->log(
			array(
				'user'   => wp_get_current_user(),
				'client' => 'onboarding',
				'tool'   => 'onboarding.complete',
				'action' => sprintf( 'onboarded (mode: %s, api key: %s)', $mode, '' !== $issued_key ? 'created' : 'skipped' ),
				'risk'   => 0,
				'status' => AuditLogger::STATUS_OK,
			)
		);

		return array(
			'mode'     => $mode,
			'api_key'  => $issued_key,
		);
	}
}
