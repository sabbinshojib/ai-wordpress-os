<?php
/**
 * Developer Task Plan — immutable, deterministic plan value object.
 *
 * Represents the complete, reviewable blueprint of a developer task.
 * Contains no execution or mutation methods; conversion to executable
 * ChangeSet units is handled exclusively by DeveloperPlanChangeSetAdapter.
 *
 * @package AIOS\Developer\Plan
 */

declare( strict_types=1 );

namespace AIOS\Developer\Plan;

use AIOS\Developer\Capability\DeveloperCapability;
use AIOS\Developer\Support\DeveloperTestIdentifier;
use AIOS\Developer\Support\JsonSafeValidator;
use AIOS\Mutation\OperationRegistry;
use AIOS\Security\PermissionEngine;

final class DeveloperTaskPlan {

	/**
	 * Unique task instance identifier (e.g. dtp-...).
	 */
	private string $taskId;

	/**
	 * High-level objective of the task.
	 */
	private string $objective;

	/**
	 * Target file/directory scope paths.
	 *
	 * @var string[]
	 */
	private array $scope;

	/**
	 * Serialized operation specifications in exact sequential order.
	 *
	 * @var array<int, array{type: string, spec: array<string, mixed>}>
	 */
	private array $operations;

	/**
	 * Whitelisted test strategy identifiers.
	 *
	 * @var string[]
	 */
	private array $testStrategies;

	/**
	 * Required developer capabilities for this plan.
	 *
	 * @var string[]
	 */
	private array $requiredCapabilities;

	/**
	 * Derived risk level (0-4).
	 */
	private int $riskLevel;

	/**
	 * Whether human approval is required before applying.
	 */
	private bool $requiresApproval;

	/**
	 * Acting WordPress user ID.
	 */
	private int $principalUserId;

	/**
	 * Target WordPress blog/site ID.
	 */
	private ?int $siteId;

	/**
	 * Plan creation timestamp.
	 */
	private int $createdAt;

	/**
	 * Contextual metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata;

	/**
	 * Cached fingerprint.
	 */
	private ?string $cachedFingerprint = null;

