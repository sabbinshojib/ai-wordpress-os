<?php
/**
 * The concrete, deterministic MutationPlannerInterface implementation.
 * Every operation is constructed exclusively via
 * OperationRegistry::build() — the same closed whitelist durable
 * rehydration uses — so this class inherits the identical guarantee:
 * no dynamic class instantiation from a type string, no unsupported
 * operation type, no malformed spec reaches a ChangeSet.
 *
 * Not wired to any AI-facing tool, REST endpoint, or MCP surface.
 * Phase 3 (not yet built) is expected to be the first real caller.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Security\PathGuard;

final class TypedChangeSetBuilder implements MutationPlannerInterface {

	public function build(
		int $principal_user_id,
		array $operation_specifications,
		array $metadata = array(),
		?int $site_id = null,
		?PathGuard $path_guard = null
	): ChangeSet {
		if ( array() === $operation_specifications ) {
			throw new \InvalidArgumentException( 'At least one operation specification is required.' );
		}

		$operations = array();
		foreach ( $operation_specifications as $specification ) {
			if ( ! $specification instanceof OperationSpecification ) {
				throw new \InvalidArgumentException( 'Every entry must be an OperationSpecification — no other shape is accepted.' );
			}
			$operations[] = OperationRegistry::build( $specification->type, $specification->spec, $path_guard );
		}

		return new ChangeSet( $principal_user_id, $operations, $metadata, $site_id );
	}
}
