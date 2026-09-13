<?php
/**
 * Concrete Developer Task Planner.
 *
 * Implements DeveloperTaskPlannerInterface to turn requests or internal task
 * inputs into immutable DeveloperTaskPlan instances with derived capabilities,
 * risk levels, and approval flags.
 *
 * @package AIOS\Developer\Plan
 */

declare( strict_types=1 );

namespace AIOS\Developer\Plan;

use AIOS\Developer\Plan\Internal\InternalTaskPlanInput;
use AIOS\Developer\Support\DeveloperTestIdentifier;
use AIOS\Developer\Support\JsonSafeValidator;
use AIOS\Settings\Settings;

final class DeveloperTaskPlanner implements DeveloperTaskPlannerInterface {

	/**
	 * Settings instance for threshold checks.
	 */
	private ?Settings $settings;

	/**
	 * Constructor.
	 *
	 * @param Settings|null $settings Settings collaborator.
	 */
	public function __construct( ?Settings $settings = null ) {
		$this->settings = $settings;
	}

	/**
	 * Plan a task from an external high-level request.
	 *
	 * In P3-A, high-level requests without internal operation specs produce
	 * read-only inspection plans.
	 *
	 * @param DeveloperTaskRequest $request The high-level request.
	 * @return DeveloperTaskPlan The generated plan.
	 */
	public function plan( DeveloperTaskRequest $request ): DeveloperTaskPlan {
		$task_id       = 'dtp-' . bin2hex( random_bytes( 8 ) );
		$operations    = array();
		$tests         = array();
		$required_caps = DeveloperTaskPlan::deriveRequiredCapabilities( $operations, $request->scope(), $tests );
		$risk_level    = DeveloperTaskPlan::deriveRiskLevel( $operations, $required_caps );
		$approval      = $this->resolveApprovalRequirement( $risk_level );
		$created_at    = time();

		return new DeveloperTaskPlan(
			$task_id,
			$request->objective(),
			$request->scope(),
			$operations,
			$tests,
			$required_caps,
			$risk_level,
			$approval,
			$request->principalUserId(),
			$request->siteId(),
			$created_at,
			$request->metadata()
		);
	}

	/**
	 * Plan a task from an internal input with prebuilt operation specifications.
	 *
	 * @param InternalTaskPlanInput $input The internal input.
	 * @return DeveloperTaskPlan The generated plan.
	 */
	public function planFromInternal( InternalTaskPlanInput $input ): DeveloperTaskPlan {
		$request    = $input->request();
		$task_id    = 'dtp-' . bin2hex( random_bytes( 8 ) );
		$operations = array();

		foreach ( $input->operationSpecifications() as $op_spec ) {
			$operations[] = array(
				'type' => $op_spec->type,
				'spec' => $op_spec->spec,
			);
		}

		$tests         = $input->testStrategies();
		DeveloperTestIdentifier::assertAllValid( $tests );

		$required_caps = DeveloperTaskPlan::deriveRequiredCapabilities( $operations, $request->scope(), $tests );
		$risk_level    = DeveloperTaskPlan::deriveRiskLevel( $operations, $required_caps );
		$approval      = $this->resolveApprovalRequirement( $risk_level );
		$created_at    = time();

		return new DeveloperTaskPlan(
			$task_id,
			$request->objective(),
			$request->scope(),
			$operations,
			$tests,
			$required_caps,
			$risk_level,
			$approval,
			$request->principalUserId(),
			$request->siteId(),
			$created_at,
			$request->metadata()
		);
	}

	/**
	 * Resolve whether human approval is required for a risk level.
	 *
	 * @param int $risk_level Derived risk level.
	 * @return bool True if approval required.
	 */
	private function resolveApprovalRequirement( int $risk_level ): bool {
		if ( null !== $this->settings ) {
			return $risk_level >= $this->settings->approvalThreshold();
		}

		return DeveloperTaskPlan::deriveRequiresApproval( $risk_level );
	}
}
