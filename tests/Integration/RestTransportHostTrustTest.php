<?php
/**
 * Regression coverage for SEC-M2 (Sprint 0.3A-B): the MCP REST
 * transport's HTTPS gate must never trust request-controlled
 * headers (Host, X-Forwarded-Proto, Forwarded) to decide whether a
 * request is exempt from the HTTPS requirement. The only legitimate
 * "local development" signal is `wp_get_environment_type()`, which
 * resolves from the WP_ENVIRONMENT_TYPE constant / filter — never
 * from anything a client can send.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Mcp\Transports\RestTransport;
use AIOS\Security\ApiKeyManager;
use AIOS\Tests\TestCase;
use WP_Error;
use WP_REST_Request;

final class RestTransportHostTrustTest extends TestCase {

	private RestTransport $transport;

	/** @var string[] $_SERVER keys this suite may set, cleaned up after each test. */
	private const TOUCHED_SERVER_KEYS = array(
		'HTTP_HOST',
		'HTTP_X_FORWARDED_PROTO',
		'HTTP_X_FORWARDED_HOST',
		'HTTP_FORWARDED',
		'HTTP_X_AI_OS_KEY',
	);

	protected function setUp(): void {
		$this->resetPlugin();
		$this->transport = Plugin::instance()->container()->get( RestTransport::class );
	}

	protected function tearDown(): void {
		foreach ( self::TOUCHED_SERVER_KEYS as $key ) {
			unset( $_SERVER[ $key ] );
		}
	}

	private function request(): WP_REST_Request {
		return new WP_REST_Request();
	}

	private function asNonSslProduction(): void {
		$GLOBALS['__wp_shim']['is_ssl']           = false;
		$GLOBALS['__wp_shim']['environment_type'] = 'production';
	}

	private function authenticateAsAdmin(): void {
		$GLOBALS['__wp_shim']['current_user'] = $this->adminUser();
	}

	// ------------------------------------------------------------ Host header

	public function test_hostile_http_host_cannot_create_a_local_development_bypass(): void {
		$this->authenticateAsAdmin();
		$this->asNonSslProduction();

		foreach ( array( 'localhost', '127.0.0.1', 'evil.local', 'attacker.test', '' ) as $host ) {
			$_SERVER['HTTP_HOST'] = $host;

			$result = $this->transport->permission( $this->request() );

			$this->assertInstanceOf(
				WP_Error::class,
				$result,
				"Host header [{$host}] must NOT bypass the HTTPS requirement"
			);
			$this->assertEquals( 'ai_os_https_required', $result->get_error_code() );
		}
	}

	// ------------------------------------------------------------ X-Forwarded-Proto

	public function test_forged_x_forwarded_proto_cannot_bypass_https_enforcement(): void {
		$this->authenticateAsAdmin();
		$this->asNonSslProduction();
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

		$result = $this->transport->permission( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result, 'a forged X-Forwarded-Proto header must not satisfy the HTTPS requirement' );
		$this->assertEquals( 'ai_os_https_required', $result->get_error_code() );
	}

	// ------------------------------------------------------------ Forwarded

	public function test_forged_forwarded_header_cannot_bypass_https_enforcement(): void {
		$this->authenticateAsAdmin();
		$this->asNonSslProduction();
		$_SERVER['HTTP_FORWARDED'] = 'for=203.0.113.7;proto=https;by=203.0.113.43';

		$result = $this->transport->permission( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result, 'a forged Forwarded header must not satisfy the HTTPS requirement' );
		$this->assertEquals( 'ai_os_https_required', $result->get_error_code() );
	}

	// ------------------------------------------------------------ baseline production behavior

	public function test_production_non_ssl_request_is_rejected_when_require_https_is_enabled(): void {
		$this->authenticateAsAdmin();
		$this->asNonSslProduction();

		$result = $this->transport->permission( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'ai_os_https_required', $result->get_error_code() );
	}

	// ------------------------------------------------------------ documented local exception

	public function test_local_environment_via_wp_get_environment_type_is_allowed_without_ssl(): void {
		$this->authenticateAsAdmin();
		$GLOBALS['__wp_shim']['is_ssl']           = false;
		$GLOBALS['__wp_shim']['environment_type'] = 'local';

		$result = $this->transport->permission( $this->request() );

		$this->assertTrue( true === $result, 'wp_get_environment_type() === "local" is the only valid HTTPS exemption' );
	}

	public function test_local_exception_is_not_granted_by_headers_even_when_environment_is_not_local(): void {
		$this->authenticateAsAdmin();
		$this->asNonSslProduction(); // environment_type stays 'production'.
		$_SERVER['HTTP_HOST']              = 'localhost';
		$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
		$_SERVER['HTTP_FORWARDED']         = 'proto=https';

		$result = $this->transport->permission( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result, 'stacking every spoofed header must still not equal a local environment' );
	}

	// ------------------------------------------------------------ normal SSL path

	public function test_normal_ssl_request_passes(): void {
		$this->authenticateAsAdmin();
		$GLOBALS['__wp_shim']['is_ssl']           = true;
		$GLOBALS['__wp_shim']['environment_type'] = 'production';

		$result = $this->transport->permission( $this->request() );

		$this->assertTrue( true === $result, 'a genuinely SSL request must pass regardless of headers' );
	}

	// ------------------------------------------------------------ unrelated auth behavior is unchanged

	public function test_api_key_authentication_still_works_over_ssl(): void {
		$GLOBALS['__wp_shim']['is_ssl'] = true;

		$manager = Plugin::instance()->container()->get( ApiKeyManager::class );
		$issued  = $manager->issue( array( 'label' => 'regression-key', 'user_id' => 1, 'max_level' => 1 ) );

		$_SERVER['HTTP_X_AI_OS_KEY'] = $issued['key'];

		$result = $this->transport->permission( $this->request() );

		$this->assertTrue( true === $result, 'API-key authenticated requests over SSL must still be permitted' );
	}

	public function test_unauthenticated_request_is_still_rejected_over_ssl(): void {
		$GLOBALS['__wp_shim']['is_ssl'] = true;
		// No current_user, no API key: wp_get_current_user() falls back to WP_User(0).

		$result = $this->transport->permission( $this->request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertEquals( 'ai_os_unauthenticated', $result->get_error_code() );
	}
}
