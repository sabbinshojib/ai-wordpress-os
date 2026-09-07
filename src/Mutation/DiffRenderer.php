<?php
/**
 * Builds a deterministic, reviewable AIOS\Mutation\MutationDiff for a
 * ChangeSet, from the Snapshots MutationEngine already captured (the
 * "before" state) and each operation's own intendedValue() (the
 * "after" state). Called AFTER Snapshot, BEFORE Approval — the
 * resulting diff hash feeds ChangeSetFingerprint, so what gets
 * approved is bound to exactly this rendered diff.
 *
 * Reuses AIOS\Support\Diff for the actual line-diff algorithm rather
 * than reimplementing one. This class's own job is entirely about
 * WHAT is safe to feed that algorithm and how to represent operation
 * types (option/post/meta) that are not naturally line-based text.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Support\Diff;
use AIOS\Support\Sanitize;

final class DiffRenderer {

	/**
	 * Content larger than this is never fed to the line-diff algorithm
	 * — represented as truncated with hashes/byte-lengths only. Bounds
	 * memory/output per Package B's requirement.
	 */
	private const MAX_DIFFABLE_BYTES = 262144; // 256 KiB

	/**
	 * @param array<string, Snapshot> $snapshots operation id => Snapshot, as captured by MutationEngine.
	 */
	public static function render( ChangeSet $change_set, array $snapshots ): MutationDiff {
		$diffs = array();
		foreach ( $change_set->operations() as $operation ) {
			$snapshot = $snapshots[ $operation->id() ] ?? null;
			$diffs[]  = self::renderOperation( $operation, $snapshot );
		}
		return new MutationDiff( $diffs );
	}

	private static function renderOperation( ChangeOperationInterface $operation, ?Snapshot $snapshot ): OperationDiff {
		$before = self::extractBefore( $operation->type(), $snapshot );
		$after  = $operation->intendedValue();

		$before_string = self::toComparableString( $before );
		$after_string  = self::toComparableString( $after );

		if ( self::looksSecret( $before_string ) || self::looksSecret( $after_string ) ) {
			return new OperationDiff(
				$operation->id(),
				$operation->type(),
				$operation->target(),
				OperationDiff::KIND_SECRET,
				array( 'reason' => 'value looks secret-shaped; redacted' ),
				null,
				false,
				self::hashOrNull( $before_string ),
				self::hashOrNull( $after_string )
			);
		}

		if ( self::looksBinary( $before_string ) || self::looksBinary( $after_string ) ) {
			return new OperationDiff(
				$operation->id(),
				$operation->type(),
				$operation->target(),
				OperationDiff::KIND_BINARY,
				array(
					'before_bytes' => null === $before_string ? null : strlen( $before_string ),
					'after_bytes'  => null === $after_string ? null : strlen( $after_string ),
				),
				null,
				false,
				self::hashOrNull( $before_string ),
				self::hashOrNull( $after_string )
			);
		}

		$before_len = null === $before_string ? 0 : strlen( $before_string );
		$after_len  = null === $after_string ? 0 : strlen( $after_string );
		if ( $before_len > self::MAX_DIFFABLE_BYTES || $after_len > self::MAX_DIFFABLE_BYTES ) {
			return new OperationDiff(
				$operation->id(),
				$operation->type(),
				$operation->target(),
				self::isTextLike( $operation->type() ) ? OperationDiff::KIND_TEXT : OperationDiff::KIND_VALUE,
				array( 'before_bytes' => $before_len, 'after_bytes' => $after_len, 'reason' => 'content exceeds diff size bound' ),
				null,
				true,
				self::hashOrNull( $before_string ),
				self::hashOrNull( $after_string )
			);
		}

		$computed = Diff::compute( $before_string ?? '', $after_string ?? '' );

		return new OperationDiff(
			$operation->id(),
			$operation->type(),
			$operation->target(),
			self::isTextLike( $operation->type() ) ? OperationDiff::KIND_TEXT : OperationDiff::KIND_VALUE,
			$computed['summary'],
			'' === $computed['unified'] ? null : $computed['unified'],
			false,
			self::hashOrNull( $before_string ),
			self::hashOrNull( $after_string )
		);
	}

	private static function isTextLike( string $type ): bool {
		return in_array( $type, array( 'file.create', 'file.patch', 'file.delete' ), true );
	}

	private static function extractBefore( string $type, ?Snapshot $snapshot ): mixed {
		if ( null === $snapshot ) {
			return null;
		}
		$state = $snapshot->state();
		return match ( $type ) {
			'file.patch', 'file.delete' => $state['original_content'] ?? null,
			'file.create'               => null, // Nothing existed before a create.
			'option.update'             => ( $state['existed'] ?? false ) ? ( $state['original_value'] ?? null ) : null,
			'post.metadata_update'      => ( $state['existed'] ?? false ) ? ( $state['original_value'] ?? null ) : null,
			'post.content_update'       => $state['original'] ?? null,
			default                     => null,
		};
	}

	/**
	 * Text values pass through as-is; everything else becomes a
	 * canonical (sorted-key, UNESCAPED_SLASHES) JSON string so the same
	 * logical value always renders identically.
	 */
	private static function toComparableString( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		return (string) json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	}

	private static function looksSecret( ?string $value ): bool {
		return null !== $value && Sanitize::looksLikeSecret( $value );
	}

	/**
	 * A NUL byte is the standard, cheap "this is not text" heuristic —
	 * valid UTF-8 text never legitimately contains one.
	 */
	private static function looksBinary( ?string $value ): bool {
		return null !== $value && str_contains( $value, "\0" );
	}

	private static function hashOrNull( ?string $value ): ?string {
		return null === $value ? null : hash( 'sha256', $value );
	}
}
