<?php
/**
 * Unit tests: JSON-Schema subset validator + JSON-RPC envelope.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Mcp\Protocol\JsonRpcRequest;
use AIOS\Mcp\Protocol\JsonRpcResponse;
use AIOS\Support\Validator;
use AIOS\Tests\TestCase;

final class ValidatorTest extends TestCase {

	private Validator $validator;

	protected function setUp(): void {
		$this->validator = new Validator();
	}

	public function test_type_checking(): void {
		$this->assertCount( 0, $this->validator->validate( 'hello', array( 'type' => 'string' ) ) );
		$this->assertCount( 0, $this->validator->validate( 5, array( 'type' => 'integer' ) ) );
		$this->assertCount( 0, $this->validator->validate( 5.0, array( 'type' => 'integer' ) ) );
		$this->assertCount( 0, $this->validator->validate( 5.5, array( 'type' => 'number' ) ) );
		$this->assertCount( 1, $this->validator->validate( 'five', array( 'type' => 'integer' ) ) );
		$this->assertCount( 0, $this->validator->validate( 5, array( 'type' => array( 'integer', 'null' ) ) ) );
		$this->assertCount( 0, $this->validator->validate( null, array( 'type' => array( 'integer', 'null' ) ) ) );
	}

	public function test_required_properties(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
			'required'   => array( 'id' ),
		);
		$this->assertCount( 0, $this->validator->validate( array( 'id' => 5 ), $schema ) );
		$errors = $this->validator->validate( array(), $schema );
		$this->assertCount( 1, $errors );
		$this->assertStringContains( 'required', $errors[0] );
	}

	public function test_enum(): void {
		$schema = array( 'type' => 'string', 'enum' => array( 'draft', 'publish' ) );
		$this->assertCount( 0, $this->validator->validate( 'draft', $schema ) );
		$this->assertCount( 1, $this->validator->validate( 'future', $schema ) );
	}

	public function test_numeric_bounds(): void {
		$schema = array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50 );
		$this->assertCount( 0, $this->validator->validate( 25, $schema ) );
		$this->assertCount( 1, $this->validator->validate( 0, $schema ) );
		$this->assertCount( 1, $this->validator->validate( 51, $schema ) );
	}

	public function test_string_constraints(): void {
		$this->assertCount( 0, $this->validator->validate( 'ab', array( 'type' => 'string', 'minLength' => 2 ) ) );
		$this->assertCount( 1, $this->validator->validate( 'a', array( 'type' => 'string', 'minLength' => 2 ) ) );
		$this->assertCount( 1, $this->validator->validate( str_repeat( 'a', 11 ), array( 'type' => 'string', 'maxLength' => 10 ) ) );
		$this->assertCount( 1, $this->validator->validate( 'zzz', array( 'type' => 'string', 'pattern' => '^\\d+$' ) ) );
	}

	public function test_array_items(): void {
		$schema = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'string' ),
			'minItems' => 1,
		);
		$this->assertCount( 0, $this->validator->validate( array( 'a', 'b' ), $schema ) );
		$this->assertCount( 1, $this->validator->validate( array(), $schema ), 'minItems' );
		$this->assertCount( 1, $this->validator->validate( array( 'a', 5 ), $schema ), 'items type' );
	}

	public function test_additional_properties_rejected(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array( 'id' => array( 'type' => 'integer' ) ),
			'additionalProperties' => false,
		);
		$this->assertCount( 0, $this->validator->validate( array( 'id' => 1 ), $schema ) );
		$this->assertCount( 1, $this->validator->validate( array( 'id' => 1, 'extra' => true ), $schema ) );
	}

	public function test_nested_property_schemas(): void {
		$schema = array(
			'type'       => 'object',
			'properties' => array(
				'content' => array( 'type' => 'string', 'maxLength' => 500000 ),
			),
			'required'   => array( 'content' ),
		);
		$this->assertCount( 0, $this->validator->validate( array( 'content' => 'x' ), $schema ) );
		$this->assertCount( 1, $this->validator->validate( array( 'content' => 5 ), $schema ) );
		$this->assertCount( 1, $this->validator->validate( array(), $schema ) );
	}

	public function test_anyof_first_match(): void {
		$schema = array(
			'anyOf' => array(
				array( 'type' => 'integer' ),
				array( 'type' => 'string', 'maxLength' => 3 ),
			),
		);
		$this->assertCount( 0, $this->validator->validate( 7, $schema ) );
		$this->assertCount( 0, $this->validator->validate( 'abc', $schema ) );
		$this->assertCount( 1, $this->validator->validate( 'abcdef', $schema ) );
	}

	// ------------------------------------------------------------ JSON-RPC

	public function test_jsonrpc_request_parsing(): void {
		$request = JsonRpcRequest::fromDecoded( array(
			'jsonrpc' => '2.0',
			'id'      => 42,
			'method'  => 'tools/call',
			'params'  => array( 'name' => 'site.get_info' ),
		) );
		$this->assertNotNull( $request );
		$this->assertEquals( 'tools/call', $request->method() );
		$this->assertEquals( 42, $request->idAsInt() );
		$this->assertEquals( 'site.get_info', $request->param( 'name' ) );
		$this->assertFalse( $request->isNotification() );
	}

	public function test_jsonrpc_notification_has_no_id(): void {
		$request = JsonRpcRequest::fromDecoded( array(
			'jsonrpc' => '2.0',
			'method'  => 'notifications/initialized',
		) );
		$this->assertNotNull( $request );
		$this->assertTrue( $request->isNotification() );
	}

	public function test_jsonrpc_invalid_envelopes_rejected(): void {
		$this->assertNull( JsonRpcRequest::fromDecoded( 'string' ) );
		$this->assertNull( JsonRpcRequest::fromDecoded( array( 'method' => 'x' ) ), 'missing jsonrpc' );
		$this->assertNull( JsonRpcRequest::fromDecoded( array( 'jsonrpc' => '1.0', 'method' => 'x' ) ) );
		$this->assertNull( JsonRpcRequest::fromDecoded( array( 'jsonrpc' => '2.0' ) ), 'missing method' );
		$this->assertNull( JsonRpcRequest::fromDecoded( array( 'jsonrpc' => '2.0', 'method' => 'x', 'params' => 'nope' ) ) );
		$this->assertNull( JsonRpcRequest::fromDecoded( array( 'jsonrpc' => '2.0', 'method' => 'x', 'id' => 1.5 ) ), 'float id invalid' );
		$this->assertNull( JsonRpcRequest::fromDecoded( array( 1, 2, 3 ) ), 'batch envelope handled elsewhere' );
	}

	public function test_jsonrpc_response_shapes(): void {
		$response = JsonRpcResponse::result( 7, array( 'pong' => true ) );
		$body = $response->toArray();
		$this->assertEquals( '2.0', $body['jsonrpc'] );
		$this->assertEquals( 7, $body['id'] );
		$this->assertEquals( array( 'pong' => true ), $body['result'] );

		$error = JsonRpcResponse::error( null, JsonRpcResponse::E_PARSE, 'bad' );
		$body  = $error->toArray();
		$this->assertArrayHasKey( 'error', $body );
		$this->assertArrayNotHasKey( 'result', $body );
	}
}
