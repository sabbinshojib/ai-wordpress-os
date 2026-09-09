<?php
/**
 * JSON-RPC 2.0 value objects (spec §4, MCP protocol).
 *
 * @package AIOS\Mcp\Protocol
 */

declare( strict_types=1 );

namespace AIOS\Mcp\Protocol;

/**
 * JSON-RPC request (call or notification).
 */
final class JsonRpcRequest {

	private bool $is_notification;

		/**
		 * JSON-RPC 2.0 request envelope as parsed from the transport.
		 * ids may be string, int or null (notification).
		 *
		 * @param array<string, mixed>|null $params
		 */
	public function __construct(
		private string|int|null $id,
		private string $method,
		private ?array $params
	) {
			$this->is_notification = null === $id;
	}

		/**
		 * Parse a decoded JSON body into a request. Returns null when the
		 * envelope is not a valid JSON-RPC request (batch arrays return
		 * a collection through JsonRpc::parse).
		 *
		 * @param mixed $decoded
		 */
	public static function fromDecoded( mixed $decoded ): ?self {
		if ( ! is_array( $decoded ) || array_is_list( $decoded ) ) {
				return null;
		}

			$version = $decoded['jsonrpc'] ?? null;
			$method  = $decoded['method'] ?? null;

		if ( '2.0' !== $version || ! is_string( $method ) || '' === $method ) {
				return null;
		}

			$id = $decoded['id'] ?? null;
		if ( null !== $id && ! is_string( $id ) && ! is_int( $id ) ) {
				return null; // Float/bool/object ids are invalid per spec.
		}

			$params = $decoded['params'] ?? null;
		if ( null !== $params && ! is_array( $params ) ) {
				return null;
		}

			return new self( $id, $method, $params );
	}

	public function id(): ?string {
			return is_string( $this->id ) ? $this->id : ( null === $this->id ? null : (string) $this->id );
	}

	public function idAsInt(): ?int {
			return is_numeric( $this->id ) ? (int) $this->id : null;
	}

	public function rawId(): string|int|float|null {
			return $this->id;
	}

	public function method(): string {
			return $this->method;
	}

		/**
		 * @return array<string, mixed>|null
		 */
	public function params(): ?array {
			return $this->params;
	}

		/**
		 * @param mixed $fallback
		 */
	public function param( string $key, mixed $fallback = null ): mixed {
			return $this->params[ $key ] ?? $fallback;
	}

	public function isNotification(): bool {
			return $this->is_notification;
	}
}
