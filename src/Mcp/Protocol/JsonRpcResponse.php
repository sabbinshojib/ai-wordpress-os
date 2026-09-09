<?php
/**
 * JSON-RPC 2.0 response + error codes.
 *
 * @package AIOS\Mcp\Protocol
 */

declare( strict_types=1 );

namespace AIOS\Mcp\Protocol;

final class JsonRpcResponse {

	/**
	 * Standard JSON-RPC / MCP error codes.
	 */
	public const E_PARSE            = -32700;
	public const E_INVALID_REQUEST  = -32600;
	public const E_METHOD_NOT_FOUND = -32601;
	public const E_INVALID_PARAMS   = -32602;
	public const E_INTERNAL         = -32603;

	/**
	 * MCP-specific.
	 */
	public const E_UNSUPPORTED = -32000;

	private function __construct(
		private string|int|null $id,
		private ?array $result,
		private ?array $error
	) {}

	/**
	 * @param array<string, mixed> $result
	 */
	public static function result( string|int|null $id, array $result ): self {
		return new self( $id, $result, null );
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function error( string|int|null $id, int $code, string $message, array $data = array() ): self {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);
		if ( ! empty( $data ) ) {
			$error['data'] = $data;
		}
		return new self( $id, null, $error );
	}

	/**
	 * @param array<string, mixed> $payload Full response body.
	 */
	public static function fromArray( array $payload ): self {
		$id     = $payload['id'] ?? null;
		$error  = isset( $payload['error'] ) && is_array( $payload['error'] ) ? $payload['error'] : null;
		$result = isset( $payload['result'] ) && is_array( $payload['result'] ) ? $payload['result'] : null;
		return new self( is_string( $id ) || is_int( $id ) ? $id : null, $result, $error );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		$body = array( 'jsonrpc' => '2.0' );
		if ( null !== $this->id ) {
			$body['id'] = $this->id;
		} elseif ( null !== $this->error ) {
			$body['id'] = null; // Parse errors must echo id:null.
		}
		if ( null !== $this->error ) {
			$body['error'] = $this->error;
		} else {
			$body['result'] = $this->result ?? array();
		}
		return $body;
	}

	public function isError(): bool {
		return null !== $this->error;
	}
}
