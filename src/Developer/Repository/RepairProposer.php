<?php
/**
 * Repair Proposer — turns a FailureDiagnosis into a non-binding RepairProposal.
 *
 * Implements DeveloperCapability::PROPOSE_REPAIR (P3-B, T-113). Produces
 * descriptive, structured suggestions only — never an executable mutation.
 * See RepairProposal's docblock for the explicit boundary against
 * AIOS\Mutation\OperationSpecification.
 *
 * @package AIOS\Developer\Repository
 */

declare( strict_types=1 );

namespace AIOS\Developer\Repository;

final class RepairProposer {

	public function propose( FailureDiagnosis $diagnosis ): RepairProposal {
		if ( $diagnosis->isUnparseable() ) {
			return new RepairProposal(
				'Unable to determine a repair: diagnostic output was unparseable.',
				null,
				null,
				'Manual investigation required — re-run with a supported output format (PHPUnit default text reporter, PHPStan --error-format=raw, or PHPCS default/full report) and re-diagnose.',
				RepairProposal::CONFIDENCE_LOW,
				'FailureDiagnoser could not extract a file/line/category from the supplied payload.',
				false
			);
		}

		$has_location = null !== $diagnosis->file() && null !== $diagnosis->line();
		$confidence   = $has_location ? RepairProposal::CONFIDENCE_MEDIUM : RepairProposal::CONFIDENCE_LOW;

		$summary = sprintf(
			'%s in %s',
			$this->categoryLabel( $diagnosis->category() ),
			$has_location ? sprintf( '%s:%d', $diagnosis->file(), $diagnosis->line() ) : 'an unlocated file'
		);

		$approach = match ( $diagnosis->category() ) {
			FailureDiagnosis::CATEGORY_PHPUNIT_FAILURE => 'Review the failing assertion and the code path under test; a fix requires a separate, approved mutation task — this proposal does not apply one.',
			FailureDiagnosis::CATEGORY_PHPSTAN_ERROR    => 'Review the reported type/analysis error at the given location; a fix requires a separate, approved mutation task — this proposal does not apply one.',
			FailureDiagnosis::CATEGORY_PHPCS_VIOLATION  => 'Review the reported coding-standard violation at the given location; a fix requires a separate, approved mutation task — this proposal does not apply one.',
			default                                     => 'Manual investigation required.',
		};

		return new RepairProposal(
			$summary,
			$diagnosis->file(),
			$diagnosis->line(),
			$approach,
			$confidence,
			sprintf( 'Derived from a %s diagnosis: %s', $diagnosis->sourceTool(), $diagnosis->message() ),
			$has_location
		);
	}

	private function categoryLabel( string $category ): string {
		return match ( $category ) {
			FailureDiagnosis::CATEGORY_PHPUNIT_FAILURE => 'A PHPUnit test failure',
			FailureDiagnosis::CATEGORY_PHPSTAN_ERROR    => 'A PHPStan analysis error',
			FailureDiagnosis::CATEGORY_PHPCS_VIOLATION  => 'A PHPCS coding-standard violation',
			default                                     => 'An unclassified diagnostic finding',
		};
	}
}
