<?php
/**
 * Catalog provider contract.
 *
 * Each provider registers its abilities (business logic) and tools
 * (MCP-facing metadata) with the registries.
 *
 * @package AIOS\Tools\Catalog
 */

declare( strict_types=1 );

namespace AIOS\Tools\Catalog;

use AIOS\Abilities\AbilityRegistry;
use AIOS\Tools\ToolRegistry;

interface CatalogProviderInterface {

	public static function id(): string;

	/**
	 * Whether this provider's tools can run in the current
	 * environment (checked lazily, not at registration).
	 */
	public static function isActive(): bool;

	/**
	 * Register abilities + tools.
	 */
	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void;
}
