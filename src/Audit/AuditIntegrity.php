<?php
/**
 * Tamper-evident audit chain (SEC-M4, Sprint 0.3A).
 *
 * This does NOT make audit rows immutable — the database layer can
 * still be edited directly by anyone with sufficient DB access, and
 * this class makes no claim otherwise. What it provides is TAMPER
 * EVIDENCE: every row is linked to its predecessor by a keyed hash
 * (HMAC-SHA256) over that predecessor's own hash plus the row's own
 * (already redacted) content, so any of the following becomes
 * cryptographically detectable on verification:
 *
 *  - a row's content was edited after the fact (recomputed hash
 *    differs from the stored one)
 *  - a row's declared predecessor link was altered (same effect,
 *    since prev_hash is itself part of what gets hashed)
 *  - rows were reordered relative to their recorded chain sequence
 *  - a row was deleted from the middle of the chain (the next row's
 *    prev_hash/chain_seq no longer lines up with its new neighbor)
 *  - a hash/sequence value is structurally malformed
 *
 * The HMAC key is derived from the site's own auth salt (same family
 * as Crypto::deriveKeyFromEnvironment(), but domain-separated so the
 * two are never interchangeable) — never persisted, never logged,
 * never exposed through any REST/diagnostic surface.
 *
 * Rows written before this feature shipped have no integrity columns
 * at all. They are reported as "legacy" — a distinct, explicit state
 * — and are never counted as verified.
 *
 * @package AIOS\Audit
 */

declare( strict_types=1 );

namespace AIOS\Audit;

final class AuditIntegrity {

	/**
	 * Integrity format version. Bump this (and branch on it in
	 * computeHash()) if the canonical payload or hashing scheme ever
	 * needs to change, so old and new chain segments can still be told
	 * apart and verified with the rules that produced them.
	 */
	public const VERSION = 1;

	/**
	 * Sentinel `prev_hash` for the first row of a chain segment (either
	 * the very first row ever, or the first row written after a run of
	 * legacy/pre-integrity rows that carry no hash to link onto).
	 * Deliberately NOT a valid hex hash so it can never collide with a
	 * real record_hash value.
	 */
	public const GENESIS_PREV = 'genesis';

	public const STATUS_VALID               = 'valid';
	public const STATUS_LEGACY              = 'legacy';
	public const STATUS_MALFORMED           = 'malformed';
	public const STATUS_MISSING_PREDECESSOR = 'missing_predecessor';
	public const STATUS_BROKEN_CHAIN        = 'broken_chain';
	public const STATUS_TAMPERED            = 'tampered';

	private const HASH_PATTERN = '/^[0-9a-f]{64}$/';

	private string $key;

	public function __construct( ?string $key = null ) {
		$this->key = $key ?? self::deriveKeyFromEnvironment();
	}

