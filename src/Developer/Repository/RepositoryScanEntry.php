<?php
/**
 * Repository Scan Entry — immutable value object for one scanned filesystem entry.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class RepositoryScanEntry {

	/**
	 * Root-relative, forward-slash path.
	 */
	private string $path;

	/**
	 * Whether the entry is a directory (false = regular file).
	 */
	private bool $isDirectory;

	/**
	 * File size in bytes (0 for directories).
	 */
	private int $size;

	/**
	 * Last-modified unix timestamp.
	 */
	private int $mtime;

	/**
	 * Detected PHP namespace, when parseable from a `.php` file's header.
	 */
	private ?string $phpNamespace;

	/**
	 * Detected primary PHP class/interface/trait/enum name, when parseable.
	 */
	private ?string $phpClass;

	public function __construct(
		string $path,
		bool $is_directory,
		int $size,
		int $mtime,
		?string $php_namespace = null,
		?string $php_class = null
	) {
		$clean_path = trim( $path );
		if ( '' === $clean_path ) {
			throw new \InvalidArgumentException( 'RepositoryScanEntry path cannot be empty.' );
		}
		if ( $size < 0 ) {
			throw new \InvalidArgumentException( 'RepositoryScanEntry size cannot be negative.' );
		}
		if ( $mtime < 0 ) {
			throw new \InvalidArgumentException( 'RepositoryScanEntry mtime cannot be negative.' );
		}

		$this->path         = $clean_path;
		$this->isDirectory  = $is_directory;
		$this->size         = $size;
		$this->mtime        = $mtime;
		$this->phpNamespace = $php_namespace;
		$this->phpClass     = $php_class;
	}

	public function path(): string {
		return $this->path;
	}

	public function isDirectory(): bool {
		return $this->isDirectory;
	}

	public function size(): int {
		return $this->size;
	}

	public function mtime(): int {
		return $this->mtime;
	}

	public function phpNamespace(): ?string {
		return $this->phpNamespace;
	}

	public function phpClass(): ?string {
		return $this->phpClass;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'path'          => $this->path,
			'is_directory'  => $this->isDirectory,
			'size'          => $this->size,
			'mtime'         => $this->mtime,
			'php_namespace' => $this->phpNamespace,
			'php_class'     => $this->phpClass,
		);
	}
}
