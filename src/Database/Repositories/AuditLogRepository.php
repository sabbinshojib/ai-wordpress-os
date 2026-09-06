<?php
/**
 * Audit log repository: append + filtered queries.
 *
 * @package AIOS\Database\Repositories
 */

declare( strict_types=1 );

namespace AIOS\Database\Repositories;

use AIOS\Audit\AuditIntegrity;
use AIOS\Database\Database;
use AIOS\Support\Strings;

final class AuditLogRepository {

	private Database $db;

	private AuditIntegrity $integrity;

	public function __construct( Database $db, ?AuditIntegrity $integrity = null ) {
		$this->db        = $db;
		$this->integrity = $integrity ?? new AuditIntegrity();
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
			'client'           => Strings::truncate( (string) ( $entry['client'] ?? '' ), 64 ),
			'principal_type'   => Strings::truncate( (string) ( $entry['principal_type'] ?? 'user' ), 20 ),
			'tool'             => Strings::truncate( (string) ( $entry['tool'] ?? '' ), 190 ),
			'action'           => Strings::truncate( (string) ( $entry['action'] ?? '' ), 190 ),
			'args_hash'        => (string) ( $entry['args_hash'] ?? '' ),
			'args_json'        => isset( $entry['args_json'] ) && is_string( $entry['args_json'] ) ? $entry['args_json'] : null,
			'risk'             => max( 0, min( 4, (int) ( $entry['risk'] ?? 0 ) ) ),
			'status'           => Strings::truncate( (string) ( $entry['status'] ?? 'ok' ), 20 ),
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

		// Tamper-evident chain (SEC-M4): link this row onto whatever
		// immediately precedes it in this SITE's own table — multisite
		// isolation is automatic here because every site has its own
		// physically separate audit_logs table (see Database::table()),
		// so there is nothing extra to scope by blog id at the SQL
		// level. siteId is folded into the hash material anyway as a
		// second, explicit guard against a row computed under one site's
		// key material ever verifying under another's.
		$site_id  = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;
		$previous = $this->lastRawRow();
		$row      = array_merge( $row, $this->integrity->nextLink( AuditIntegrity::contentFieldsFromRow( $row ), $previous, $site_id ) );

		$id = $this->db->insert( $this->table(), $row );
		return $id ?? 0;
	}

	/**
	 * The most recently inserted row, RAW (no hydration) — the exact
	 * persisted shape integrity linking must chain onto.
	 *
	 * @return array<string, mixed>|null
	 */
	private function lastRawRow(): ?array {
		$sql  = 'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT 1';
		$rows = $this->db->getResults( $sql );
		return $rows[0] ?? null;
	}

	/**
	 * RAW rows (no hydration) in chronological (ascending) insertion
	 * order, for integrity verification — hydrate() json-decodes fields
	 * that participate in the hash, which would silently desync
	 * verify-time content from what write time actually hashed.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function chainRows( int $limit = 1000 ): array {
		$limit = max( 1, min( 5000, $limit ) );
		$sql   = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT %d', $limit );
		return array_reverse( $this->db->getResults( $sql ) );
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
			$values[] = Strings::truncate( $filters['tool'], 190 );
		}
		if ( ! empty( $filters['status'] ) && is_string( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = Strings::truncate( $filters['status'], 20 );
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
			$like     = '%' . $this->db->escLike( Strings::truncate( $filters['search'], 100 ) ) . '%';
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
		$row['integrity_version'] = isset( $row['integrity_version'] ) && null !== $row['integrity_version'] ? (int) $row['integrity_version'] : null;
		$row['chain_seq']         = isset( $row['chain_seq'] ) && null !== $row['chain_seq'] ? (int) $row['chain_seq'] : null;
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
