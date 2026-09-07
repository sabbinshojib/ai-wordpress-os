<?php
/**
 * The full, ordered set of per-operation diffs for one ChangeSet, plus
 * a deterministic hash of the whole (safe, redacted) representation —
 * fed into ChangeSetFingerprint so an approval is bound to the exact
 * reviewed diff, not merely to a ChangeSet id.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class MutationDiff {

	/**
	 * @param OperationDiff[] $operations In ChangeSet operation order.
	 */
	public function __construct( public readonly array $operations ) {}

	/**
	 * @return sha256 hex over the diff's own safe (redacted, hash-only
	 *         for large/binary/secret content) representation — never
	 *         over raw unredacted content.
	 */
	public function hash(): string {
		$canonical = array_map( static fn( OperationDiff $d ): array => $d->toArray(), $this->operations );
		return hash( 'sha256', (string) json_encode( $canonical, JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray(): array {
		return array_map( static fn( OperationDiff $d ): array => $d->toArray(), $this->operations );
	}
}
