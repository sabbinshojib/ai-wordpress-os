<?php
/**
 * ToolRegistry — the dynamic tool catalog (spec §5).
 *
 * Core tools are registered by the Catalog providers; integrations
 * append tools via the `ai_os_register_tool` filter (spec §66).
 * The registry never hard-codes availability: each tool carries its
 * own availability condition.
 *
 * @package AIOS\Tools
 */

declare( strict_types=1 );

namespace AIOS\Tools;

final class ToolRegistry {

	/**
	 * @var array<string, Tool>
	 */
	private array $tools = array();

	private bool $booted = false;

	/**
	 * @param Tool[] $tools
	 */
	public function registerMany( array $tools ): void {
		foreach ( $tools as $tool ) {
			if ( $tool instanceof Tool ) {
				$this->register( $tool );
			}
		}
	}

	public function register( Tool $tool ): void {
		if ( $this->booted ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				trigger_error( 'AI OS: tool registry booted; cannot register ' . $tool->name(), E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error
			}
			return;
		}

		$existing = $this->tools[ $tool->name() ] ?? null;
		if ( null === $existing || version_compare( $tool->version(), $existing->version(), '>' ) ) {
			$this->tools[ $tool->name() ] = $tool;
		}
	}

	/**
	 * Apply third-party tools and seal.
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		/**
		 * Filter: append or replace tools.
		 *
		 * @param Tool[] $tools
		 */
		$extra = apply_filters( 'ai_os_register_tool', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $tool ) {
			if ( $tool instanceof Tool ) {
				$this->register( $tool );
			}
		}

		$this->booted = true;

		/**
		 * Action: registry is sealed — integration adapters may now
		 * inspect the final catalog (e.g. for diagnostics).
		 */
		do_action( 'ai_os_tools_booted', $this );
	}

	public function has( string $name ): bool {
		return isset( $this->tools[ $name ] );
	}

	public function get( string $name ): ?Tool {
		return $this->tools[ $name ] ?? null;
	}

	/**
	 * All tools (including unavailable ones — used by the admin UI).
	 *
	 * @return array<string, Tool>
	 */
	public function all(): array {
		return $this->tools;
	}

	/**
	 * Available tools only (MCP tools/list).
	 *
	 * @return Tool[]
	 */
	public function available(): array {
		return array_values( array_filter( $this->tools, static fn( Tool $tool ): bool => $tool->isAvailable() ) );
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray( bool $only_available = false ): array {
		$out = array();
		foreach ( $this->tools as $tool ) {
			if ( $only_available && ! $tool->isAvailable() ) {
				continue;
			}
			$out[] = $tool->toArray();
		}
		return $out;
	}

	public function count(): int {
		return count( $this->tools );
	}

	public function countAvailable(): int {
		return count( $this->available() );
	}

	/**
	 * Category listing for the admin tools screen.
	 *
	 * @return array<string, Tool[]>
	 */
	public function byCategory(): array {
		$grouped = array();
		foreach ( $this->tools as $tool ) {
			$grouped[ $tool->category() ][] = $tool;
		}
		ksort( $grouped );
		return $grouped;
	}
}
