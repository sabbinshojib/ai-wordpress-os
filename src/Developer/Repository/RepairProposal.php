<?php
/**
 * Repair Proposal — structured, non-binding suggestion.
 *
 * This is deliberately NOT an executable unit of change. It carries no
 * `type`/`spec` shape compatible with AIOS\Mutation\OperationSpecification
 * and no method here ever constructs or returns one — applying an actual
 * fix requires a separate, later, human-authored/approved mutation task
 * (P3-B Non-goals). This class must not import or reference
 * AIOS\Mutation\OperationSpecification.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class RepairProposal {

	public const CONFIDENCE_LOW    = 'low';
	public const CONFIDENCE_MEDIUM = 'medium';

	/**
	 * @var string[]
	 */
	public const CONFIDENCE_LEVELS = array( self::CONFIDENCE_LOW, self::CONFIDENCE_MEDIUM );

	private string $summary;

	private ?string $targetFile;

	private ?int $targetLine;

	private string $suggestedApproach;

	private string $confidence;

	private string $rationale;

	private bool $actionable;

	public function __construct(
		string $summary,
		?string $target_file,
		?int $target_line,
		string $suggested_approach,
		string $confidence,
		string $rationale,
		bool $actionable
	) {
		if ( '' === trim( $summary ) ) {
			throw new \InvalidArgumentException( 'RepairProposal summary cannot be empty.' );
		}
		if ( ! in_array( $confidence, self::CONFIDENCE_LEVELS, true ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unsupported RepairProposal confidence "%s".', $confidence ) );
		}
		if ( null !== $target_line && $target_line < 1 ) {
			throw new \InvalidArgumentException( 'RepairProposal target_line must be a positive integer when provided.' );
		}

		$this->summary           = trim( $summary );
		$this->targetFile        = $target_file;
		$this->targetLine        = $target_line;
		$this->suggestedApproach = trim( $suggested_approach );
		$this->confidence        = $confidence;
		$this->rationale         = trim( $rationale );
		$this->actionable        = $actionable;
	}

	public function summary(): string {
		return $this->summary;
	}

	public function targetFile(): ?string {
		return $this->targetFile;
	}

	public function targetLine(): ?int {
		return $this->targetLine;
	}

	public function suggestedApproach(): string {
		return $this->suggestedApproach;
	}

	public function confidence(): string {
		return $this->confidence;
	}

	public function rationale(): string {
		return $this->rationale;
	}

	/**
	 * Whether this proposal points at a specific, actionable location.
	 * Always false for a proposal derived from an unparseable diagnosis.
	 */
	public function actionable(): bool {
		return $this->actionable;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'summary'            => $this->summary,
			'target_file'        => $this->targetFile,
			'target_line'        => $this->targetLine,
			'suggested_approach' => $this->suggestedApproach,
			'confidence'         => $this->confidence,
			'rationale'          => $this->rationale,
			'actionable'         => $this->actionable,
		);
	}
}
