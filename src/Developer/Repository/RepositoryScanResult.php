<?php
/**
 * Repository Scan Result — immutable value object for a bounded scan.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class RepositoryScanResult {

	/**
	 * The scope path that was scanned (as given to the scanner).
	 */
	private string $scopePath;

	/**
	 * Scanned entries.
	 *
	 * @var RepositoryScanEntry[]
	 */
	private array $entries;

	/**
	 * Whether the scan was truncated by the max-entries bound.
	 */
	private bool $truncated;

	/**
	 * Unix timestamp the scan completed.
	 */
	private int $scannedAt;

	/**
	 * @param RepositoryScanEntry[] $entries Scanned entries.
	 */
	public function __construct( string $scope_path, array $entries, bool $truncated, int $scanned_at ) {
		foreach ( $entries as $entry ) {
			if ( ! $entry instanceof RepositoryScanEntry ) {
				throw new \InvalidArgumentException( 'Every entry in RepositoryScanResult must be a RepositoryScanEntry.' );
			}
		}
		if ( $scanned_at < 0 ) {
			throw new \InvalidArgumentException( 'RepositoryScanResult scannedAt cannot be negative.' );
		}

		$this->scopePath = $scope_path;
		$this->entries   = array_values( $entries );
		$this->truncated = $truncated;
		$this->scannedAt = $scanned_at;
	}

	public function scopePath(): string {
		return $this->scopePath;
	}

	/**
	 * @return RepositoryScanEntry[]
	 */
	public function entries(): array {
		return $this->entries;
	}

	public function truncated(): bool {
		return $this->truncated;
	}

	public function scannedAt(): int {
		return $this->scannedAt;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'scope_path' => $this->scopePath,
			'entries'    => array_map( static fn ( RepositoryScanEntry $e ): array => $e->toArray(), $this->entries ),
			'truncated'  => $this->truncated,
			'scanned_at' => $this->scannedAt,
		);
	}
}
