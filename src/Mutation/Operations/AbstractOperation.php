<?php
/**
 * Shared id-generation for every concrete ChangeOperationInterface.
 *
 * @package AIOS\Mutation\Operations
 */

declare( strict_types=1 );

namespace AIOS\Mutation\Operations;

use AIOS\Mutation\ChangeOperationInterface;

abstract class AbstractOperation implements ChangeOperationInterface {

	protected readonly string $id;

	public function __construct() {
		$this->id = 'op_' . bin2hex( random_bytes( 12 ) );
	}

	public function id(): string {
		return $this->id;
	}
}
