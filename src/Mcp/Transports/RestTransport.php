<?php
/**
 * Streamable HTTP transport (MCP 2025-03-26/2025-06-18) over a
 * WordPress REST route: POST /wp-json/ai-os/v1/mcp.
 *
 * Stateless profile: every request is self-contained (auth headers
 * each time), no session server state. Responses are plain
 * application/json. GET/DELETE → 405 per the stateless profile.
 *
 * @package AIOS\Mcp\Transports
 */

declare( strict_types=1 );

namespace AIOS\Mcp\Transports;

use AIOS\Mcp\Protocol\JsonRpcRequest;
use AIOS\Mcp\Protocol\JsonRpcResponse;
use AIOS\Mcp\Server;
use AIOS\Security\Authenticator;
use AIOS\Security\PermissionEngine;
use AIOS\Settings\Settings;
use AIOS\Support\StructuredError;
use AIOS\Tools\ToolExecutor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RestTransport {

        public const ROUTE = '/mcp';

        private Server $server;

        private Settings $settings;

        private ToolExecutor $executor;

        public function __construct( Server $server, Settings $settings, ToolExecutor $executor ) {
                $this->server    = $server;
                $this->settings  = $settings;
                $this->executor  = $executor;
        }

        /**
         * Register transport routes.
         */
        public function register( string $namespace ): void {
                register_rest_route(
                        $namespace,
                        self::ROUTE,
                        array(
                                array(
                                        'methods'             => 'POST',
                                        'callback'            => array( $this, 'handle' ),
                                        'permission_callback' => array( $this, 'permission' ),
                                ),
                                array(
                                        'methods'             => 'GET',
                                        'callback'            => static fn(): WP_REST_Response => new WP_REST_Response(
                                                array(
                                                        'error' => array(
                                                                'code'    => JsonRpcResponse::E_METHOD_NOT_FOUND,
                                                                'message' => 'This MCP endpoint is stateless: use POST with JSON-RPC 2.0 bodies.',
                                                        ),
                                                ),
                                                405,
                                                array( 'Allow' => 'POST' )
                                        ),
                                        'permission_callback' => '__return_true',
                                ),
                        )
                );
        }

        /**
         * Permission callback: runs BEFORE the JSON-RPC layer so we can
         * enforce HTTPS and resolve the principal cleanly. A REST
         * authentication error short-circuits with a proper 401.
         *
         * @return bool|WP_Error
         */
        public function permission( WP_REST_Request $request ) {
                if ( ! $this->settings->mcpEnabled() ) {
                        return new WP_Error(
                                'ai_os_mcp_disabled',
                                'The MCP server is disabled in AI OS settings.',
                                array( 'status' => 503 )
                        );
                }

                if ( $this->settings->requireHttps() && ! is_ssl() && ! $this->isLocalDevelopment() ) {
                        return new WP_Error(
                                'ai_os_https_required',
                                'The MCP endpoint requires HTTPS. Enable TLS or set require_https=false for local development only.',
                                array( 'status' => 400 )
                        );
                }

                $user = $this->resolveUser( $request );
                if ( null === $user ) {
                        return new WP_Error(
                                'ai_os_unauthenticated',
                                'Authenticate with a WordPress Application Password (HTTP Basic) or an AI OS API Key (X-AI-OS-Key header).',
                                array( 'status' => 401 )
                        );
                }

                if ( ! PermissionEngine::canUse( $user ) ) {
                        return new WP_Error(
                                'ai_os_forbidden',
                                'This account is not permitted to use AI WordPress OS.',
                                array( 'status' => 403 )
                        );
                }

                return true;
        }

        /**
         * POST handler: parse → dispatch → render.
         */
        public function handle( WP_REST_Request $request ): WP_REST_Response {
                $user = $this->resolveUser( $request );
                if ( null === $user ) {
                        // permission() already covers this; belt and suspenders.
                        return $this->jsonResponse(
                                JsonRpcResponse::error( null, JsonRpcResponse::E_INVALID_REQUEST, 'Unauthenticated.' ),
                                401
                        );
                }

                $rate = $this->rateLimit( $user );
                if ( null !== $rate ) {
                        return $rate;
                }

                $body   = $request->get_body();
                $decoded = json_decode( (string) $body, true );

                if ( JSON_ERROR_NONE !== json_last_error() ) {
                        return $this->jsonResponse(
                                JsonRpcResponse::error( null, JsonRpcResponse::E_PARSE, 'Parse error: malformed JSON.' ),
                                200 // JSON-RPC errors return 200 with the error envelope.
                        );
                }

                // Batch support (JSON-RPC 2.0): a non-empty list of requests.
                if ( is_array( $decoded ) && array_is_list( $decoded ) ) {
                        return $this->handleBatch( $decoded, $user );
                }

                $parsed = JsonRpcRequest::fromDecoded( $decoded );
                if ( null === $parsed ) {
                        return $this->jsonResponse(
                                JsonRpcResponse::error( null, JsonRpcResponse::E_INVALID_REQUEST, 'Invalid Request: not a JSON-RPC 2.0 envelope.' ),
                                200
                        );
                }

                $response = $this->server->dispatch( $parsed, $user, 'mcp' );

                if ( null === $response ) {
                        // Notification: 202 Accepted, empty body.
                        return new WP_REST_Response( null, 202 );
                }

                return $this->jsonResponse( $response, 200 );
        }

        /**
         * @param array<int, mixed> $batch
         */
        private function handleBatch( array $batch, $user ): WP_REST_Response {
                $responses = array();

                if ( array() === $batch ) {
                        return $this->jsonResponse(
                                JsonRpcResponse::error( null, JsonRpcResponse::E_INVALID_REQUEST, 'Invalid Request: empty batch.' ),
                                200
                        );
                }

                foreach ( array_slice( $batch, 0, 32 ) as $entry ) { // bound batch size
                        $parsed = JsonRpcRequest::fromDecoded( $entry );
                        if ( null === $parsed ) {
                                $responses[] = JsonRpcResponse::error( null, JsonRpcResponse::E_INVALID_REQUEST, 'Invalid Request.' )->toArray();
                                continue;
                        }
                        $response = $this->server->dispatch( $parsed, $user, 'mcp' );
                        if ( null !== $response ) {
                                $responses[] = $response->toArray();
                        }
                }

                if ( array() === $responses ) {
                        return new WP_REST_Response( null, 202 ); // all notifications.
                }

                return new WP_REST_Response( $responses, 200 );
        }

        /**
         * Resolve principal: X-AI-OS-Key header first, then WordPress
         * REST auth state (application password / cookie+nonce), which
         * core has already applied to the current user by the time the
         * permission callback runs.
         *
         * @return \WP_User|null
         */
        private function resolveUser( WP_REST_Request $request ): ?object {
                $keys = \AIOS\Core\Plugin::instance()?->container()?->get( \AIOS\Database\Repositories\ApiKeyRepository::class );
                if ( null === $keys ) {
                        $current = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
                        return ( $current instanceof \WP_User && $current->exists() ) ? $current : null;
                }

                $auth       = new Authenticator( $keys, $this->settings, 'mcp' );
                $core_user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
                $core_user  = ( $core_user instanceof \WP_User && $core_user->exists() ) ? $core_user : null;

                if ( $auth->resolve( $core_user ) ) {
                        return $auth->user();
                }
                return null;
        }

        /**
         * Rolling-window rate limit for MCP requests (per user).
         */
        private function rateLimit( $user ): ?WP_REST_Response {
                $limiter = \AIOS\Core\Plugin::instance()?->container()?->get( \AIOS\Security\RateLimiter::class );
                if ( null === $limiter ) {
                        return null;
                }

                $limiter->configure( $this->settings->rateLimitRequests() );
                $principal = 'user:' . (int) $user->ID;
                if ( ! $limiter->allow( $principal, 'mcp' ) ) {
                        $error = StructuredError::rateLimit( 'MCP request rate limit exceeded.', $limiter->meta( $principal, 'mcp' ) );
                        return new WP_REST_Response(
                                array( 'error' => $error->toArray() ),
                                429,
                                array( 'Retry-After' => (string) max( 1, $limiter->meta( $principal, 'mcp' )['retry_after'] ) )
                        );
                }
                return null;
        }

        private function jsonResponse( JsonRpcResponse $response, int $status ): WP_REST_Response {
                $wp_response = new WP_REST_Response( $response->toArray(), $status );
                $wp_response->header( 'Content-Type', 'application/json' );
                return $wp_response;
        }

        /**
         * Local development exceptions (localhost / playground hosts).
         */
        private function isLocalDevelopment(): bool {
                $host = strtolower( (string) ( $_SERVER['HTTP_HOST'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                return '' === $host
                        || str_contains( $host, 'localhost' )
                        || str_contains( $host, '127.0.0.1' )
                        || str_contains( $host, '.local' )
                        || str_contains( $host, '.test' )
                        || ( defined( 'WP_ENVIRONMENT_TYPE' ) && 'local' === WP_ENVIRONMENT_TYPE );
        }
}
