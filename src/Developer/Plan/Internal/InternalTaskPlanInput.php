<?php
/**
 * Internal Task Plan Input — isolated adapter contract for prebuilt operations.
 *
 * Dedicated internal contract allowing the planner to accept prebuilt
 * OperationSpecification instances during P3-A testing before P3-B repository
 * intelligence is built. This keeps low-level operations out of the public
 * DeveloperTaskRequest API.
 *
 * @package AIOS\Developer\Plan\Internal
 */

declare( strict_types=1 );

namespace AIOS\Developer\Plan\Internal;

use AIOS\Developer\Plan\DeveloperTaskRequest;
use AIOS\Developer\Support\DeveloperTestIdentifier;
use AIOS\Mutation\OperationSpecification;

final class InternalTaskPlanInput {

	/**
	 * Underlying high-level task request.
	 */
	private DeveloperTaskRequest $request;

	/**
	 * Prebuilt operation specifications.
	 *
	 * @var OperationSpecification[]
	 */
	private array $operationSpecifications;

	/**
	 * Whitelisted test strategy identifiers.
	 *
	 * @var string[]
	 */
	private array $testStrategies;

	/**
	 * Constructor.
	 *
	 * @param DeveloperTaskRequest     $request                  The base task request.
	 * @param OperationSpecification[] $operation_specifications List of operation specs.
	 * @param string[]                 $test_strategies          List of approved test strategy identifiers.
	 * @throws \InvalidArgumentException If operations or test strategies are invalid.
	 */
	public function __construct(
		DeveloperTaskRequest $request,
		array $operation_specifications,
		array $test_strategies = array()
	) {
		if ( array() === $operation_specifications ) {
			throw new \InvalidArgumentException( 'InternalTaskPlanInput requires at least one OperationSpecification.' );
		}

		foreach ( $operation_specifications as $spec ) {
			if ( ! $spec instanceof OperationSpecification ) {
				throw new \InvalidArgumentException(
					'Every item in operationSpecifications must be an instance of OperationSpecification.'
				);
			}
		}

		DeveloperTestIdentifier::assertAllValid( $test_strategies );

		$this->request                 = $request;
		$this->operationSpecifications = array_values( $operation_specifications );
		$this->testStrategies          = array_values( $test_strategies );
	}

	public function request(): DeveloperTaskRequest {
		return $this->request;
	}

	/**
	 * @return OperationSpecification[]
	 */
	public function operationSpecifications(): array {
		return $this->operationSpecifications;
	}

	/**
	 * @return string[]
	 */
	public function testStrategies(): array {
		return $this->testStrategies;
	}
}
