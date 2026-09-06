<?php
/**
 * Audit log repository: append + filtered queries.
 *
 * @package AIOS\Database\Repositories
 */

declare( strict_types=1 );

namespace AIOS\Database\Repositories;

use AIOS\Database\Database;

final class AuditLogRepository {

	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	private function table(): string {
		return $this->db->table( Database::TABLE_AUDIT_LOGS );
	}

	/**
	 * Append an immutable audit entry.
	 *
	 * @param array<string, mixed> $entry {
	 *   user_id: int, client: string, principal_type: string, tool: string,
	 *   action: string, args_json: ?string, args_hash: string, risk: int,
	 *   status: string, error: ?string, affected_objects: ?array,
	 *   affected_files: ?array, approval_id: ?int, rollback_id: ?int,
	 *   duration_ms: int, ip: ?string
	 * }
	 * @return int Inserted row id (0 on failure).
	 */
	public function insert( array $entry ): int {
		$row = array(
			'occurred_at'      => current_time( 'mysql', true ),
			'user_id'          => (int) ( $entry['user_id'] ?? 0 ),
			'client'           => mb_substr( (string) ( $entry['client'] ?? '' ), 0, 64 ),
			'principal_type'   => mb_substr( (string) ( $entry['principal_type'] ?? 'user' ), 0, 20 ),
			'tool'             => mb_substr( (string) ( $entry['tool'] ?? '' ), 0, 190 ),
			'action'           => mb_substr( (string) ( $entry['action'] ?? '' ), 0, 190 ),
			'args_hash'        => (string) ( $entry['args_hash'] ?? '' ),
			'args_json'        => isset( $entry['args_json'] ) && is_string( $entry['args_json'] ) ? $entry['args_json'] : null,
			'risk'             => max( 0, min( 4, (int) ( $entry['risk'] ?? 0 ) ) ),
			'status'           => mb_substr( (string) ( $entry['status'] ?? 'ok' ), 0, 20 ),
			'error'            => isset( $entry['error'] ) && is_string( $entry['error'] ) ? $entry['error'] : null,
			'affected_objects' => isset( $entry['affected_objects'] ) && is_array( $entry['affected_objects'] )
				? wp_json_encode( $entry['affected_objects'] ) : null,
			'affected_files'   => isset( $entry['affected_files'] ) && is_array( $entry['affected_files'] )
				? wp_json_encode( $entry['affected_files'] ) : null,
			'approval_id'      => isset( $entry['approval_id'] ) ? (int) $entry['approval_id'] : null,
			'rollback_id'      => isset( $entry['rollback_id'] ) ? (int) $entry['rollback_id'] : null,
			'duration_ms'      => max( 0, (int) ( $entry['duration_ms'] ?? 0 ) ),
			'ip'               => self::packIp( $entry['ip'] ?? null ),
		);

		$id = $this->db->insert( $this->table(), $row );
		return $id ?? 0;
	}

	/**
	 * Filtered listing (admin activity screen + logs.list tool).
	 *
	 * @param array<string, mixed> $filters {
	 *   user_id: ?int, tool: ?string, status: ?string, risk: ?int,
	 *   since: ?mysql-date, integration: ?string, search: ?string
	 * }
	 * @return array<int, array<string, mixed>>
	 */
	public function query( array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );

		$where  = array( '1=1' );
		$values = array();

		if ( isset( $filters['user_id'] ) && is_numeric( $filters['user_id'] ) ) {
			$where[]  = 'user_id = %d';
			$values[] = (int) $filters['user_id'];
		}
		if ( ! empty( $filters['tool'] ) && is_string( $filters['tool'] ) ) {
			$where[]  = 'tool = %s';
			$values[] = mb_substr( $filters['tool'], 0, 190 );
		}
		if ( ! empty( $filters['status'] ) && is_string( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = mb_substr( $filters['status'], 0, 20 );
		}
		if ( isset( $filters['risk'] ) && is_numeric( $filters['risk'] ) ) {
			$where[]  = 'risk = %d';
			$values[] = (int) $filters['risk'];
		}
		if ( ! empty( $filters['since'] ) && is_string( $filters['since'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}/', $filters['since'] ) ) {
			$where[]  = 'occurred_at >= %s';
			$values[] = $filters['since'];
		}
		if ( ! empty( $filters['search'] ) && is_string( $filters['search'] ) ) {
			$where[]  = '(tool LIKE %s OR action LIKE %s OR error LIKE %s)';
			$like     = '%' . $this->db->escLike( mb_substr( $filters['search'], 0, 100 ) ) . '%';
			$values   = array_merge( $values, array( $like, $like, $like ) );
		}

		$sql = 'SELECT * FROM ' . $this->table();
		if ( $values ) {
			$sql .= $this->db->prepare( ' WHERE ' . implode( ' AND ', $where ), ...$values );
		} else {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= $this->db->prepare( ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset );

		return array_map( array( $this, 'hydrate' ), $this->db->getResults( $sql ) );
	}

	/**
	 * Single entry by id.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( int $id ): ?array {
		$sql = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id );
		$row = $this->db->getRow( $sql );
		return $row ? $this->hydrate( $row ) : null;
	}

	/**
	 * Aggregate stats for the dashboard (24h + totals).
	 *
	 * @return array<string, int>
	 */
	public function stats(): array {
		$since = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );

		$sql = $this->db->prepare(
			'SELECT
				COUNT(*) AS total_24h,
				SUM(status = "blocked") AS blocked_24h,
				SUM(status = "error") AS errors_24h,
				SUM(status = "rejected") AS rejected_24h
			FROM ' . $this->table() . ' WHERE occurred_at >= %s',
			$since
		);
		$row = $this->db->getRow( $sql ) ?? array();

		$total = (int) $this->db->getVar( 'SELECT COUNT(*) FROM ' . $this->table() );

		return array(
			'total'    => $total,
			'total_24h' => (int) ( $row['total_24h'] ?? 0 ),
			'blocked_24h' => (int) ( $row['blocked_24h'] ?? 0 ),
			'errors_24h' => (int) ( $row['errors_24h'] ?? 0 ),
			'rejected_24h' => (int) ( $row['rejected_24h'] ?? 0 ),
		);
	}

	/**
	 * Retention purge (WP-Cron).
	 */
	public function purgeOlderThan( int $days ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$sql    = $this->db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE occurred_at < %s', $cutoff );
		return (int) $this->db->query( $sql );
	}

	/**
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function hydrate( array $row ): array {
		$row['id']            = (int) $row['id'];
		$row['user_id']       = (int) $row['user_id'];
		$row['risk']          = (int) $row['risk'];
		$row['duration_ms']   = (int) $row['duration_ms'];
		$row['approval_id']   = null === $row['approval_id'] ? null : (int) $row['approval_id'];
		$row['rollback_id']   = null === $row['rollback_id'] ? null : (int) $row['rollback_id'];
		$row['affected_objects'] = null === $row['affected_objects'] ? null : json_decode( (string) $row['affected_objects'], true );
		$row['affected_files']   = null === $row['affected_files'] ? null : json_decode( (string) $row['affected_files'], true );
		$row['args_decoded']  = null === $row['args_json'] ? null : json_decode( (string) $row['args_json'], true );
		return $row;
	}

	/**
	 * inet_pton with graceful fallback (test shim safety).
	 */
	public static function packIp( ?string $ip ): ?string {
		if ( empty( $ip ) ) {
			return null;
		}
		$packed = @inet_pton( $ip );
		return false === $packed ? null : $packed;
	}
}
