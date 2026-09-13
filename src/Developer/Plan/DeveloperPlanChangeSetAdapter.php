<?php
/**
 * Developer Plan ChangeSet Adapter — bridges DeveloperTaskPlan to ChangeSet.
 *
 * Translates an immutable, reviewable DeveloperTaskPlan into an executable
 * ChangeSet via TypedChangeSetBuilder. Ensures that the DeveloperTaskPlan
 * itself remains purely data-oriented with zero execution or mutation methods.
 *
 * @package AIOS\Developer\Plan
 */

declare( strict_types=1 );

namespace AIOS\Developer\Plan;

use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\OperationSpecification;
use AIOS\Mutation\TypedChangeSetBuilder;
use AIOS\Security\PathGuard;

final class DeveloperPlanChangeSetAdapter {

	/**
	 * TypedChangeSetBuilder collaborator.
	 */
	private TypedChangeSetBuilder $builder;

	/**
	 * Constructor.
	 *
	 * @param TypedChangeSetBuilder|null $builder Optional pre-configured builder.
	 */
	public function __construct( ?TypedChangeSetBuilder $builder = null ) {
		$this->builder = $builder ?? new TypedChangeSetBuilder();
	}

	/**
	 * Convert a DeveloperTaskPlan into an executable ChangeSet.
	 *
	 * @param DeveloperTaskPlan  $plan       The source task plan.
	 * @param PathGuard|null     $path_guard PathGuard instance required for file operations.
	 * @return ChangeSet The executable ChangeSet.
	 * @throws \InvalidArgumentException If plan has no operations or conversion fails.
	 */
	public function toChangeSet( DeveloperTaskPlan $plan, ?PathGuard $path_guard = null ): ChangeSet {
		$raw_operations = $plan->operations();
		if ( array() === $raw_operations ) {
			throw new \InvalidArgumentException(
				sprintf( 'DeveloperTaskPlan "%s" has no operations to convert to a ChangeSet.', $plan->taskId() )
			);
		}

		$specs = array();
		foreach ( $raw_operations as $op ) {
			$specs[] = new OperationSpecification( $op['type'], $op['spec'] );
		}

		$metadata = array_merge(
			$plan->metadata(),
			array(
				'developer_task_id'   => $plan->taskId(),
				'developer_task_fp'   => $plan->fingerprint(),
				'developer_objective' => $plan->objective(),
			)
		);

		return $this->builder->build(
			$plan->principalUserId(),
			$specs,
			$metadata,
			$plan->siteId(),
			$path_guard
		);
	}
}
