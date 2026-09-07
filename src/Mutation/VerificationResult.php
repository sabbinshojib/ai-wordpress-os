<?php
/**
 * Outcome of a ChangeOperation's post-apply verify() call: did the
 * mutation have its intended — and only its intended — effect.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class VerificationResult {

	private function __construct(
		private readonly bool $ok,
		private readonly string $operationId,
		private readonly string $message,
		/** @var array<string, mixed> */
		private readonly array $context
	) {}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function success( string $operation_id, string $message = 'verified', array $context = array() ): self {
		return new self( true, $operation_id, $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public static function failure( string $operation_id, string $message, array $context = array() ): self {
		return new self( false, $operation_id, $message, $context );
	}

	public function ok(): bool {
		return $this->ok;
	}

	public function operationId(): string {
		return $this->operationId;
	}

	public function message(): string {
		return $this->message;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function context(): array {
		return $this->context;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'ok'           => $this->ok,
			'operation_id' => $this->operationId,
			'message'      => $this->message,
			'context'      => $this->context,
		);
	}
}
