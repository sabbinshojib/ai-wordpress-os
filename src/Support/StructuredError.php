<?php
/**
 * Structured error envelope.
 *
 * Every error surfaced to an external AI client uses this shape
 * (spec §43): { code, message, type, retryable, context }.
 * Raw stack traces are never exposed; details go to the internal log.
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

final class StructuredError implements \JsonSerializable, \Stringable {

	public const TYPE_VALIDATION  = 'validation';
	public const TYPE_PERMISSION  = 'permission';
	public const TYPE_APPROVAL    = 'approval';
	public const TYPE_NOT_FOUND   = 'not_found';
	public const TYPE_CONFLICT    = 'conflict';
	public const TYPE_RATE_LIMIT  = 'rate_limit';
	public const TYPE_EXECUTION   = 'execution';
	public const TYPE_PROTOCOL    = 'protocol';
	public const TYPE_UNAVAILABLE = 'unavailable';
	public const TYPE_SECURITY    = 'security';

	/**
	 * Machine-readable, stable error code (e.g. "tool.input_invalid").
	 */
	private string $code;

	/**
	 * Human-readable, safe message. No paths, no stack traces.
	 */
	private string $message;

	/**
	 * Broad error category, one of the TYPE_* constants.
	 */
	private string $type;

	private bool $retryable;

	/**
	 * Safe structured context (already redacted).
	 *
	 * @var array<string, mixed>
	 */
	private array $context;

	/**
	 * Internal detail, only ever logged, never serialized outward.
	 */
	private ?string $internal_detail;

	/**
	 * @param array<string, mixed> $context
	 */
	public function __construct(
		string $code,
		string $message,
		string $type = self::TYPE_EXECUTION,
		bool $retryable = false,
		array $context = array(),
		?string $internal_detail = null
	) {
		$this->code            = $code;
		$this->message         = $message;
		$this->type            = $type;
		$this->retryable       = $retryable;
		$this->context         = $context;
		$this->internal_detail = $internal_detail;
	}

	public static function validation( string $code, string $message, array $context = array() ): self {
		return new self( $code, $message, self::TYPE_VALIDATION, false, $context );
	}

	public static function permission( string $code, string $message, array $context = array() ): self {
		return new self( $code, $message, self::TYPE_PERMISSION, false, $context );
	}

	public static function approval( string $code, string $message, array $context = array() ): self {
		return new self( $code, $message, self::TYPE_APPROVAL, false, $context );
	}

	public static function notFound( string $code, string $message, array $context = array() ): self {
		return new self( $code, $message, self::TYPE_NOT_FOUND, false, $context );
	}

	public static function rateLimit( string $message, array $context = array() ): self {
		return new self( 'security.rate_limited', $message, self::TYPE_RATE_LIMIT, true, $context );
	}

	public static function security( string $code, string $message, array $context = array() ): self {
		return new self( $code, $message, self::TYPE_SECURITY, false, $context );
	}

	public static function execution( string $code, string $message, array $context = array(), bool $retryable = false ): self {
		return new self( $code, $message, self::TYPE_EXECUTION, $retryable, $context );
	}

	public static function protocol( string $code, string $message, array $context = array() ): self {
		return new self( $code, $message, self::TYPE_PROTOCOL, false, $context );
	}

	public function withContext( array $context ): self {
		$this->context = array_merge( $this->context, $context );
		return $this;
	}

	public function code(): string {
		return $this->code;
	}

	public function message(): string {
		return $this->message;
	}

	public function type(): string {
		return $this->type;
	}

	public function retryable(): bool {
		return $this->retryable;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function context(): array {
		return $this->context;
	}

	public function internalDetail(): ?string {
		return $this->internal_detail;
	}

	/**
	 * Outward-facing JSON shape.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'code'      => $this->code,
			'message'   => $this->message,
			'type'      => $this->type,
			'retryable' => $this->retryable,
			'context'   => $this->context,
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array {
		return $this->toArray();
	}

	public function __toString(): string {
		return sprintf( '[%s/%s] %s', $this->type, $this->code, $this->message );
	}
}
