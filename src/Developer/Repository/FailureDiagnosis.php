<?php
/**
 * Failure Diagnosis — immutable value object for one parsed diagnostic finding.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class FailureDiagnosis {

	public const CATEGORY_PHPUNIT_FAILURE = 'phpunit_failure';
	public const CATEGORY_PHPSTAN_ERROR   = 'phpstan_error';
	public const CATEGORY_PHPCS_VIOLATION = 'phpcs_violation';
	public const CATEGORY_UNPARSEABLE     = 'unparseable';

	/**
	 * @var string[]
	 */
	public const CATEGORIES = array(
		self::CATEGORY_PHPUNIT_FAILURE,
		self::CATEGORY_PHPSTAN_ERROR,
		self::CATEGORY_PHPCS_VIOLATION,
		self::CATEGORY_UNPARSEABLE,
	);

	private string $category;

	private ?string $file;

	private ?int $line;

	private string $message;

	/**
	 * Raw source tool identifier as supplied by the caller ('phpunit'|'phpstan'|'phpcs'|'unknown').
	 */
	private string $sourceTool;

	public function __construct( string $category, ?string $file, ?int $line, string $message, string $source_tool ) {
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unsupported FailureDiagnosis category "%s".', $category ) );
		}
		if ( null !== $line && $line < 1 ) {
			throw new \InvalidArgumentException( 'FailureDiagnosis line must be a positive integer when provided.' );
		}

		$this->category   = $category;
		$this->file       = $file;
		$this->line       = $line;
		$this->message    = trim( $message );
		$this->sourceTool = $source_tool;
	}

	public function category(): string {
		return $this->category;
	}

	public function file(): ?string {
		return $this->file;
	}

	public function line(): ?int {
		return $this->line;
	}

	public function message(): string {
		return $this->message;
	}

	public function sourceTool(): string {
		return $this->sourceTool;
	}

	public function isUnparseable(): bool {
		return self::CATEGORY_UNPARSEABLE === $this->category;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'category'    => $this->category,
			'file'        => $this->file,
			'line'        => $this->line,
			'message'     => $this->message,
			'source_tool' => $this->sourceTool,
		);
	}
}
