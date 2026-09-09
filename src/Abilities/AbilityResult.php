<?php
/**
 * AbilityResult — success/error envelope for ability execution.
 *
 * @package AIOS\Abilities
 */

declare( strict_types=1 );

namespace AIOS\Abilities;

use AIOS\Support\StructuredError;

final class AbilityResult {

		/**
		 * @var array<string, mixed>|null
		 */
	private ?array $data;

	private ?StructuredError $error;

		/**
		 * Objects affected by this ability (post ids, attachment ids…)
		 * for the audit log.
		 *
		 * @var array<int, array{type: string, id: string}>
		 */
	private array $affectedObjects = array();

		/**
		 * Human-readable note attached to approvals (what will happen).
		 */
	private string $note = '';

	private function __construct( ?array $data, ?StructuredError $error ) {
			$this->data  = $data;
			$this->error = $error;
	}

		/**
		 * @param array<string, mixed> $data
		 */
	public static function success( array $data = array() ): self {
			return new self( $data, null );
	}

	public static function error( string $code, string $message, string $type = 'execution', array $context = array(), bool $retryable = false ): self {
			return new self( null, new StructuredError( $code, $message, $type, $retryable, $context ) );
	}

	public static function fromError( StructuredError $error ): self {
			return new self( null, $error );
	}

	public function ok(): bool {
			return null === $this->error;
	}

		/**
		 * @return array<string, mixed>
		 */
	public function data(): array {
			return $this->data ?? array();
	}

	public function getError(): ?StructuredError {
			return $this->error;
	}

		/**
		 * @param string     $type Object type (e.g. 'post', 'attachment').
		 * @param string|int $id   Object identifier.
		 */
	public function affected( string $type, string|int $id ): self {
			$this->affectedObjects[] = array(
				'type' => $type,
				'id'   => (string) $id,
			);
			return $this;
	}

		/**
		 * @return array<int, array{type: string, id: string}>
		 */
	public function affectedObjects(): array {
			return $this->affectedObjects;
	}

	public function note( string $note ): self {
			$this->note = $note;
			return $this;
	}

	public function getNote(): string {
			return $this->note;
	}

		/**
		 * @return array<string, mixed>
		 */
	public function toArray(): array {
			return array(
				'ok'       => $this->ok(),
				'data'     => $this->data ?? array(),
				'error'    => $this->error?->toArray(),
				'note'     => $this->note,
				'affected' => $this->affectedObjects,
			);
	}
}
