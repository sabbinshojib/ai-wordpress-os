<?php
/**
 * REST API registration (spec §40): namespace ai-os/v1.
 *
 * Every endpoint has a permission callback, validation and
 * sanitization. Controllers resolve services from the container.
 *
 * @package AIOS\Rest
 */

declare( strict_types=1 );

namespace AIOS\Rest;

use AIOS\Core\Container;
use AIOS\Mcp\Transports\RestTransport;
use AIOS\Rest\Controllers\ApprovalsController;
use AIOS\Rest\Controllers\CapabilitiesController;
use AIOS\Rest\Controllers\ContextController;
use AIOS\Rest\Controllers\KeysController;
use AIOS\Rest\Controllers\LogsController;
use AIOS\Rest\Controllers\SettingsController;
use AIOS\Rest\Controllers\SiteController;
use AIOS\Rest\Controllers\ToolsController;

final class RestApi {

	private Container $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	public function hook(): void {
		add_action( 'rest_api_init', array( $this, 'registerRoutes' ) );
	}

	public function registerRoutes(): void {
		$namespace = AI_WP_OS_REST_NAMESPACE;

		( new SiteController( $this->container ) )->register( $namespace );
		( new ToolsController( $this->container ) )->register( $namespace );
		( new ApprovalsController( $this->container ) )->register( $namespace );
		( new LogsController( $this->container ) )->register( $namespace );
		( new ContextController( $this->container ) )->register( $namespace );
		( new SettingsController( $this->container ) )->register( $namespace );
		( new KeysController( $this->container ) )->register( $namespace );
		( new CapabilitiesController( $this->container ) )->register( $namespace );

		/** @var RestTransport $transport */
		$transport = $this->container->get( RestTransport::class );
		$transport->register( $namespace );
	}
}
