<?php
/**
 * Tool — an MCP-exposable unit of capability (spec §5).
 *
 * A tool wraps an ability with MCP-facing metadata:
 *   name, description, category, input/output schema, required
 *   permission level, risk level, confirmation policy, callback,
 *   integration, version, availability condition.
 *
 * In Phase 1 every tool delegates to an ability of the same name,
 * keeping business logic in exactly one place (spec §6).
 *
 * @package AIOS\Tools
 */

declare( strict_types=1 );

namespace AIOS\Tools;

use AIOS\Abilities\Ability;
use AIOS\Security\PermissionEngine;
use Closure;

final class Tool {

	/**
	 * Risk/permission levels 0-4 (mirror of PermissionEngine).
	 */
	public const RISK_LOW        = 0;
	public const RISK_MEDIUM     = 1;
	public const RISK_SENSITIVE  = 2;
	public const RISK_HIGH       = 3;
	public const RISK_DEPLOYMENT = 4;

	/**
	 * Confirmation policies:
	 *   'never'    — execute when permission allows
	 *   'approval' — require a human approval record first
	 */
	public const CONFIRM_NEVER    = 'never';
	public const CONFIRM_APPROVAL = 'approval';

	private string $name;
	private string $description;
	private string $category;

	/**
	 * JSON-Schema subset (Validator dialect).
	 *
	 * @var array<string, mixed>
	 */
	private array $inputSchema;

	/**
	 * @var array<string, mixed>
	 */
	private array $outputSchema;

	/**
	 * Required permission level (0-4).
	 */
	private int $permissionLevel;

	/**
	 * Risk level (0-4) — drives the approval gate and audit display.
	 */
	private int $riskLevel;

	private string $confirmation;

	/**
	 * Underlying ability name (usually identical to tool name).
	 */
	private string $abilityName;

	private string $integration;
	private string $version;

	/**
	 * Availability condition: () → bool. Tools whose condition fails
	 * are hidden from tools/list (e.g. WooCommerce tools when WC is
	 * not active).
	 */
	private Closure $available;

	/**
	 * @param array<string, mixed> $definition
	 */
	public static function make( array $definition ): self {
		$name = (string) ( $definition['name'] ?? '' );
		if ( '' === $name || ! preg_match( '/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)+$/i', $name ) ) {
			throw new \InvalidArgumentException( "Invalid tool name [{$name}]. Use dot notation, e.g. content.create_post." );
		}

		return new self(
			$name,
			(string) ( $definition['description'] ?? '' ),
			(string) ( $definition['category'] ?? 'general' ),
			is_array( $definition['inputSchema'] ?? null ) ? $definition['inputSchema'] : array( 'type' => 'object' ),
			is_array( $definition['outputSchema'] ?? null ) ? $definition['outputSchema'] : array( 'type' => 'object' ),
			max( 0, min( 4, (int) ( $definition['permissionLevel'] ?? $definition['riskLevel'] ?? 0 ) ) ),
			max( 0, min( 4, (int) ( $definition['riskLevel'] ?? $definition['permissionLevel'] ?? 0 ) ) ),
			in_array( (string) ( $definition['confirmation'] ?? self::CONFIRM_APPROVAL ), array( self::CONFIRM_NEVER, self::CONFIRM_APPROVAL ), true )
				? (string) ( $definition['confirmation'] ?? self::CONFIRM_APPROVAL )
				: self::CONFIRM_APPROVAL,
			(string) ( $definition['ability'] ?? $name ),
			(string) ( $definition['integration'] ?? 'core' ),
			(string) ( $definition['version'] ?? '1.0.0' ),
			$definition['available'] ?? static fn(): bool => true
		);
	}

	/**
	 * @param Closure(): bool $available
	 */
	public function __construct(
		string $name,
		string $description,
		string $category,
		array $inputSchema,
		array $outputSchema,
		int $permissionLevel,
		int $riskLevel,
		string $confirmation,
		string $abilityName,
		string $integration = 'core',
		string $version = '1.0.0',
		?Closure $available = null
	) {
		$this->name            = $name;
		$this->description     = $description;
		$this->category        = $category;
		$this->inputSchema     = $inputSchema;
		$this->outputSchema    = $outputSchema;
		$this->permissionLevel = $permissionLevel;
		$this->riskLevel       = $riskLevel;
		$this->confirmation    = $confirmation;
		$this->abilityName     = $abilityName;
		$this->integration     = $integration;
		$this->version         = $version;
		$this->available       = $available ?? static fn(): bool => true;
	}

	public function name(): string {
		return $this->name;
	}

	public function description(): string {
		return $this->description;
	}

	public function category(): string {
		return $this->category;
	}

	public function inputSchema(): array {
		return $this->inputSchema;
	}

	public function outputSchema(): array {
		return $this->outputSchema;
	}

	public function permissionLevel(): int {
		return $this->permissionLevel;
	}

	public function riskLevel(): int {
		return $this->riskLevel;
	}

	public function riskName(): string {
		return PermissionEngine::LEVEL_NAMES[ $this->riskLevel ] ?? 'unknown';
	}

	public function confirmation(): string {
		return $this->confirmation;
	}

	public function requiresApproval(): bool {
		return self::CONFIRM_APPROVAL === $this->confirmation;
	}

	public function abilityName(): string {
		return $this->abilityName;
	}

	public function integration(): string {
		return $this->integration;
	}

	public function version(): string {
		return $this->version;
	}

	public function isAvailable(): bool {
		return (bool) ( $this->available )();
	}

	/**
	 * MCP tools/list entry (spec §30: meaningful high-level tools).
	 *
	 * @return array<string, mixed>
	 */
	public function toMcpSchema(): array {
		return array(
			'name'        => $this->name,
			'description' => $this->description,
			'inputSchema' => $this->inputSchema,
		);
	}

	/**
	 * Full metadata entry for the REST catalog + admin UI.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'name'            => $this->name,
			'description'     => $this->description,
			'category'        => $this->category,
			'inputSchema'     => $this->inputSchema,
			'outputSchema'    => $this->outputSchema,
			'permissionLevel' => $this->permissionLevel,
			'levelName'       => PermissionEngine::LEVEL_NAMES[ $this->permissionLevel ] ?? 'unknown',
			'riskLevel'       => $this->riskLevel,
			'riskName'        => $this->riskName(),
			'confirmation'    => $this->confirmation,
			'ability'         => $this->abilityName,
			'integration'     => $this->integration,
			'version'         => $this->version,
			'available'       => $this->isAvailable(),
		);
	}
}
