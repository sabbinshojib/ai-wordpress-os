<?php
/**
 * The only intended upstream construction path for a ChangeSet, for
 * any future caller (Phase 3's developer capabilities, an eventual
 * AI-facing surface). Implementations accept ONLY typed, whitelisted
 * AIOS\Mutation\OperationSpecification instances — never raw shell,
 * raw SQL, an eval payload, or an arbitrary class name. This is a
 * deterministic construction boundary, not an LLM planner: it does
 * not interpret free-form natural-language requests, it validates and
 * assembles already-typed specifications into a ChangeSet.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

use AIOS\Security\PathGuard;

interface MutationPlannerInterface {

	/**
	 * @param OperationSpecification[] $operation_specifications At least one.
	 * @param array<string, mixed>     $metadata
	 *
	 * @throws \InvalidArgumentException On an empty specification list
	 *         or an invalid principal — same validation ChangeSet's own
	 *         constructor already enforces, surfaced here too so a
	 *         caller of the Planner boundary never needs to know about
	 *         ChangeSet's constructor directly.
	 * @throws MutationException Via OperationRegistry::build() for a
	 *         malformed spec (a specification whose type is valid but
	 *         whose spec fields the underlying operation constructor rejects).
	 */
	public function build(
		int $principal_user_id,
		array $operation_specifications,
		array $metadata = array(),
		?int $site_id = null,
		?PathGuard $path_guard = null
	): ChangeSet;
}