	/**
	 * Domain-separated from Crypto's key derivation on purpose: this
	 * key authenticates chain links, it never encrypts/decrypts
	 * anything, and the two must never be substitutable for each other.
	 */
	private static function deriveKeyFromEnvironment(): string {
		$salt = '';
		if ( defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) && '' !== AUTH_KEY ) {
			$salt = AUTH_KEY;
		} elseif ( defined( 'AUTH_SALT' ) && is_string( AUTH_SALT ) && '' !== AUTH_SALT ) {
			$salt = AUTH_SALT;
		} elseif ( function_exists( 'wp_salt' ) ) {
			try {
				$salt = (string) wp_salt( 'auth' );
			} catch ( \Throwable $e ) {
				$salt = '';
			}
		}
		if ( '' === $salt ) {
			$salt = 'ai-wordpress-os-development-salt';
		}
		return hash( 'sha256', 'ai-os-audit-integrity|' . $salt, true );
	}

	/**
	 * Deterministic, canonical JSON for a fields map: keys sorted
	 * recursively, no whitespace, stable scalar representation. Two
	 * calls with the same logical content always produce byte-identical
	 * output regardless of insertion order.
	 *
	 * @param array<string, mixed> $fields
	 */
	public static function canonicalize( array $fields ): string {
		$normalize = static function ( mixed $value ) use ( &$normalize ): mixed {
			if ( is_array( $value ) ) {
				if ( array_is_list( $value ) ) {
					return array_map( $normalize, $value );
				}
				ksort( $value );
				$out = array();
				foreach ( $value as $k => $v ) {
					$out[ (string) $k ] = $normalize( $v );
				}
				return $out;
			}
			return $value;
		};

		return (string) json_encode( $normalize( $fields ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The exact set of a row's own fields that participate in its hash.
	 * Both write time (AuditLogRepository::insert()) and verify time
	 * call this on the row's OWN persisted representation, so the two
	 * can never drift apart — nothing here is caller-suppliable at
	 * verify time.
	 *
	 * These fields are read from the row AFTER AuditLogger has already
	 * applied Sanitize::redact()/redactString() (args_hash is a hash of
	 * the redacted payload, `error` is pre-redacted) — this class never
	 * sees, and therefore can never leak, an unredacted secret.
	 *
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	public static function contentFieldsFromRow( array $row ): array {
		return array(
			'occurred_at'      => (string) ( $row['occurred_at'] ?? '' ),
			'user_id'          => (int) ( $row['user_id'] ?? 0 ),
			'client'           => (string) ( $row['client'] ?? '' ),
			'principal_type'   => (string) ( $row['principal_type'] ?? '' ),
			'tool'             => (string) ( $row['tool'] ?? '' ),
			'action'           => (string) ( $row['action'] ?? '' ),
			'args_hash'        => (string) ( $row['args_hash'] ?? '' ),
			'risk'             => (int) ( $row['risk'] ?? 0 ),
			'status'           => (string) ( $row['status'] ?? '' ),
			'error'            => $row['error'] ?? null,
			'affected_objects' => $row['affected_objects'] ?? null,
			'affected_files'   => $row['affected_files'] ?? null,
			'approval_id'      => $row['approval_id'] ?? null,
			'rollback_id'      => $row['rollback_id'] ?? null,
			'duration_ms'      => (int) ( $row['duration_ms'] ?? 0 ),
		);
	}

	/**
	 * @param array<string, mixed> $contentFields
	 */
	public function computeHash( array $contentFields, string $prevHash, int $chainSeq, int $siteId ): string {
		$material = implode(
			'|',
			array(
				'aios-audit-v' . self::VERSION,
				(string) $siteId,
				(string) $chainSeq,
				$prevHash,
				self::canonicalize( $contentFields ),
			)
		);
		return hash_hmac( 'sha256', $material, $this->key );
	}

	/**
	 * A row counts as "chain-bearing" only when it carries a complete,
	 * present integrity triple. Anything else — including a row that
	 * predates this feature entirely — is legacy.
	 *
	 * @param array<string, mixed> $row
	 */
	public static function isChainRow( array $row ): bool {
		return isset( $row['integrity_version'], $row['record_hash'], $row['chain_seq'] )
			&& null !== $row['integrity_version']
			&& '' !== (string) $row['record_hash']
			&& '' !== (string) $row['chain_seq'];
	}

	/**
	 * Compute the integrity columns for a new row, given its own
	 * (already finalized, already redacted) content fields and its
	 * immediate predecessor in insertion order — or null when there is
	 * none (first row ever, or the immediate predecessor is a legacy
	 * row with nothing to link onto).
	 *
	 * @param array<string, mixed>      $contentFields
	 * @param array<string, mixed>|null $previous
	 * @return array{integrity_version: int, chain_seq: int, prev_hash: string, record_hash: string}
	 */
	public function nextLink( array $contentFields, ?array $previous, int $siteId ): array {
		$prevHash = self::GENESIS_PREV;
		$chainSeq = 1;

		if ( null !== $previous && self::isChainRow( $previous ) ) {
			$prevHash = (string) $previous['record_hash'];
			$chainSeq = (int) $previous['chain_seq'] + 1;
		}

		return array(
			'integrity_version' => self::VERSION,
			'chain_seq'         => $chainSeq,
			'prev_hash'         => $prevHash,
			'record_hash'       => $this->computeHash( $contentFields, $prevHash, $chainSeq, $siteId ),
		);
	}

	/**
	 * Verify a chronologically-ordered (ascending insertion order) list
	 * of RAW audit rows — i.e. exactly as stored, not the API-hydrated
	 * shape (json-decoded affected_objects etc. would silently change
	 * what gets hashed and desync from write time).
	 *
	 * Legacy rows (no integrity columns) are skipped for hash/sequence
	 * math but reset the "expected predecessor" for whatever chain row
	 * follows them, since a chain segment that starts right after
	 * legacy history has no cryptographic predecessor to link onto —
	 * such a row is only valid if it declares GENESIS_PREV itself.
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array{overall: string, checked: int, legacy: int, issues: array<int, array{id: mixed, status: string}>}
	 */
	public function verifyChain( array $rows, int $siteId ): array {
		$issues       = array();
		$legacy_count = 0;
		$checked      = 0;
		$prev_row     = null;

		foreach ( $rows as $row ) {
			if ( ! self::isChainRow( $row ) ) {
				++$legacy_count;
				$prev_row = null; // Next chain row (if any) starts a fresh segment.
				continue;
			}

			++$checked;
			$status = $this->verifyRow( $row, $prev_row, $siteId );
			if ( self::STATUS_VALID !== $status ) {
				$issues[] = array( 'id' => $row['id'] ?? null, 'status' => $status );
			}
			$prev_row = $row;
		}

		if ( array() !== $issues ) {
			$overall = self::STATUS_BROKEN_CHAIN;
		} elseif ( 0 === $checked && $legacy_count > 0 ) {
			// Nothing to cryptographically verify — legacy-only history.
			// Never reported as "valid": that would imply verification
			// happened when it did not.
			$overall = self::STATUS_LEGACY;
		} else {
			$overall = self::STATUS_VALID;
		}

		return array(
			'overall' => $overall,
			'checked' => $checked,
			'legacy'  => $legacy_count,
			'issues'  => $issues,
		);
	}

	/**
	 * @param array<string, mixed>      $row
	 * @param array<string, mixed>|null $prev_row
	 */
	private function verifyRow( array $row, ?array $prev_row, int $siteId ): string {
		$hash      = (string) ( $row['record_hash'] ?? '' );
		$prev_hash = (string) ( $row['prev_hash'] ?? '' );
		$chain_seq = $row['chain_seq'] ?? null;

		if ( 1 !== preg_match( self::HASH_PATTERN, $hash ) ) {
			return self::STATUS_MALFORMED;
		}
		if ( self::GENESIS_PREV !== $prev_hash && 1 !== preg_match( self::HASH_PATTERN, $prev_hash ) ) {
			return self::STATUS_MALFORMED;
		}
		if ( ! is_numeric( $chain_seq ) || (int) $chain_seq < 1 ) {
			return self::STATUS_MALFORMED;
		}
		$chain_seq = (int) $chain_seq;

		if ( null === $prev_row ) {
			if ( self::GENESIS_PREV !== $prev_hash || 1 !== $chain_seq ) {
				// Declares a predecessor that is not present in the
				// verified range (or not first in its segment).
				return self::STATUS_MISSING_PREDECESSOR;
			}
		} else {
			if ( $chain_seq !== (int) $prev_row['chain_seq'] + 1 ) {
				return self::STATUS_MISSING_PREDECESSOR;
			}
			if ( $prev_hash !== (string) $prev_row['record_hash'] ) {
				return self::STATUS_BROKEN_CHAIN;
			}
		}

		$recomputed = $this->computeHash( self::contentFieldsFromRow( $row ), $prev_hash, $chain_seq, $siteId );
		if ( ! hash_equals( $recomputed, $hash ) ) {
			return self::STATUS_TAMPERED;
		}

		return self::STATUS_VALID;
	}
}
