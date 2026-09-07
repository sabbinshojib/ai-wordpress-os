<?php
/**
 * A reviewable, typed unit of change: an ordered list of
 * ChangeOperationInterface instances plus the metadata Policy,
 * Approval, and Audit need to make their decisions.
 *
 * Immutable once constructed — a ChangeSet is never mutated after
 * creation; MutationEngine reads it, it never writes back into it.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Security\PermissionEngine;

final class ChangeSet {

	private string $id;

	/** @var ChangeOperationInterface[] */
	private array $operations;

	private int $principalUserId;

	private string $principalType;

	private int $siteId;

	private string $createdAt;

	/** @var array<string, mixed> */
	private array $metadata;

	/**
	 * @param ChangeOperationInterface[] $operations At least one.
	 * @param array<string, mixed>       $metadata   Free-form, non-secret context
	 *                                                (e.g. ['reason' => ..., 'source' => 'planner']).
	 * @param string|null                 $id         Internal use only — preserves the
	 *                                                original id when OperationRegistry
	 *                                                rehydrates a ChangeSet from durable
	 *                                                storage (so its fingerprint still
	 *                                                matches the one that was approved).
	 *                                                A fresh ChangeSet always omits this
	 *                                                and gets a new random id.
	 */
	public function __construct(
		int $principal_user_id,
		array $operations,
		array $metadata = array(),
		?int $site_id = null,
		string $principal_type = 'user',
		?string $id = null
	) {
		if ( array() === $operations ) {
			throw new \InvalidArgumentException( 'A ChangeSet must contain at least one operation.' );
		}
		foreach ( $operations as $operation ) {
			if ( ! $operation instanceof ChangeOperationInterface ) {
				throw new \InvalidArgumentException( 'Every ChangeSet operation must implement ChangeOperationInterface.' );
			}
		}
		if ( $principal_user_id <= 0 ) {
			throw new \InvalidArgumentException( 'A ChangeSet must have a valid principal_user_id.' );
		}

		$this->id              = $id ?? ( 'cs_' . bin2hex( random_bytes( 12 ) ) );
		$this->operations      = array_values( $operations );
		$this->principalUserId = $principal_user_id;
		$this->principalType   = $principal_type;
		$this->siteId          = $site_id ?? ( function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1 );
		$this->createdAt       = function_exists( 'current_time' ) ? (string) current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );
		$this->metadata        = $metadata;
	}

	public function id(): string {
		return $this->id;
	}

	/**
	 * @return ChangeOperationInterface[]
	 */
	public function operations(): array {
		return $this->operations;
	}

	public function principalUserId(): int {
		return $this->principalUserId;
	}

	public function principalType(): string {
		return $this->principalType;
	}

	public function siteId(): int {
		return $this->siteId;
	}

	public function createdAt(): string {
		return $this->createdAt;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * The highest risk level across every operation — this is what
	 * governs approval gating for the ChangeSet as a whole. A
	 * ChangeSet is never "partially" gated: if any one operation
	 * requires approval, the entire set does.
	 */
	public function riskLevel(): int {
		$max = PermissionEngine::LEVEL_READ;
		foreach ( $this->operations as $operation ) {
			$max = max( $max, $operation->riskLevel() );
		}
		return $max;
	}

	/**
	 * Safe, non-secret summary of every operation — for Diff/audit/
	 * approval-preview surfaces.
	 *
	 * @return array<string, mixed>
	 */
	public function describe(): array {
		return array(
			'id'               => $this->id,
			'principal_user_id' => $this->principalUserId,
			'principal_type'   => $this->principalType,
			'site_id'          => $this->siteId,
			'created_at'       => $this->createdAt,
			'risk_level'       => $this->riskLevel(),
			'metadata'         => $this->metadata,
			'operations'       => array_map(
				static fn( ChangeOperationInterface $operation ): array => array(
					'id'     => $operation->id(),
					'type'   => $operation->type(),
					'target' => $operation->target(),
					'risk'   => $operation->riskLevel(),
					'detail' => $operation->describe(),
				),
				$this->operations
			),
		);
	}
}
