<?php
/**
 * Typed settings model with mode presets.
 *
 * Settings live in a single autoloaded option `ai_os_settings`
 * (small, scalar, cache-friendly — everything else goes to custom
 * tables per spec §39). All values are validated and defaulted.
 *
 * @package AIOS\Settings
 */

declare( strict_types=1 );

namespace AIOS\Settings;

final class Settings {

	public const OPTION_KEY = 'ai_os_settings';

	public const MODE_SAFE     = 'safe';
	public const MODE_BALANCED = 'balanced';
	public const MODE_ADVANCED = 'advanced';

		/**
		 * Mode → { max auto-exec level, approval threshold }.
		 *
		 * (spec §7/§59)
		 */
	public const MODE_PRESETS = array(
		self::MODE_SAFE     => array(
			'max_level'   => 1,
			'approval_at' => 2,
		),
		self::MODE_BALANCED => array(
			'max_level'   => 2,
			'approval_at' => 3,
		),
		self::MODE_ADVANCED => array(
			'max_level'   => 3,
			'approval_at' => 4,
		),
	);

		/**
		 * Sanitized settings payload.
		 *
		 * @var array<string, mixed>
		 */
	private array $data;

	private bool $dirty = false;

	public function __construct() {
			$this->data = $this->sanitize( $this->load() );
	}

		/**
		 * @return array<string, mixed>
		 */
	private function load(): array {
			$stored = get_option( self::OPTION_KEY, array() );
			return is_array( $stored ) ? $stored : array();
	}

		/**
		 * Defaults + sanitization. Every write path funnels through here so
		 * the option can never hold an unexpected shape.
		 *
		 * @param array<string, mixed> $input
		 * @return array<string, mixed>
		 */
	private function sanitize( array $input ): array {
			$defaults = array(
				// Security mode (spec §59).
				'mode'                     => self::MODE_SAFE,

				// Approvals.
				'approval_ttl_minutes'     => 15,
				'auto_approve_safe'        => false,

				// Rate limiting (per principal, rolling 60s).
				'rate_limit_requests'      => 120,
				'rate_limit_executions'    => 60,

				// MCP.
				'mcp_enabled'              => true,
				'require_https'            => true,

				// API key auth.
				'api_keys_enabled'         => true,

				// File inspection (read-only in Phase 1).
				'file_read_enabled'        => true,
				'file_read_max_bytes'      => 512 * 1024,

				// Audit.
				'audit_retention_days'     => 90,
				'audit_log_args'           => true,

				// Context cache.
				'context_cache_minutes'    => 15,

				// Housekeeping.
				'remove_data_on_uninstall' => false,
			);

			$clean = $defaults;

			if ( isset( $input['mode'] ) && is_string( $input['mode'] )
					&& isset( self::MODE_PRESETS[ $input['mode'] ] ) ) {
					$clean['mode'] = $input['mode'];
			}

			$int_fields = array(
				'approval_ttl_minutes'  => array( 1, 1440 ),
				'rate_limit_requests'   => array( 10, 10000 ),
				'rate_limit_executions' => array( 10, 10000 ),
				'file_read_max_bytes'   => array( 1024, 5 * 1024 * 1024 ),
				'audit_retention_days'  => array( 7, 3650 ),
				'context_cache_minutes' => array( 1, 1440 ),
			);
			foreach ( $int_fields as $field => list( $min, $max ) ) {
				if ( isset( $input[ $field ] ) && is_numeric( $input[ $field ] ) ) {
						$clean[ $field ] = (int) max( $min, min( $max, (int) $input[ $field ] ) );
				}
			}

			foreach ( array( 'auto_approve_safe', 'mcp_enabled', 'require_https', 'api_keys_enabled', 'file_read_enabled', 'audit_log_args', 'remove_data_on_uninstall' ) as $flag ) {
				if ( array_key_exists( $flag, $input ) ) {
						$clean[ $flag ] = (bool) $input[ $flag ];
				}
			}

			return $clean;
	}

		/**
		 * Persist the current settings (only when dirty).
		 */
	public function save(): void {
		if ( $this->dirty ) {
				update_option( self::OPTION_KEY, $this->data, true );
				$this->dirty = false;
		}
	}

		/**
		 * Replace settings from an admin-supplied payload.
		 *
		 * @param array<string, mixed> $input
		 */
	public function update( array $input ): void {
			$merged = array_merge( $this->data, $input );
			$clean  = $this->sanitize( $merged );
		if ( $clean !== $this->data ) {
				$this->data  = $clean;
				$this->dirty = true;
		}
			$this->save();
	}

		// ------------------------------------------------------------------ accessors

	public function mode(): string {
			return $this->data['mode'];
	}

	public function maxAutoLevel(): int {
			return self::MODE_PRESETS[ $this->mode() ]['max_level'];
	}

	public function approvalThreshold(): int {
			return self::MODE_PRESETS[ $this->mode() ]['approval_at'];
	}

	public function approvalTtlMinutes(): int {
			return $this->data['approval_ttl_minutes'];
	}

	public function autoApproveSafe(): bool {
			return $this->data['auto_approve_safe'];
	}

	public function rateLimitRequests(): int {
			return $this->data['rate_limit_requests'];
	}

	public function rateLimitExecutions(): int {
			return $this->data['rate_limit_executions'];
	}

	public function mcpEnabled(): bool {
			return $this->data['mcp_enabled'];
	}

	public function requireHttps(): bool {
			return $this->data['require_https'];
	}

	public function apiKeysEnabled(): bool {
			return $this->data['api_keys_enabled'];
	}

	public function fileReadEnabled(): bool {
			return $this->data['file_read_enabled'];
	}

	public function fileReadMaxBytes(): int {
			return $this->data['file_read_max_bytes'];
	}

	public function auditRetentionDays(): int {
			return $this->data['audit_retention_days'];
	}

	public function auditLogArgs(): bool {
			return $this->data['audit_log_args'];
	}

	public function contextCacheMinutes(): int {
			return $this->data['context_cache_minutes'];
	}

	public function removeDataOnUninstall(): bool {
			return $this->data['remove_data_on_uninstall'];
	}

		/**
		 * Complete, sanitized settings payload (for the admin UI).
		 *
		 * @return array<string, mixed>
		 */
	public function toArray(): array {
			return $this->data;
	}

	public static function defaults(): array {
			$settings = new self();
			return $settings->toArray();
	}
}
