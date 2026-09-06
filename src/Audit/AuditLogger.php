<?php
/**
 * AuditLogger — records every meaningful AI action (spec §9).
 *
 * All writes funnel through here from ToolExecutor, the approval
 * engine and the REST controllers. Arguments are hashed (for
 * traceability) and optionally stored fully (setting-gated, always
 * REDACTED). IP is packed binary. Failures never break the audited
 * operation, but they are surfaced in the internal debug log.
 *
 * @package AIOS\Audit
 */

declare( strict_types=1 );

namespace AIOS\Audit;

use AIOS\Database\Repositories\AuditLogRepository;
use AIOS\Settings\Settings;
use AIOS\Support\Sanitize;
use AIOS\Support\Strings;
use WP_User;

final class AuditLogger {

	public const STATUS_OK       = 'ok';
	public const STATUS_ERROR    = 'error';
	public const STATUS_BLOCKED  = 'blocked';
	public const STATUS_APPROVAL = 'approval_required';
	public const STATUS_REJECTED = 'rejected';

	private AuditLogRepository $logs;

	private Settings $settings;

	private AuditIntegrity $integrity;

	public function __construct( AuditLogRepository $logs, Settings $settings, ?AuditIntegrity $integrity = null ) {
		$this->logs      = $logs;
		$this->settings  = $settings;
		$this->integrity = $integrity ?? new AuditIntegrity();
	}

	/**
	 * Log a tool-execution-relevant event.
	 *
	 * @param array<string, mixed> $event {
	 *   user: ?WP_User, client: string, principal_type: string,
	 *   tool: string, action: string, args: ?array, risk: int,
	 *   status: string, error: ?string, affected_objects: ?array,
	 *   affected_files: ?array, approval_id: ?int, rollback_id: ?int,
	 *   duration_ms: int, ip: ?string
	 * }
	 * @return int Audit row id (0 on failure — never throws).
	 */
	public function log( array $event ): int {
		$user = $event['user'] ?? null;

		$args = null;
		$args_hash = '';
		if ( isset( $event['args'] ) && is_array( $event['args'] ) ) {
			// Hash is over the REDACTED json so the log stays consistent.
			$redacted   = Sanitize::redact( $event['args'] );
			$args_json  = wp_json_encode( $redacted ) ?: '{}';
			$args_hash  = hash( 'sha256', $args_json );
			$args       = $this->settings->auditLogArgs() ? $args_json : null;
		}

		try {
			return $this->logs->insert(
				array(
					'user_id'          => ( $user instanceof WP_User ) ? (int) $user->ID : 0,
					'client'           => (string) ( $event['client'] ?? '' ),
					'principal_type'   => (string) ( $event['principal_type'] ?? 'user' ),
					'tool'             => (string) ( $event['tool'] ?? '' ),
					'action'           => (string) ( $event['action'] ?? '' ),
					'args_json'        => $args,
					'args_hash'        => $args_hash,
					'risk'             => (int) ( $event['risk'] ?? 0 ),
					'status'           => (string) ( $event['status'] ?? self::STATUS_OK ),
					'error'            => isset( $event['error'] ) && is_string( $event['error'] ) ? Sanitize::redactString( Strings::truncate( $event['error'], 2000 ) ) : null,
					'affected_objects' => isset( $event['affected_objects'] ) && is_array( $event['affected_objects'] ) ? $event['affected_objects'] : null,
					'affected_files'   => isset( $event['affected_files'] ) && is_array( $event['affected_files'] ) ? $event['affected_files'] : null,
					'approval_id'      => isset( $event['approval_id'] ) ? (int) $event['approval_id'] : null,
					'rollback_id'      => isset( $event['rollback_id'] ) ? (int) $event['rollback_id'] : null,
					'duration_ms'      => (int) ( $event['duration_ms'] ?? 0 ),
					'ip'               => $event['ip'] ?? null,
				)
			);
		} catch ( \Throwable $failure ) {
			// Audit must never take the request down; log internally only.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[AI WordPress OS] audit write failed: ' . $failure->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			return 0;
		}
	}

	/**
	 * Canonical log id for an approval's executed action (used to link
	 * approval → audit rows).
	 */
	public function repository(): AuditLogRepository {
		return $this->logs;
	}

	/**
	 * Verify this site's own audit chain (SEC-M4). See
	 * AuditIntegrity::verifyChain() for the full status vocabulary —
	 * this never claims legacy (pre-integrity) rows are "verified".
	 *
	 * @return array{overall: string, checked: int, legacy: int, issues: array<int, array{id: mixed, status: string}>}
	 */
	public function verifyIntegrity( int $limit = 1000 ): array {
		$site_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		return $this->integrity->verifyChain( $this->logs->chainRows( $limit ), $site_id );
	}
}
