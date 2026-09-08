<?php
/**
 * Line-based unified diff engine.
 *
 * Implements a straightforward LCS-based diff. Used by the approvals
 * preview UI today (content before/after) and by the Phase 2 snapshot
 * engine later. Produces both a structured op list and a unified diff
 * string.
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

final class Diff {

	public static function compute( string $old, string $new, int $context_lines = 3 ): array {
		$old_lines = self::splitLines( $old );
		$new_lines = self::splitLines( $new );

		$ops = self::diffOps( $old_lines, $new_lines );

		return array(
			'ops'      => $ops,
			'summary'  => array(
				'added'     => count( array_filter( $ops, static fn( array $op ): bool => 'add' === $op['type'] ) ),
				'removed'   => count( array_filter( $ops, static fn( array $op ): bool => 'remove' === $op['type'] ) ),
				'unchanged' => count( array_filter( $ops, static fn( array $op ): bool => 'context' === $op['type'] ) ),
			),
			'unified'  => self::unified( $ops, $context_lines ),
		);
	}

	/**
	 * Compute only the summary counters (cheap for UI lists).
	 */
	public static function summary( string $old, string $new ): array {
		$old_lines = self::splitLines( $old );
		$new_lines = self::splitLines( $new );
		$ops       = self::diffOps( $old_lines, $new_lines );

		return array(
			'added'   => count( array_filter( $ops, static fn( array $op ): bool => 'add' === $op['type'] ) ),
			'removed' => count( array_filter( $ops, static fn( array $op ): bool => 'remove' === $op['type'] ) ),
		);
	}

	/**
	 * @return string[]
	 */
	private static function splitLines( string $text ): array {
		if ( '' === $text ) {
			return array();
		}
		$lines = preg_split( '/\r\n|\n|\r/', rtrim( $text, "\n\r" ) );
		return false === $lines ? array() : $lines;
	}

	/**
	 * Myers-lite LCS via dynamic programming. Inputs are bounded
	 * (file reads / post content), so O(n*m) memory is acceptable here.
	 *
	 * @param string[] $old_lines
	 * @param string[] $new_lines
	 * @return array<int, array{type: string, line: string, old_no: ?int, new_no: ?int}>
	 */
	private static function diffOps( array $old_lines, array $new_lines ): array {
		$n = count( $old_lines );
		$m = count( $new_lines );

		// Longest-common-subsequence table.
		$lcs = array_fill( 0, $n + 1, array_fill( 0, $m + 1, 0 ) );
		for ( $i = $n - 1; $i >= 0; $i-- ) {
			for ( $j = $m - 1; $j >= 0; $j-- ) {
				if ( $old_lines[ $i ] === $new_lines[ $j ] ) {
					$lcs[ $i ][ $j ] = $lcs[ $i + 1 ][ $j + 1 ] + 1;
				} else {
					$lcs[ $i ][ $j ] = max( $lcs[ $i + 1 ][ $j ], $lcs[ $i ][ $j + 1 ] );
				}
			}
		}

		$ops = array();
		$i   = 0;
		$j   = 0;
		while ( $i < $n && $j < $m ) {
			if ( $old_lines[ $i ] === $new_lines[ $j ] ) {
				$ops[] = array(
					'type'   => 'context',
					'line'   => $old_lines[ $i ],
					'old_no' => $i + 1,
					'new_no' => $j + 1,
				);
				$i++;
				$j++;
			} elseif ( $lcs[ $i + 1 ][ $j ] >= $lcs[ $i ][ $j + 1 ] ) {
				$ops[] = array(
					'type'   => 'remove',
					'line'   => $old_lines[ $i ],
					'old_no' => $i + 1,
					'new_no' => null,
				);
				$i++;
			} else {
				$ops[] = array(
					'type'   => 'add',
					'line'   => $new_lines[ $j ],
					'old_no' => null,
					'new_no' => $j + 1,
				);
				$j++;
			}
		}
		while ( $i < $n ) {
			$ops[] = array( 'type' => 'remove', 'line' => $old_lines[ $i ], 'old_no' => $i + 1, 'new_no' => null );
			$i++;
		}
		while ( $j < $m ) {
			$ops[] = array( 'type' => 'add', 'line' => $new_lines[ $j ], 'old_no' => null, 'new_no' => $j + 1 );
			$j++;
		}

		return $ops;
	}

	/**
	 * Render a unified diff body (hunk headers included).
	 *
	 * @param array<int, array{type: string, line: string, old_no: ?int, new_no: ?int}> $ops
	 */
	private static function unified( array $ops, int $context_lines ): string {
		// Mark which ops are within `context_lines` of an add/remove.
		$interesting = array();
		$length      = count( $ops );
		for ( $index = 0; $index < $length; $index++ ) {
			if ( 'context' !== $ops[ $index ]['type'] ) {
				$start = max( 0, $index - $context_lines );
				$end   = min( $length - 1, $index + $context_lines );
				for ( $k = $start; $k <= $end; $k++ ) {
					$interesting[ $k ] = true;
				}
			}
		}

		$out    = array();
		/** @var bool $in_hunk PHPStan: widen past the literal `false` below — this is mutated to true inside the loop before flush() ever reads it. */
		$in_hunk = false;
		$hunk_old_start = 0;
		$hunk_new_start = 0;
		$hunk_old_count = 0;
		$hunk_new_count = 0;
		$hunk_body      = array();

		$flush = static function () use ( &$out, &$in_hunk, &$hunk_old_start, &$hunk_new_start, &$hunk_old_count, &$hunk_new_count, &$hunk_body ): void {
			if ( $in_hunk ) {
				$out[] = sprintf( '@@ -%d,%d +%d,%d @@', $hunk_old_start, $hunk_old_count, $hunk_new_start, $hunk_new_count );
				$out   = array_merge( $out, $hunk_body );
			}
			$in_hunk        = false;
			$hunk_body      = array();
			$hunk_old_count = 0;
			$hunk_new_count = 0;
		};

		foreach ( $ops as $index => $op ) {
			if ( isset( $interesting[ $index ] ) ) {
				if ( ! $in_hunk ) {
					$flush();
					$in_hunk        = true;
					$hunk_old_start = $op['old_no'] ?? ( $index + 1 );
					$hunk_new_start = $op['new_no'] ?? ( $index + 1 );
				}
				$prefix = match ( $op['type'] ) {
					'add'    => '+',
					'remove' => '-',
					default  => ' ',
				};
				$hunk_body[] = $prefix . $op['line'];
				if ( null !== $op['old_no'] ) {
					$hunk_old_count++;
				}
				if ( null !== $op['new_no'] ) {
					$hunk_new_count++;
				}
			} else {
				$flush();
			}
		}
		$flush();

		return implode( "\n", $out );
	}
}
