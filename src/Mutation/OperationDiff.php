<?php
/**
 * A single operation's reviewable before/after representation.
 * Immutable value object produced by DiffRenderer — never constructed
 * directly by an operation itself, so no operation can smuggle raw
 * secret content into a diff by bypassing the renderer's redaction.
 *
 * @package AIOS\Mutation
 */

declare( strict_types=1 );

namespace AIOS\Mutation;

final class OperationDiff {

	public const KIND_TEXT   = 'text';
	public const KIND_VALUE  = 'value';
	public const KIND_BINARY = 'binary_redacted';
	public const KIND_SECRET = 'redacted_value';

	/**
	 * @param array<string, mixed> $summary Safe counters (e.g. added/removed line counts, byte lengths) — never raw content.
	 */
	public function __construct(
		public readonly string $operationId,
		public readonly string $type,
		public readonly string $target,
		public readonly string $kind,
		public readonly array $summary,
		public readonly ?string $unified,
		public readonly bool $truncated,
		public readonly ?string $beforeHash,
		public readonly ?string $afterHash
	) {}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'operation_id' => $this->operationId,
			'type'         => $this->type,
			'target'       => $this->target,
			'kind'         => $this->kind,
			'summary'      => $this->summary,
			'unified'      => $this->unified,
			'truncated'    => $this->truncated,
			'before_hash'  => $this->beforeHash,
			'after_hash'   => $this->afterHash,
		);
	}
}
