<?php
/**
 * Failure Diagnoser — parses caller-supplied test/static-analysis output.
 *
 * Implements DeveloperCapability::DIAGNOSE_FAILURE (P3-B, T-112). Never
 * executes PHPUnit, PHPStan, or PHPCS itself — it only parses text output
 * the caller already produced elsewhere, keeping this class decoupled from
 * test/analysis execution (out of P3-B scope; see the P3-B spec's Non-goals).
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class FailureDiagnoser {

	public const TOOL_PHPUNIT = 'phpunit';
	public const TOOL_PHPSTAN = 'phpstan';
	public const TOOL_PHPCS   = 'phpcs';
	public const TOOL_AUTO    = 'auto';

	/**
	 * Parse a raw diagnostic payload into one or more structured diagnoses.
	 *
	 * @return FailureDiagnosis[] Never empty — a single CATEGORY_UNPARSEABLE
	 *                            entry is returned when nothing recognizable is found.
	 */
	public function diagnose( string $payload, string $tool = self::TOOL_AUTO ): array {
		if ( '' === trim( $payload ) ) {
			return array( $this->unparseable( '', 'Empty diagnostic payload supplied.' ) );
		}

		$order = match ( $tool ) {
			self::TOOL_PHPUNIT => array( self::TOOL_PHPUNIT ),
			self::TOOL_PHPSTAN => array( self::TOOL_PHPSTAN ),
			self::TOOL_PHPCS   => array( self::TOOL_PHPCS ),
			default            => array( self::TOOL_PHPUNIT, self::TOOL_PHPSTAN, self::TOOL_PHPCS ),
		};

		foreach ( $order as $candidate ) {
			$found = match ( $candidate ) {
				self::TOOL_PHPUNIT => $this->parsePhpunit( $payload ),
				self::TOOL_PHPSTAN => $this->parsePhpstan( $payload ),
				self::TOOL_PHPCS   => $this->parsePhpcs( $payload ),
			};

			if ( array() !== $found ) {
				return $found;
			}
		}

		return array( $this->unparseable( $tool, 'No recognizable PHPUnit, PHPStan, or PHPCS failure pattern found in payload.' ) );
	}

	/**
	 * @return FailureDiagnosis[]
	 */
	private function parsePhpunit( string $payload ): array {
		if ( 0 === preg_match_all( '/^\d+\)\s+([A-Za-z0-9_\\\\]+)::([A-Za-z0-9_]+)\s*$/m', $payload, $matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$diagnoses = array();
		$count     = count( $matches[0] );

		for ( $i = 0; $i < $count; $i++ ) {
			$header_end = $matches[0][ $i ][1] + strlen( $matches[0][ $i ][0] );
			$block_end  = $i + 1 < $count ? $matches[0][ $i + 1 ][1] : strlen( $payload );
			$block      = substr( $payload, $header_end, $block_end - $header_end );

			$file    = null;
			$line    = null;
			$message = 'Test failure (no assertion message captured).';

			if ( preg_match( '/^\s*(.+\.php):(\d+)\s*$/m', $block, $loc ) ) {
				$file = trim( $loc[1] );
				$line = (int) $loc[2];
			}

			$block_lines = preg_split( '/\R/', trim( $block ) );
			$block_lines = false === $block_lines ? array() : $block_lines;
			foreach ( $block_lines as $candidate_line ) {
				$candidate_line = trim( $candidate_line );
				if ( '' === $candidate_line || 1 === preg_match( '/\.php:\d+$/', $candidate_line ) ) {
					continue;
				}
				$message = $candidate_line;
				break;
			}

			$diagnoses[] = new FailureDiagnosis(
				FailureDiagnosis::CATEGORY_PHPUNIT_FAILURE,
				$file,
				$line,
				sprintf( '%s::%s — %s', $matches[1][ $i ][0], $matches[2][ $i ][0], $message ),
				self::TOOL_PHPUNIT
			);
		}

		return $diagnoses;
	}

	/**
	 * @return FailureDiagnosis[]
	 */
	private function parsePhpstan( string $payload ): array {
		// PHPStan `--error-format=raw`: "path:line:message" per line.
		if ( 0 === preg_match_all( '/^([^\s:][^\n:]*\.php):(\d+):(.+)$/m', $payload, $matches, PREG_SET_ORDER ) ) {
			return array();
		}

		$diagnoses = array();
		foreach ( $matches as $match ) {
			$diagnoses[] = new FailureDiagnosis(
				FailureDiagnosis::CATEGORY_PHPSTAN_ERROR,
				trim( $match[1] ),
				(int) $match[2],
				trim( $match[3] ),
				self::TOOL_PHPSTAN
			);
		}

		return $diagnoses;
	}

	/**
	 * @return FailureDiagnosis[]
	 */
	private function parsePhpcs( string $payload ): array {
		if ( 0 === preg_match_all( '/^FILE:\s*(.+)$/m', $payload, $file_matches, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}

		$diagnoses = array();
		$count     = count( $file_matches[0] );

		for ( $i = 0; $i < $count; $i++ ) {
			$file        = trim( $file_matches[1][ $i ][0] );
			$block_start = $file_matches[0][ $i ][1] + strlen( $file_matches[0][ $i ][0] );
			$block_end   = $i + 1 < $count ? $file_matches[0][ $i + 1 ][1] : strlen( $payload );
			$block       = substr( $payload, $block_start, $block_end - $block_start );

			if ( 0 === preg_match_all( '/^\s*(\d+)\s*\|\s*(ERROR|WARNING)\s*\|\s*(.+)$/m', $block, $rows, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $rows as $row ) {
				$diagnoses[] = new FailureDiagnosis(
					FailureDiagnosis::CATEGORY_PHPCS_VIOLATION,
					$file,
					(int) $row[1],
					sprintf( '[%s] %s', $row[2], trim( $row[3] ) ),
					self::TOOL_PHPCS
				);
			}
		}

		return $diagnoses;
	}

	/**
	 * @return FailureDiagnosis
	 */
	private function unparseable( string $tool, string $reason ): FailureDiagnosis {
		return new FailureDiagnosis(
			FailureDiagnosis::CATEGORY_UNPARSEABLE,
			null,
			null,
			$reason,
			'' === $tool ? self::TOOL_AUTO : $tool
		);
	}
}
