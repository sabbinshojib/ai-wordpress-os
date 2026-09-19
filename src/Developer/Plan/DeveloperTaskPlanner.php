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
use AIOS\Developer\Repository\GitInspector;
use AIOS\Developer\Repository\RepositoryScanner;
use AIOS\Developer\Support\DeveloperTestIdentifier;
use AIOS\Developer\Support\JsonSafeValidator;
use AIOS\Security\PathGuardException;
use AIOS\Settings\Settings;

final class DeveloperTaskPlanner implements DeveloperTaskPlannerInterface {

	/**
	 * Settings instance for threshold checks.
	 */
	private ?Settings $settings;

	/**
	 * Repository scanner collaborator (P3-B). Null preserves the exact
	 * P3-A behavior (no repository-intelligence metadata populated).
	 */
	private ?RepositoryScanner $repositoryScanner;

	/**
	 * Git inspector collaborator (P3-B). Null preserves the exact P3-A
	 * behavior (no git-intelligence metadata populated).
	 */
	private ?GitInspector $gitInspector;

	/**
	 * Constructor.
	 *
	 * @param Settings|null          $settings           Settings collaborator.
	 * @param RepositoryScanner|null $repository_scanner P3-B read-only repository scanner; omit to keep P3-A behavior unchanged.
	 * @param GitInspector|null      $git_inspector      P3-B read-only git inspector; omit to keep P3-A behavior unchanged.
	 */
	public function __construct( ?Settings $settings = null, ?RepositoryScanner $repository_scanner = null, ?GitInspector $git_inspector = null ) {
		$this->settings          = $settings;
		$this->repositoryScanner = $repository_scanner;
		$this->gitInspector      = $git_inspector;
	}

	/**
	 * Plan a task from an external high-level request.
	 *
	 * In P3-A, high-level requests without internal operation specs produce
	 * read-only inspection plans. P3-B (when a RepositoryScanner and/or
	 * GitInspector collaborator is supplied) additionally populates the
	 * plan's metadata with real, read-only repository/git findings for a
	 * non-empty scope — operations remain empty either way; P3-B never
	 * emits operations.
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
		$metadata      = $this->withRepositoryIntelligence( $request->metadata(), $request->scope() );

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
			$metadata
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

		$tests = $input->testStrategies();
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
	 * Merge best-effort, read-only repository/git findings into plan
	 * metadata. Never throws: any scan/inspection failure is recorded as
	 * an error string under the same namespaced metadata key rather than
	 * propagating, since repository intelligence is informational and
	 * must never block plan creation. A no-op (returns $metadata as-is)
	 * when no scope is given or no P3-B collaborator was injected — this
	 * is what keeps every existing P3-A caller's behavior byte-identical.
	 *
	 * @param array<string, mixed> $metadata Request metadata.
	 * @param string[]              $scope    Request scope paths.
	 * @return array<string, mixed>
	 */
	private function withRepositoryIntelligence( array $metadata, array $scope ): array {
		if ( array() === $scope || ( null === $this->repositoryScanner && null === $this->gitInspector ) ) {
			return $metadata;
		}

		if ( null !== $this->repositoryScanner ) {
			$scans = array();
			foreach ( $scope as $scope_path ) {
				if ( ! is_string( $scope_path ) || '' === $scope_path ) {
					continue;
				}
				try {
					$scans[ $scope_path ] = $this->repositoryScanner->scan( $scope_path )->toArray();
				} catch ( PathGuardException $e ) {
					$scans[ $scope_path ] = array(
						'error'  => $e->getMessage(),
						'reason' => $e->reason(),
					);
				}
			}
			$metadata['p3b_repository_scan'] = $scans;
		}

		if ( null !== $this->gitInspector ) {
			try {
				$metadata['p3b_git_inspection'] = $this->gitInspector->inspect()->toArray();
			} catch ( \Throwable $e ) {
				$metadata['p3b_git_inspection'] = array(
					'available' => false,
					'error'     => $e->getMessage(),
				);
			}
		}

		JsonSafeValidator::assertJsonSafe( $metadata, 'metadata' );

		return $metadata;
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
