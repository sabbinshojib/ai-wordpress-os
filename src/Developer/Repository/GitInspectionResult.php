<?php
/**
 * Git Inspection Result — immutable value object for read-only git state.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class GitInspectionResult {

	private bool $available;

	private ?string $branch;

	private ?string $headSha;

	private bool $clean;

	/**
	 * Changed files as reported by `git status --porcelain=v1`.
	 *
	 * @var array<int, array{status: string, path: string}>
	 */
	private array $changedFiles;

	private ?string $error;

	/**
	 * @param array<int, array{status: string, path: string}> $changed_files Changed files.
	 */
	public function __construct(
		bool $available,
		?string $branch,
		?string $head_sha,
		bool $clean,
		array $changed_files,
		?string $error = null
	) {
		foreach ( $changed_files as $file ) {
			if ( ! is_array( $file ) || ! isset( $file['status'], $file['path'] ) || ! is_string( $file['status'] ) || ! is_string( $file['path'] ) ) {
				throw new \InvalidArgumentException( 'Every changed file entry must have string "status" and "path".' );
			}
		}

		$this->available    = $available;
		$this->branch       = $branch;
		$this->headSha      = $head_sha;
		$this->clean        = $clean;
		$this->changedFiles = array_values( $changed_files );
		$this->error        = $error;
	}

	public function available(): bool {
		return $this->available;
	}

	public function branch(): ?string {
		return $this->branch;
	}

	public function headSha(): ?string {
		return $this->headSha;
	}

	public function clean(): bool {
		return $this->clean;
	}

	/**
	 * @return array<int, array{status: string, path: string}>
	 */
	public function changedFiles(): array {
		return $this->changedFiles;
	}

	public function error(): ?string {
		return $this->error;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'available'     => $this->available,
			'branch'        => $this->branch,
			'head_sha'      => $this->headSha,
			'clean'         => $this->clean,
			'changed_files' => $this->changedFiles,
			'error'         => $this->error,
		);
	}
}
