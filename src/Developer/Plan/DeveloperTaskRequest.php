<?php
/**
 * Developer Task Request — high-level public request contract.
 *
 * Represents an external or UI-level task specification (objective, scope,
 * constraints, principal context, and metadata) without exposing raw mutation
 * operations directly.
 *
 * @package AIOS\Developer\Plan
 */

declare( strict_types=1 );

namespace AIOS\Developer\Plan;

use AIOS\Developer\Support\JsonSafeValidator;

final class DeveloperTaskRequest {

	/**
	 * Task objective description.
	 */
	private string $objective;

	/**
	 * Target file/directory scope paths.
	 *
	 * @var string[]
	 */
	private array $scope;

	/**
	 * Architectural or execution constraints.
	 *
	 * @var string[]
	 */
	private array $constraints;

	/**
	 * Acting WordPress user ID.
	 */
	private int $principalUserId;

	/**
	 * WordPress multisite blog/site ID.
	 */
	private ?int $siteId;

	/**
	 * Arbitrary JSON-safe metadata.
	 *
	 * @var array<string, mixed>
	 */
	private array $metadata;

	/**
	 * Constructor.
	 *
	 * @param string               $objective         The high-level objective of the task.
	 * @param string[]             $scope             Target scope file paths or directories.
	 * @param string[]             $constraints       Execution constraints.
	 * @param int                  $principal_user_id The acting user ID.
	 * @param int|null             $site_id           The WordPress site ID.
	 * @param array<string, mixed> $metadata          Contextual metadata.
	 * @throws \InvalidArgumentException If inputs are invalid or not JSON-safe.
	 */
	public function __construct(
		string $objective,
		array $scope = array(),
		array $constraints = array(),
		int $principal_user_id = 1,
		?int $site_id = null,
		array $metadata = array()
	) {
		$clean_objective = trim( $objective );
		if ( '' === $clean_objective ) {
			throw new \InvalidArgumentException( 'DeveloperTaskRequest objective cannot be empty.' );
		}

		if ( $principal_user_id <= 0 ) {
			throw new \InvalidArgumentException( 'DeveloperTaskRequest principal_user_id must be greater than zero.' );
		}

		if ( null !== $site_id && $site_id <= 0 ) {
			throw new \InvalidArgumentException( 'DeveloperTaskRequest site_id must be greater than zero when provided.' );
		}

		foreach ( $scope as $scope_item ) {
			if ( ! is_string( $scope_item ) ) {
				throw new \InvalidArgumentException( 'Every item in DeveloperTaskRequest scope must be a string.' );
			}
		}

		foreach ( $constraints as $constraint ) {
			if ( ! is_string( $constraint ) ) {
				throw new \InvalidArgumentException( 'Every item in DeveloperTaskRequest constraints must be a string.' );
			}
		}

		JsonSafeValidator::assertJsonSafe( $metadata, 'metadata' );

		$this->objective       = $clean_objective;
		$this->scope           = array_values( $scope );
		$this->constraints     = array_values( $constraints );
		$this->principalUserId = $principal_user_id;
		$this->siteId          = $site_id;
		$this->metadata        = $metadata;
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
	 * @return string[]
	 */
	public function constraints(): array {
		return $this->constraints;
	}

	public function principalUserId(): int {
		return $this->principalUserId;
	}

	public function siteId(): ?int {
		return $this->siteId;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function metadata(): array {
		return $this->metadata;
	}

	/**
	 * Serialize to associative array.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'objective'         => $this->objective,
			'scope'             => $this->scope,
			'constraints'       => $this->constraints,
			'principal_user_id' => $this->principalUserId,
			'site_id'           => $this->siteId,
			'metadata'          => $this->metadata,
		);
	}

	/**
	 * Reconstruct from associative array.
	 *
	 * @param array<string, mixed> $data Serialized array data.
	 * @return self
	 * @throws \InvalidArgumentException If data is missing or malformed.
	 */
	public static function fromArray( array $data ): self {
		if ( ! isset( $data['objective'] ) || ! is_string( $data['objective'] ) ) {
			throw new \InvalidArgumentException( 'Missing or invalid "objective" in DeveloperTaskRequest array.' );
		}

		$scope             = isset( $data['scope'] ) && is_array( $data['scope'] ) ? $data['scope'] : array();
		$constraints       = isset( $data['constraints'] ) && is_array( $data['constraints'] ) ? $data['constraints'] : array();
		$principal_user_id = isset( $data['principal_user_id'] ) && is_int( $data['principal_user_id'] ) ? $data['principal_user_id'] : 1;
		$site_id           = isset( $data['site_id'] ) && is_int( $data['site_id'] ) ? $data['site_id'] : null;
		$metadata          = isset( $data['metadata'] ) && is_array( $data['metadata'] ) ? $data['metadata'] : array();

		return new self(
			$data['objective'],
			$scope,
			$constraints,
			$principal_user_id,
			$site_id,
			$metadata
		);
	}
}
