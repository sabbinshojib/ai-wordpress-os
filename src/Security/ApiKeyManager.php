<?php
/**
 * API key lifecycle manager: issue, list, revoke.
 *
 * Keys: "aios_" + base64url(random_bytes(32)) → 43 chars after prefix.
 * Storage: SHA-256 hash + 8-char prefix for identification in UI.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

use AIOS\Database\Repositories\ApiKeyRepository;
use AIOS\Support\Strings;
use AIOS\Support\StructuredError;

final class ApiKeyManager {

	private ApiKeyRepository $keys;

	public function __construct( ApiKeyRepository $keys ) {
		$this->keys = $keys;
	}

	/**
	 * Issue a new key. The raw secret is returned exactly once and is
	 * never stored.
	 *
	 * @param array<string, mixed> $input { label, user_id, capabilities, max_level, expires_at }
	 * @return array<string, mixed> { key (raw), id, label, prefix, ... }
	 */
	public function issue( array $input ): array {
		$user_id = (int) ( $input['user_id'] ?? 0 );
		if ( $user_id <= 0 ) {
			throw new \InvalidArgumentException( 'A valid user_id is required.' );
		}

		$label = trim( (string) ( $input['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = sprintf( 'Key #%d', time() );
		}

		$raw_key = self::generate();

		$capabilities = array();
		foreach ( (array) ( $input['capabilities'] ?? array() ) as $capability ) {
			if ( is_string( $capability ) && preg_match( '/^[a-z0-9_.\-]{2,64}$/i', $capability ) ) {
				$capabilities[] = $capability;
			}
		}

		$expires_at = null;
		if ( ! empty( $input['expires_at'] ) && is_string( $input['expires_at'] ) ) {
			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $input['expires_at'] ) ) {
				$expires_at = $input['expires_at'] . ' 23:59:59';
			} elseif ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $input['expires_at'] ) ) {
				$expires_at = $input['expires_at'];
			}
		}

		$id = $this->keys->create(
			array(
				'label'        => Strings::truncate( $label, 190 ),
				'key_prefix'   => substr( $raw_key, 0, 12 ),
				'key_hash'     => \AIOS\Support\Crypto::keyHash( $raw_key ),
				'user_id'      => $user_id,
				'capabilities' => $capabilities,
				'max_level'    => max( 0, min( 4, (int) ( $input['max_level'] ?? 1 ) ) ),
				'expires_at'   => $expires_at,
			)
		);

		return array(
			'id'           => $id,
			'key'          => $raw_key,
			'label'        => $label,
			'prefix'       => substr( $raw_key, 0, 12 ),
			'user_id'      => $user_id,
			'capabilities' => $capabilities,
			'max_level'    => (int) ( $input['max_level'] ?? 1 ),
			'expires_at'   => $expires_at,
			'created_at'   => current_time( 'mysql', true ),
		);
	}

	/**
	 * Issue a "recovery style" replacement for an existing key:
	 * revoke the old, create a new with the same scopes. The raw key
	 * is returned once.
	 *
	 * @return array<string, mixed>|StructuredError
	 */
	public function rotate( int $id ): array|StructuredError {
		$all = $this->keys->all( 500 );
		foreach ( $all as $record ) {
			if ( (int) $record['id'] === $id ) {
				$this->keys->revoke( $id );
				$replacement = $this->issue(
					array(
						'label'        => ( $record['label'] ?? 'key' ) . ' (rotated)',
						'user_id'      => (int) $record['user_id'],
						'capabilities' => (array) ( $record['capabilities'] ?? array() ),
						'max_level'    => (int) ( $record['max_level'] ?? 1 ),
					)
				);
				$replacement['rotated_from'] = $id;
				return $replacement;
			}
		}
		return StructuredError::notFound( 'api_key.not_found', 'API key not found.' );
	}

	public function revoke( int $id ): bool {
		return $this->keys->revoke( $id );
	}

	/**
	 * List keys for the admin UI (never includes hashes or raw keys).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list(): array {
		$records = array();
		foreach ( $this->keys->all( 200 ) as $record ) {
			unset( $record['key_hash'] );
			$records[] = $record;
		}
		return $records;
	}

	/**
	 * CSPRNG key material.
	 */
	public static function generate(): string {
		return 'aios_' . rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}
}
