<?php
/**
 * API key repository. Keys are stored as SHA-256 hashes only.
 *
 * @package AIOS\Database\Repositories
 */

declare( strict_types=1 );

namespace AIOS\Database\Repositories;

use AIOS\Database\Database;
use AIOS\Support\Strings;

final class ApiKeyRepository {

	private Database $db;

	public function __construct( Database $db ) {
			$this->db = $db;
	}

	private function table(): string {
			return $this->db->table( Database::TABLE_API_KEYS );
	}

		/**
		 * Insert a new key record.
		 *
		 * @param array<string, mixed> $data {
		 *   label: string, key_prefix: string, key_hash: string, user_id: int,
		 *   capabilities: string[], max_level: int, expires_at: ?mysql-date
		 * }
		 */
	public function create( array $data ): int {
			$id = $this->db->insert(
				$this->table(),
				array(
					'created_at'   => current_time( 'mysql', true ),
					'label'        => Strings::truncate( (string) ( $data['label'] ?? '' ), 190 ),
					'key_prefix'   => Strings::truncate( (string) ( $data['key_prefix'] ?? '' ), 12 ),
					'key_hash'     => (string) ( $data['key_hash'] ?? '' ),
					'user_id'      => (int) ( $data['user_id'] ?? 0 ),
					'capabilities' => wp_json_encode( $data['capabilities'] ?? array() ),
					'max_level'    => max( 0, min( 4, (int) ( $data['max_level'] ?? 0 ) ) ),
					'expires_at'   => $data['expires_at'] ?? null,
				)
			);
			return $id ?? 0;
	}

		/**
		 * Look up an active key by its SHA-256 hash.
		 *
		 * Revocation and expiry are re-validated in PHP so behavior is
		 * identical across MySQL/MariaDB timezones.
		 *
		 * @return array<string, mixed>|null
		 */
	public function findActiveByHash( string $key_hash ): ?array {
			$sql = $this->db->prepare(
				'SELECT * FROM ' . $this->table() . ' WHERE key_hash = %s LIMIT 1',
				$key_hash
			);
			$row = $this->db->getRow( $sql );
		if ( null === $row ) {
				return null;
		}

		if ( null !== ( $row['revoked_at'] ?? null ) && '' !== (string) $row['revoked_at'] ) {
				return null;
		}

			$expires_at = $row['expires_at'] ?? null;
		if ( null !== $expires_at && '' !== (string) $expires_at ) {
				$expiry = strtotime( (string) $expires_at . 'Z' );
				// Fall back to naive UTC parse when the value already
				// carries an explicit timezone.
			if ( false === $expiry ) {
					$expiry = strtotime( (string) $expires_at );
			}
			if ( false !== $expiry && $expiry < time() ) {
					return null;
			}
		}

			return $this->hydrate( $row );
	}

		/**
		 * Touch last-used metadata (fire-and-forget, no error surfacing).
		 */
	public function touch( int $id, ?string $ip ): void {
			$this->db->update(
				$this->table(),
				array(
					'last_used_at' => current_time( 'mysql', true ),
					'last_ip'      => null === $ip ? null : AuditLogRepository::packIp( $ip ),
				),
				array( 'id' => $id )
			);
	}

		/**
		 * List keys for the admin UI (hashes never leave the table).
		 *
		 * @return array<int, array<string, mixed>>
		 */
	public function all( int $limit = 100 ): array {
			$sql = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' ORDER BY id DESC LIMIT %d', max( 1, min( 500, $limit ) ) );
			return array_map( array( $this, 'hydrate' ), $this->db->getResults( $sql ) );
	}

	public function revoke( int $id ): bool {
			// NOTE: never put NULL into a wpdb WHERE array — MySQL's
			// `col = NULL` never matches. Fetch first, guard in PHP.
			$record = null;
		foreach ( $this->all( 500 ) as $candidate ) {
			if ( (int) $candidate['id'] === $id ) {
				$record = $candidate;
				break;
			}
		}
		if ( null === $record || null !== $record['revoked_at'] ) {
				return false; // Missing or already revoked.
		}

			return 0 !== $this->db->update(
				$this->table(),
				array( 'revoked_at' => current_time( 'mysql', true ) ),
				array( 'id' => $id )
			);
	}

		/**
		 * @param array<string, mixed> $row
		 * @return array<string, mixed>
		 */
	private function hydrate( array $row ): array {
			$row['id']         = (int) ( $row['id'] ?? 0 );
			$row['user_id']    = (int) ( $row['user_id'] ?? 0 );
			$row['max_level']  = (int) ( $row['max_level'] ?? 0 );
			$row['revoked_at'] = $row['revoked_at'] ?? null;
			$row['expires_at'] = $row['expires_at'] ?? null;
			$capabilities      = null === ( $row['capabilities'] ?? null ) ? array() : json_decode( (string) $row['capabilities'], true );
		if ( ! $capabilities ) {
			$capabilities = array();
		}
			$row['capabilities'] = $capabilities;
			return $row;
	}
}
