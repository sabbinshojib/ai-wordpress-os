<?php
/**
 * AbilityRegistry — the dynamic ability catalog (spec §6).
 *
 * Built-in abilities are registered by the tool catalog providers.
 * Third parties append abilities via the `ai_os_register_ability`
 * filter (spec §66). Every registered ability is validated.
 *
 * @package AIOS\Abilities
 */

declare( strict_types=1 );

namespace AIOS\Abilities;

final class AbilityRegistry {

	/**
	 * @var array<string, Ability>
	 */
	private array $abilities = array();

	private bool $sealed = false;

	/**
	 * Register an ability. Duplicate names with a higher version win;
	 * equal/lower versions are ignored (first registration wins).
	 */
	public function register( Ability $ability ): void {
		if ( $this->sealed ) {
			// Defensive: integrations should register on the filter,
			// not after boot. Fail loud in debug, silently otherwise.
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				trigger_error( 'AI OS: ability registry sealed; cannot register ' . $ability->name(), E_USER_WARNING ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_trigger_error, WordPress.Security.EscapeOutput.OutputNotEscaped -- diagnostic log channel, not HTML output
			}
			return;
		}

		$existing = $this->abilities[ $ability->name() ] ?? null;
		if ( null === $existing || version_compare( $ability->version(), $existing->version(), '>' ) ) {
			$this->abilities[ $ability->name() ] = $ability;
		}
	}

	/**
	 * Apply the third-party filter and seal the registry. Called once
	 * by the kernel after core providers registered.
	 */
	public function boot(): void {
		if ( $this->sealed ) {
			return;
		}

		/**
		 * Filter: append or replace abilities.
		 *
		 * @param Ability[] $abilities
		 */
		$extra = apply_filters( 'ai_os_register_ability', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $ability ) {
			if ( $ability instanceof Ability ) {
				$this->register( $ability );
			}
		}

		$this->sealed = true;
	}

	public function has( string $name ): bool {
		return isset( $this->abilities[ $name ] );
	}

	public function get( string $name ): ?Ability {
		return $this->abilities[ $name ] ?? null;
	}

	/**
	 * @return array<string, Ability>
	 */
	public function all(): array {
		return $this->abilities;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function toArray(): array {
		$out = array();
		foreach ( $this->abilities as $ability ) {
			$out[] = $ability->toArray();
		}
		return $out;
	}

	public function count(): int {
		return count( $this->abilities );
	}
}
