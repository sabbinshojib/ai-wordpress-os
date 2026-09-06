<?php
/**
 * Service provider contract.
 *
 * @package AIOS\Core
 */

declare( strict_types=1 );

namespace AIOS\Core;

interface ServiceProviderInterface {

	/**
	 * Register bindings on the container. No service may be *resolved*
	 * in this phase — only bound — so boot order stays cheap.
	 */
	public function register( Container $container ): void;

	/**
	 * Boot the provider. Runs after every provider registered. Safe to
	 * resolve services and add WordPress hooks here.
	 */
	public function boot( Container $container ): void;
}
