<?php
/**
 * Closed whitelist mapping a persisted operation TYPE STRING to its
 * concrete class. This is the ONLY place a durable payload's type
 * identifier is ever turned into an object — never `new $class`,
 * never `unserialize()`, never Reflection-based construction from a
 * caller- or storage-supplied class name. An unknown type, an
 * unsupported schema version, or a malformed spec all fail closed
 * (MutationException), never a PHP fatal.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Mutation\Operations\FileCreateOperation;
use AIOS\Mutation\Operations\FileDeleteOperation;
use AIOS\Mutation\Operations\FilePatchOperation;
use AIOS\Mutation\Operations\MetadataUpdateOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\Operations\PostContentUpdateOperation;
use AIOS\Security\PathGuard;

final class OperationRegistry {

	public const SCHEMA_VERSION = 1;

	/**
	 * The closed set of type identifiers this registry will ever
	 * construct. Nothing outside this list can ever be rehydrated,
	 * regardless of what a persisted payload claims.
	 *
	 * @var string[]
	 */
	public const SUPPORTED_TYPES = array(
		FileCreateOperation::TYPE,
		FilePatchOperation::TYPE,
		FileDeleteOperation::TYPE,
		OptionUpdateOperation::TYPE,
		PostContentUpdateOperation::TYPE,
		MetadataUpdateOperation::TYPE,
	);

	public static function isSupported( string $type ): bool {
		return in_array( $type, self::SUPPORTED_TYPES, true );
	}

	/**
	 * Serialize an entire ChangeSet into a versioned, JSON-safe
	 * envelope (never PHP serialize()) — the plaintext form that
	 * ChangeSetRepository then encrypts via AIOS\Support\Crypto before
	 * it ever reaches durable storage.
	 *
	 * @return array<string, mixed>
	 */
	public static function serialize( ChangeSet $change_set ): array {
		return array(
			'schema_version'    => self::SCHEMA_VERSION,
			'change_set_id'     => $change_set->id(),
			'site_id'           => $change_set->siteId(),
			'principal_user_id' => $change_set->principalUserId(),
			'principal_type'    => $change_set->principalType(),
			'metadata'          => $change_set->metadata(),
			'operations'        => array_map(
				static fn( ChangeOperationInterface $op ): array => array(
					'type' => $op->type(),
					'spec' => $op->toSpec(),
				),
				$change_set->operations()
			),
		);
	}

	/**
	 * Reconstruct a ChangeSet from a payload previously produced by
	 * serialize(). $path_guard is required only if the payload contains
	 * at least one file.* operation.
	 *
	 * @param array<string, mixed> $payload
	 *
	 * @throws MutationException With a stable, non-secret error code
	 *         ("registry.unsupported_schema_version", "registry.malformed_payload",
	 *         "registry.unknown_operation_type") on any structural problem —
	 *         never a PHP TypeError/fatal from malformed input.
	 */
	public static function rehydrate( array $payload, ?PathGuard $path_guard = null ): ChangeSet {
		$schema_version = $payload['schema_version'] ?? null;
		if ( self::SCHEMA_VERSION !== $schema_version ) {
			throw new MutationException( 'registry.unsupported_schema_version', 'Unsupported persisted ChangeSet schema version.' );
		}

		$change_set_id     = $payload['change_set_id'] ?? null;
		$site_id           = $payload['site_id'] ?? null;
		$principal_user_id = $payload['principal_user_id'] ?? null;
		$principal_type    = $payload['principal_type'] ?? null;
		$metadata          = $payload['metadata'] ?? null;
		$operations_spec   = $payload['operations'] ?? null;

		if ( ! is_string( $change_set_id ) || '' === $change_set_id
			|| ! is_int( $site_id )
			|| ! is_int( $principal_user_id )
			|| ! is_string( $principal_type )
			|| ! is_array( $metadata )
			|| ! is_array( $operations_spec )
			|| array() === $operations_spec
		) {
			throw new MutationException( 'registry.malformed_payload', 'Persisted ChangeSet payload is malformed.' );
		}

		$operations = array();
		foreach ( $operations_spec as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['type'] ) || ! is_string( $entry['type'] ) || ! isset( $entry['spec'] ) || ! is_array( $entry['spec'] ) ) {
				throw new MutationException( 'registry.malformed_payload', 'Persisted operation entry is malformed.' );
			}
			$operations[] = self::build( $entry['type'], $entry['spec'], $path_guard );
		}

		return new ChangeSet( $principal_user_id, $operations, $metadata, $site_id, $principal_type, $change_set_id );
	}

	/**
	 * Build exactly one operation from a type identifier + spec. The
	 * ONLY place a type string is turned into a class instance.
	 *
	 * @param array<string, mixed> $spec
	 *
	 * @throws MutationException "registry.unknown_operation_type" for
	 *         anything outside SUPPORTED_TYPES, "registry.malformed_payload"
	 *         for a spec missing required keys.
	 */
	public static function build( string $type, array $spec, ?PathGuard $path_guard = null ): ChangeOperationInterface {
		if ( in_array( $type, array( FileCreateOperation::TYPE, FilePatchOperation::TYPE, FileDeleteOperation::TYPE ), true ) && null === $path_guard ) {
			throw new MutationException( 'registry.missing_path_guard', 'A PathGuard is required to rehydrate a file operation.' );
		}

		try {
			return match ( $type ) {
				FileCreateOperation::TYPE => new FileCreateOperation( self::str( $spec, 'path' ), self::str( $spec, 'content' ), $path_guard ),
				FilePatchOperation::TYPE  => new FilePatchOperation( self::str( $spec, 'path' ), self::str( $spec, 'content' ), $path_guard ),
				FileDeleteOperation::TYPE => new FileDeleteOperation( self::str( $spec, 'path' ), $path_guard ),
				OptionUpdateOperation::TYPE => new OptionUpdateOperation( self::str( $spec, 'option' ), $spec['value'] ?? null ),
				PostContentUpdateOperation::TYPE => new PostContentUpdateOperation( self::int( $spec, 'post_id' ), self::arr( $spec, 'fields' ) ),
				MetadataUpdateOperation::TYPE => new MetadataUpdateOperation( self::int( $spec, 'post_id' ), self::str( $spec, 'meta_key' ), $spec['value'] ?? null ),
				default => throw new MutationException( 'registry.unknown_operation_type', sprintf( 'Unknown or unsupported operation type: "%s".', $type ) ),
			};
		} catch ( \InvalidArgumentException $e ) {
			// An operation's own constructor validation (e.g. a protected
			// option name, an empty path) — surfaced as the same closed
			// failure mode as every other malformed-payload case.
			throw new MutationException( 'registry.malformed_payload', 'Persisted operation spec failed validation.', $e );
		}
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function str( array $spec, string $key ): string {
		if ( ! isset( $spec[ $key ] ) || ! is_string( $spec[ $key ] ) ) {
			throw new MutationException( 'registry.malformed_payload', sprintf( 'Missing or non-string field "%s" in operation spec.', $key ) );
		}
		return $spec[ $key ];
	}

	/**
	 * @param array<string, mixed> $spec
	 */
	private static function int( array $spec, string $key ): int {
		if ( ! isset( $spec[ $key ] ) || ! is_int( $spec[ $key ] ) ) {
			throw new MutationException( 'registry.malformed_payload', sprintf( 'Missing or non-integer field "%s" in operation spec.', $key ) );
		}
		return $spec[ $key ];
	}

	/**
	 * @param array<string, mixed> $spec
	 * @return array<mixed>
	 */
	private static function arr( array $spec, string $key ): array {
		if ( ! isset( $spec[ $key ] ) || ! is_array( $spec[ $key ] ) ) {
			throw new MutationException( 'registry.malformed_payload', sprintf( 'Missing or non-array field "%s" in operation spec.', $key ) );
		}
		return $spec[ $key ];
	}
}
