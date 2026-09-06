<?php
/**
 * FileReadResult — outcome envelope of a safe file read.
 *
 * @package AIOS\Files
 */

declare( strict_types=1 );

namespace AIOS\Files;

use AIOS\Support\StructuredError;

final class FileReadResult {

	private function __construct(
		private ?string $path,
		private ?string $content,
		private ?StructuredError $error
	) {}

	public static function ok( string $path, string $content ): self {
		return new self( $path, $content, null );
	}

	public static function failed( StructuredError $error ): self {
		return new self( null, null, $error );
	}

	public function path(): ?string {
		return $this->path;
	}

	public function content(): ?string {
		return $this->content;
	}

	/**
	 * Secret-shaped patterns are redacted from the returned copy.
	 */
	public function redactedContent(): string {
		return \AIOS\Support\Sanitize::redactString( $this->content ?? '' );
	}

	public function size(): int {
		return strlen( $this->content ?? '' );
	}

	public function lineCount(): int {
		$content = $this->content ?? '';
		return '' === $content ? 0 : substr_count( $content, "\n" ) + 1;
	}

	public function error(): ?StructuredError {
		return $this->error;
	}
}
