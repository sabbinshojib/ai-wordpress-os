<?php
/**
 * Thrown by ChangeOperationInterface implementations and
 * MutationEngine on any failure in the mutation pipeline. Always
 * carries a short, non-secret, machine-stable code — never a raw
 * exception message from an underlying filesystem/DB error that might
 * embed a path outside root or other sensitive detail.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class MutationException extends \RuntimeException {

	private string $errorCode;

	public function __construct( string $error_code, string $message, ?\Throwable $previous = null ) {
		parent::__construct( $message, 0, $previous );
		$this->errorCode = $error_code;
	}

	public function errorCode(): string {
		return $this->errorCode;
	}
}
