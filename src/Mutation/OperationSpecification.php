<?php
/**
 * A typed, whitelisted request for one operation — the only shape
 * TypedChangeSetBuilder accepts. Validates its type against
 * OperationRegistry::SUPPORTED_TYPES at construction, so a caller can
 * never smuggle an unsupported/unknown operation type past the
 * Planner boundary even before OperationRegistry itself would reject it.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class OperationSpecification {

	/**
	 * @param array<string, mixed> $spec Same shape as the operation's own toSpec()/OperationRegistry::build() expects.
	 */
	public function __construct(
		public readonly string $type,
		public readonly array $spec
	) {
		if ( ! OperationRegistry::isSupported( $type ) ) {
			throw new \InvalidArgumentException( sprintf( 'Operation type "%s" is not in the supported whitelist.', $type ) );
		}
	}
}
