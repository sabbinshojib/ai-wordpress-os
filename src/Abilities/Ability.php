<?php
/**
 * Ability — the canonical capability unit (spec §6).
 *
 * An ability is the single source of truth for one WordPress action:
 * name, description, input/output schema, permission callback and an
 * execution callback. The tool registry exposes abilities as MCP
 * tools; internal callers (REST, future agents) call abilities
 * directly, so business logic is never duplicated between surfaces.
 *
 * @package AIOS\Abilities
 */

declare( strict_types=1 );

namespace AIOS\Abilities;

use AIOS\Security\PermissionEngine;
use Closure;

final class Ability {

	/**
	 * Unique dot-notation name, e.g. "content.create_post".
	 */
	private string $name;

	private string $description;

	/**
	 * JSON-Schema subset for input (same dialect the Validator speaks).
	 *
	 * @var array<string, mixed>
	 */
	private array $inputSchema;

	/**
	 * @var array<string, mixed>
	 */
	private array $outputSchema;

	/**
	 * Required permission level 0-4.
	 */
	private int $level;

	/**
	 * Permission callback: (WP_User, array $args) → bool.
	 * Runs in addition to the level check (defense in depth).
	 */
	private Closure $permissionCallback;

	/**
	 * Execution callback: (array $args, WP_User $user) → AbilityResult.
	 */
	private Closure $executeCallback;

	/**
	 * Owning integration slug ('core' for built-ins).
	 */
	private string $integration;

	private string $version;

	/**
	 * @param array<string, mixed> $definition
	 */
	public static function make( array $definition ): self {
		$name = (string) ( $definition['name'] ?? '' );
		if ( '' === $name || ! preg_match( '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/i', $name ) ) {
			throw new \InvalidArgumentException( "Invalid ability name [{$name}]. Use dot notation, e.g. content.create_post." );
		}

		return new self(
			$name,
			(string) ( $definition['description'] ?? '' ),
			is_array( $definition['inputSchema'] ?? null ) ? $definition['inputSchema'] : array( 'type' => 'object' ),
			is_array( $definition['outputSchema'] ?? null ) ? $definition['outputSchema'] : array( 'type' => 'object' ),
			max( 0, min( 4, (int) ( $definition['level'] ?? 0 ) ) ),
			$definition['permissionCallback'] ?? static fn( $user, array $args ): bool => true,
			$definition['executeCallback'] ?? static fn( array $args, $user ): AbilityResult => AbilityResult::error( 'ability.no_callback', 'Ability has no execution callback.' ),
			(string) ( $definition['integration'] ?? 'core' ),
			(string) ( $definition['version'] ?? '1.0.0' )
		);
	}

	/**
	 * @param Closure(WP_User|null, array<string, mixed>): bool       $permissionCallback
	 * @param Closure(array<string, mixed>, WP_User|null): AbilityResult $executeCallback
	 */
	public function __construct(
		string $name,
		string $description,
		array $inputSchema,
		array $outputSchema,
		int $level,
		Closure $permissionCallback,
		Closure $executeCallback,
		string $integration = 'core',
		string $version = '1.0.0'
	) {
		$this->name              = $name;
		$this->description       = $description;
		$this->inputSchema       = $inputSchema;
		$this->outputSchema      = $outputSchema;
		$this->level             = $level;
		$this->permissionCallback = $permissionCallback;
		$this->executeCallback   = $executeCallback;
		$this->integration       = $integration;
		$this->version           = $version;
	}

	public function name(): string {
		return $this->name;
	}

	public function description(): string {
		return $this->description;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function inputSchema(): array {
		return $this->inputSchema;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function outputSchema(): array {
		return $this->outputSchema;
	}

	public function level(): int {
		return $this->level;
	}

	public function integration(): string {
		return $this->integration;
	}

	public function version(): string {
		return $this->version;
	}

	/**
	 * Fine-grained permission check in addition to the level gate.
	 *
	 * @param mixed $user WP_User or null.
	 * @param array<string, mixed> $args
	 */
	public function checkPermission( $user, array $args ): bool {
		return (bool) ( $this->permissionCallback )( $user, $args );
	}

	/**
	 * @param array<string, mixed> $args
	 */
	public function execute( array $args, $user ): AbilityResult {
		/** @var AbilityResult $result */
		$result = ( $this->executeCallback )( $args, $user );
		return $result instanceof AbilityResult ? $result : AbilityResult::error( 'ability.bad_return', 'Ability callback returned an invalid result.' );
	}

	/**
	 * Serialize for the registry listing / tools endpoints.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'name'         => $this->name,
			'description'  => $this->description,
			'inputSchema'  => $this->inputSchema,
			'outputSchema' => $this->outputSchema,
			'level'        => $this->level,
			'levelName'    => PermissionEngine::LEVEL_NAMES[ $this->level ] ?? 'unknown',
			'integration'  => $this->integration,
			'version'      => $this->version,
		);
	}
}
