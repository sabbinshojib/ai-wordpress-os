<?php
/**
 * Developer Task Planner Interface.
 *
 * Defines the contract for turning high-level requests or internal task
 * inputs into immutable, deterministic DeveloperTaskPlan instances.
 *
 * @package AIOS\Developer\Plan
 */

declare( strict_types=1 );

namespace AIOS\Developer\Plan;

use AIOS\Developer\Plan\Internal\InternalTaskPlanInput;

interface DeveloperTaskPlannerInterface {

	/**
	 * Plan a task from an external high-level request.
	 *
	 * @param DeveloperTaskRequest $request The high-level request.
	 * @return DeveloperTaskPlan The generated plan.
	 */
	public function plan( DeveloperTaskRequest $request ): DeveloperTaskPlan;

	/**
	 * Plan a task from an internal input with prebuilt operation specifications.
	 *
	 * @param InternalTaskPlanInput $input The internal input.
	 * @return DeveloperTaskPlan The generated plan.
	 */
	public function planFromInternal( InternalTaskPlanInput $input ): DeveloperTaskPlan;
}
