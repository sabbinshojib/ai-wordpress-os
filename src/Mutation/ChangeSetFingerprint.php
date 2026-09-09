<?php
/**
 * Canonical, deterministic cryptographic identity of "exactly what
 * will happen" for a ChangeSet: id, schema version, site, actor,
 * ordered operation list (type/target/payload fingerprint), risk, and
 * the Diff's own hash. An Approval binds to this fingerprint (see
 * MutationEngine::resumeApproved()) — if anything material changes
 * after a ChangeSet was approved (an operation added/removed/
 * reordered, a target or payload changed, the site or actor changed),
 * the fingerprint changes and the old approval no longer matches.
 *
 * Uses json_encode() over a fixed-key-order array, never PHP
 * serialize() — this is a stable, deterministic, cross-version-safe
 * canonical form, not an executable payload.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class ChangeSetFingerprint {

	public const SCHEMA_VERSION = 1;

	/**
	 * @return string sha256 hex.
	 */
	public static function compute( ChangeSet $change_set, ?string $diff_hash = null ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fingerprint producer: plain json_encode() keeps byte-stable output across WP versions (P2-09 policy).
		return hash( 'sha256', (string) json_encode( self::canonical( $change_set, $diff_hash ), JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * The canonical structure itself (not just its hash) — exposed so
	 * callers can log/inspect what was actually bound, without ever
	 * needing to reverse the hash.
	 *
	 * @return array<string, mixed>
	 */
	public static function canonical( ChangeSet $change_set, ?string $diff_hash = null ): array {
		return array(
			'schema_version'    => self::SCHEMA_VERSION,
			'change_set_id'     => $change_set->id(),
			'site_id'           => $change_set->siteId(),
			'principal_user_id' => $change_set->principalUserId(),
			'principal_type'    => $change_set->principalType(),
			'risk_level'        => $change_set->riskLevel(),
			'operations'        => array_map(
				static fn( ChangeOperationInterface $op ): array => array(
					'type'                => $op->type(),
					'target'              => $op->target(),
					'payload_fingerprint' => $op->payloadFingerprint(),
				),
				$change_set->operations()
			),
			'diff_hash'         => $diff_hash,
		);
	}

	public static function matches( ChangeSet $change_set, string $expected_fingerprint, ?string $diff_hash = null ): bool {
		return hash_equals( $expected_fingerprint, self::compute( $change_set, $diff_hash ) );
	}
}