	/**
	 * Constructor.
	 *
	 * @param string                                                      $task_id               Task instance ID.
	 * @param string                                                      $objective             Plan objective.
	 * @param string[]                                                    $scope                 Scope paths.
	 * @param array<int, array{type: string, spec: array<string, mixed>}> $operations            Sequential operation specs.
	 * @param string[]                                                    $test_strategies       Whitelisted test strategy IDs.
	 * @param string[]                                                    $required_capabilities Required capability IDs.
	 * @param int                                                         $risk_level            Derived risk level.
	 * @param bool                                                        $requires_approval     Approval requirement flag.
	 * @param int                                                         $principal_user_id     Acting user ID.
	 * @param int|null                                                    $site_id               WordPress site ID.
	 * @param int                                                         $created_at            Unix timestamp.
	 * @param array<string, mixed>                                        $metadata              Arbitrary JSON-safe metadata.
	 * @throws \InvalidArgumentException If validation fails.
	 */
	public function __construct(
		string $task_id,
		string $objective,
		array $scope,
		array $operations,
		array $test_strategies,
		array $required_capabilities,
		int $risk_level,
		bool $requires_approval,
		int $principal_user_id,
		?int $site_id,
		int $created_at,
		array $metadata = array()
	) {
		$clean_id = trim( $task_id );
		if ( '' === $clean_id || ! str_starts_with( $clean_id, 'dtp-' ) ) {
			throw new \InvalidArgumentException( 'DeveloperTaskPlan task_id must start with "dtp-".' );
		}

		$clean_objective = trim( $objective );
		if ( '' === $clean_objective ) {
			throw new \InvalidArgumentException( 'DeveloperTaskPlan objective cannot be empty.' );
		}

		if ( $principal_user_id <= 0 ) {
			throw new \InvalidArgumentException( 'DeveloperTaskPlan principal_user_id must be greater than zero.' );
		}

		if ( null !== $site_id && $site_id <= 0 ) {
			throw new \InvalidArgumentException( 'DeveloperTaskPlan site_id must be greater than zero when provided.' );
		}

		if ( $created_at <= 0 ) {
			throw new \InvalidArgumentException( 'DeveloperTaskPlan created_at must be a valid positive timestamp.' );
		}

		if ( $risk_level < PermissionEngine::LEVEL_READ || $risk_level > PermissionEngine::LEVEL_DEPLOYMENT ) {
			throw new \InvalidArgumentException( sprintf( 'DeveloperTaskPlan risk_level %d is out of bounds (0-4).', $risk_level ) );
		}

		foreach ( $scope as $item ) {
			if ( ! is_string( $item ) ) {
				throw new \InvalidArgumentException( 'Every item in DeveloperTaskPlan scope must be a string.' );
			}
		}

		foreach ( $operations as $op ) {
			if ( ! is_array( $op ) || ! isset( $op['type'] ) || ! is_string( $op['type'] ) || ! isset( $op['spec'] ) || ! is_array( $op['spec'] ) ) {
				throw new \InvalidArgumentException( 'Every operation in DeveloperTaskPlan must be an array with "type" and "spec".' );
			}
			if ( ! OperationRegistry::isSupported( $op['type'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unsupported operation type "%s" in DeveloperTaskPlan.', $op['type'] ) );
			}
			JsonSafeValidator::assertJsonSafe( $op['spec'], 'operation.spec' );
		}

		DeveloperTestIdentifier::assertAllValid( $test_strategies );

		foreach ( $required_capabilities as $cap ) {
			if ( ! is_string( $cap ) || ! DeveloperCapability::isSupported( $cap ) ) {
				throw new \InvalidArgumentException( sprintf( 'Unsupported required capability "%s" in DeveloperTaskPlan.', is_scalar( $cap ) ? (string) $cap : gettype( $cap ) ) );
			}
		}

		JsonSafeValidator::assertJsonSafe( $metadata, 'metadata' );

		$this->taskId               = $clean_id;
		$this->objective            = $clean_objective;
		$this->scope                = array_values( $scope );
		$this->operations           = array_values( $operations );
		$this->testStrategies       = array_values( $test_strategies );
		$this->requiredCapabilities = array_values( array_unique( $required_capabilities ) );
		$this->riskLevel            = $risk_level;
		$this->requiresApproval     = $requires_approval;
		$this->principalUserId      = $principal_user_id;
		$this->siteId               = $site_id;
		$this->createdAt            = $created_at;
		$this->metadata             = $metadata;
	}

	public function taskId(): string {
		return $this->taskId;
	}

	public function objective(): string {
		return $this->objective;
	}

	/**
	 * @return string[]
	 */
	public function scope(): array {
		return $this->scope;
	}

	/**
	 * @return array<int, array{type: string, spec: array<string, mixed>}>
	 */
	public function operations(): array {
		return $this->operations;
	}

	/**
	 * @return string[]
	 */
	public function testStrategies(): array {
		return $this->testStrategies;
	}

	/**
	 * @return string[]
	 */
	public function requiredCapabilities(): array {
		return $this->requiredCapabilities;
	}

	public function riskLevel(): int {
		return $this->riskLevel;
	}

	public function requiresApproval(): bool {
		return $this->requiresApproval;
	}

	public function principalUserId(): int {
		return $this->principalUserId;
	}

	public function siteId(): ?int {
		return $this->siteId;
	}

	public function createdAt(): int {
		return $this->createdAt;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Compute deterministic content fingerprint (SHA-256).
	 *
	 * Content fingerprint depends ONLY on the plan content (objective, scope,
	 * operations in exact sequence, test strategies, capabilities, risk, approval,
	 * principal user, site ID, and canonicalized metadata). Task instance identity
	 * (taskId) and creation timestamp are deliberately excluded.
	 *
	 * Mutation operations are NEVER sorted.
	 * Associative maps within specs and metadata are canonicalized.
	 *
	 * @return string 64-character SHA-256 hex string.
	 */
	public function fingerprint(): string {
		if ( null !== $this->cachedFingerprint ) {
			return $this->cachedFingerprint;
		}

		$canonical_operations = array();
		foreach ( $this->operations as $op ) {
			$canonical_operations[] = array(
				'type' => $op['type'],
				'spec' => JsonSafeValidator::canonicalize( $op['spec'] ),
			);
		}

		$sorted_scope = $this->scope;
		sort( $sorted_scope, SORT_STRING );

		$sorted_tests = $this->testStrategies;
		sort( $sorted_tests, SORT_STRING );

		$sorted_caps = $this->requiredCapabilities;
		sort( $sorted_caps, SORT_STRING );

		$payload = array(
			'objective'             => $this->objective,
			'scope'                 => $sorted_scope,
			'operations'            => $canonical_operations,
			'test_strategies'       => $sorted_tests,
			'required_capabilities' => $sorted_caps,
			'risk_level'            => $this->riskLevel,
			'requires_approval'     => $this->requiresApproval,
			'principal_user_id'     => $this->principalUserId,
			'site_id'               => $this->siteId,
			'metadata'              => JsonSafeValidator::canonicalize( $this->metadata ),
		);

		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- fingerprint producer: plain json_encode() keeps byte-stable output across WP versions.
		$json = (string) json_encode( $payload, JSON_UNESCAPED_SLASHES );
		$this->cachedFingerprint = hash( 'sha256', $json );

		return $this->cachedFingerprint;
	}

	/**
	 * Serialize plan to associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'task_id'               => $this->taskId,
			'fingerprint'           => $this->fingerprint(),
			'objective'             => $this->objective,
			'scope'                 => $this->scope,
			'operations'            => $this->operations,
			'test_strategies'       => $this->testStrategies,
			'required_capabilities' => $this->requiredCapabilities,
			'risk_level'            => $this->riskLevel,
			'requires_approval'     => $this->requiresApproval,
			'principal_user_id'     => $this->principalUserId,
			'site_id'               => $this->siteId,
			'created_at'            => $this->createdAt,
			'metadata'              => $this->metadata,
		);
	}

	/**
	 * Reconstruct plan from array with fail-closed security recomputation.
	 *
	 * Verifies that riskLevel, requiredCapabilities, and requiresApproval
	 * have not been tampered with or downgraded. Also verifies fingerprint
	 * if present in the data array.
	 *
	 * @param array<string, mixed> $data Serialized data array.
	 * @return self
	 * @throws \InvalidArgumentException On any missing, malformed, or tampered field.
	 */
	public static function fromArray( array $data ): self {
		$required_keys = array(
			'task_id',
			'objective',
			'scope',
			'operations',
			'test_strategies',
			'required_capabilities',
			'risk_level',
			'requires_approval',
			'principal_user_id',
			'created_at',
		);

		foreach ( $required_keys as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				throw new \InvalidArgumentException( sprintf( 'Missing required key "%s" in DeveloperTaskPlan array.', $key ) );
			}
		}

		if ( ! is_string( $data['task_id'] ) || ! is_string( $data['objective'] ) ) {
			throw new \InvalidArgumentException( 'task_id and objective must be strings.' );
		}

		if ( ! is_array( $data['scope'] ) || ! is_array( $data['operations'] ) || ! is_array( $data['test_strategies'] ) || ! is_array( $data['required_capabilities'] ) ) {
			throw new \InvalidArgumentException( 'scope, operations, test_strategies, and required_capabilities must be arrays.' );
		}

		if ( ! is_int( $data['risk_level'] ) || ! is_bool( $data['requires_approval'] ) || ! is_int( $data['principal_user_id'] ) || ! is_int( $data['created_at'] ) ) {
			throw new \InvalidArgumentException( 'risk_level, requires_approval, principal_user_id, and created_at have invalid types.' );
		}

		$site_id  = isset( $data['site_id'] ) && is_int( $data['site_id'] ) ? $data['site_id'] : null;
		$metadata = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();

		// Validate each operation type explicitly against OperationRegistry.
		foreach ( $data['operations'] as $op ) {
			if ( ! is_array( $op ) || ! isset( $op['type'] ) || ! is_string( $op['type'] ) ) {
				throw new \InvalidArgumentException( 'Malformed operation entry in DeveloperTaskPlan array.' );
			}
			if ( ! OperationRegistry::isSupported( $op['type'] ) ) {
				throw new \InvalidArgumentException( sprintf( 'Operation type "%s" is not in the supported whitelist.', $op['type'] ) );
			}
		}

		// Security recomputations (fail-closed tampering checks).
		$recomputed_caps = self::deriveRequiredCapabilities( $data['operations'], $data['scope'], $data['test_strategies'] );
		foreach ( $recomputed_caps as $needed_cap ) {
			if ( ! in_array( $needed_cap, $data['required_capabilities'], true ) ) {
				throw new \InvalidArgumentException(
					sprintf( 'Tampered or insufficient required_capabilities: missing required capability "%s".', $needed_cap )
				);
			}
		}

		$recomputed_risk = self::deriveRiskLevel( $data['operations'], $data['required_capabilities'] );
		if ( $data['risk_level'] < $recomputed_risk ) {
			throw new \InvalidArgumentException(
				sprintf(
					'Tampered or downgraded risk_level: plan declared %d but content recomputes to %d.',
					$data['risk_level'],
					$recomputed_risk
				)
			);
		}

		$recomputed_approval = self::deriveRequiresApproval( $recomputed_risk );
		if ( $recomputed_approval && false === $data['requires_approval'] ) {
			throw new \InvalidArgumentException(
				'Tampered requires_approval: plan requires approval for its risk level, but requires_approval was false.'
			);
		}

		$plan = new self(
			$data['task_id'],
			$data['objective'],
			$data['scope'],
			$data['operations'],
			$data['test_strategies'],
			$data['required_capabilities'],
			$data['risk_level'],
			$data['requires_approval'],
			$data['principal_user_id'],
			$site_id,
			$data['created_at'],
			$metadata
		);

		if ( isset( $data['fingerprint'] ) && is_string( $data['fingerprint'] ) ) {
			if ( ! hash_equals( $plan->fingerprint(), $data['fingerprint'] ) ) {
				throw new \InvalidArgumentException( 'Plan fingerprint mismatch — content was modified or corrupted.' );
			}
		}

		return $plan;
	}

	/**
	 * Derive required developer capabilities from plan content.
	 *
	 * @param array<int, array<string, mixed>> $operations      Operation specs.
	 * @param string[]                         $scope           Target scope.
	 * @param string[]                         $test_strategies Test strategy IDs.
	 * @return string[] Sorted list of required capabilities.
	 */
	public static function deriveRequiredCapabilities( array $operations, array $scope = array(), array $test_strategies = array() ): array {
		$caps = array( DeveloperCapability::CREATE_PLAN );

		if ( ! empty( $scope ) ) {
			$caps[] = DeveloperCapability::INSPECT_REPO;
		}

		if ( ! empty( $test_strategies ) ) {
			$caps[] = DeveloperCapability::RUN_TEST;
		}

		foreach ( $operations as $op ) {
			$type = is_array( $op ) && isset( $op['type'] ) && is_string( $op['type'] ) ? $op['type'] : '';
			if ( str_starts_with( $type, 'file.' ) ) {
				$caps[] = DeveloperCapability::PATCH_FILE;
			}
		}

		$unique = array_values( array_unique( $caps ) );
		sort( $unique, SORT_STRING );

		return $unique;
	}

	/**
	 * Derive risk level from operations and capabilities.
	 *
	 * @param array<int, array<string, mixed>> $operations            Operation specs.
	 * @param string[]                         $required_capabilities Required capability IDs.
	 * @return int Risk level (0-4).
	 */
	public static function deriveRiskLevel( array $operations, array $required_capabilities = array() ): int {
		$max_level = PermissionEngine::LEVEL_READ;

		foreach ( $operations as $op ) {
			$type = is_array( $op ) && isset( $op['type'] ) && is_string( $op['type'] ) ? $op['type'] : '';
			$op_level = match ( $type ) {
				'file.delete' => PermissionEngine::LEVEL_DESTRUCTIVE,
				'file.create', 'file.patch' => PermissionEngine::LEVEL_SENSITIVE,
				'option.update', 'post.update_content', 'metadata.update' => PermissionEngine::LEVEL_SAFE_WRITE,
				default => PermissionEngine::LEVEL_SENSITIVE,
			};
			$max_level = max( $max_level, $op_level );
		}

		foreach ( $required_capabilities as $cap ) {
			if ( is_string( $cap ) && DeveloperCapability::isSupported( $cap ) ) {
				$max_level = max( $max_level, DeveloperCapability::levelFor( $cap ) );
			}
		}

		return $max_level;
	}

	/**
	 * Determine whether a risk level requires approval by default.
	 *
	 * @param int $risk_level The risk level.
	 * @return bool True if approval required.
	 */
	public static function deriveRequiresApproval( int $risk_level ): bool {
		return $risk_level >= PermissionEngine::LEVEL_SENSITIVE;
	}
}
