<?php
/**
 * Minimal PSR-11-style service container.
 *
 * Supports:
 *   - singleton bindings (shared instances)
 *   - factory bindings (new instance per resolve)
 *   - concrete class auto-resolution via reflection
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

use Closure;
use Throwable;

final class Container {

	/**
	 * Singleton instances, keyed by binding id.
	 *
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Factories / resolvers, keyed by binding id.
	 *
	 * @var array<string, Closure>
	 */
	private array $bindings = array();

	/**
	 * ids marked as shared (singleton).
	 *
	 * @var array<string, bool>
	 */
	private array $shared = array();

	/**
	 * Bind a resolver. Ids are arbitrary strings, by convention the
	 * fully-qualified class name of the resolved service.
	 */
	public function bind( string $id, Closure $resolver, bool $share = true ): void {
		$this->bindings[ $id ] = $resolver;
		$this->shared[ $id ]   = $share;
		unset( $this->instances[ $id ] );
	}

	/**
	 * Register an already-constructed instance.
	 */
	public function instance( string $id, object $service ): void {
		$this->instances[ $id ] = $service;
		$this->shared[ $id ]    = true;
	}

	/**
	 * Whether the container can resolve the id.
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) || isset( $this->instances[ $id ] ) || class_exists( $id );
	}

	/**
	 * Resolve a service.
	 *
	 * Order: cached instance → binding → reflection auto-resolution.
	 *
	 * @throws ContainerException When the id cannot be resolved.
	 */
	public function get( string $id ): mixed {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( isset( $this->bindings[ $id ] ) ) {
			$resolved = ( $this->bindings[ $id ] )( $this );
			if ( $this->shared[ $id ] ?? false ) {
				$this->instances[ $id ] = $resolved;
			}
			return $resolved;
		}

		if ( class_exists( $id ) ) {
			return $this->build( $id );
		}

		throw new ContainerException( "Unable to resolve [{$id}]: no binding and no such class." );
	}

	/**
	 * Reflection-based auto-resolution for unbound concrete classes.
	 *
	 * Only type-hinted, class-typed constructor parameters are injected;
	 * everything with a default is left to the default; anything else
	 * fails explicitly.
	 */
	private function build( string $class_name ): object {
		try {
			$reflector = new \ReflectionClass( $class_name );
		} catch ( \ReflectionException $e ) {
			throw new ContainerException( "Class [{$class_name}] does not exist." );
		}

		if ( ! $reflector->isInstantiable() ) {
			throw new ContainerException( "Class [{$class_name}] is not instantiable." );
		}

		$constructor = $reflector->getConstructor();
		if ( null === $constructor || 0 === $constructor->getNumberOfParameters() ) {
			return new $class_name();
		}

		$arguments = array();
		foreach ( $constructor->getParameters() as $parameter ) {
			$type = $parameter->getType();

			if ( $parameter->isDefaultValueAvailable() ) {
				$arguments[] = $parameter->getDefaultValue();
				continue;
			}

			if ( $type instanceof \ReflectionNamedType && ! $type->isBuiltin() && class_exists( $type->getName() ) ) {
				$arguments[] = $this->get( $type->getName() );
				continue;
			}

			throw new ContainerException(
				sprintf(
					'Cannot auto-resolve parameter $%s of %s::__construct().',
					$parameter->getName(),
					$class_name
				)
			);
		}

		return $reflector->newInstanceArgs( $arguments );
	}

	/**
	 * Forget a resolved singleton (used by tests).
	 */
	public function forget( string $id ): void {
		unset( $this->instances[ $id ], $this->bindings[ $id ], $this->shared[ $id ] );
	}
}
