<?php
/**
 * Minimal PHPUnit-compatible base test case + standalone runner
 * support. Tests run with `php tests/run.php` (bundled runner) or
 * under PHPUnit when available (phpunit.xml.dist).
 *
 * @package AIOS\Tests
 */

declare( strict_types=1 );

namespace AIOS\Tests;

abstract class TestCase {

        /** @var string[] */
        private array $failures = array();

        private string $name = '';

        public function run(): array {
                $results = array();
                $reflection = new \ReflectionClass( $this );
                $this->name = $reflection->getShortName();

                foreach ( $reflection->getMethods() as $method ) {
                        if ( ! str_starts_with( $method->getName(), 'test_' ) ) {
                                continue;
                        }
                        $this->failures = array();
                        $this->setUp();

                        $threw = null;
                        try {
                                $method->invoke( $this );
                        } catch ( \Throwable $e ) {
                                $threw = $e;
                        }
                        $this->tearDown();

                        if ( null !== $threw ) {
                                $results[] = array(
                                        'test'    => $method->getName(),
                                        'passed'  => false,
                                        'message' => 'Uncaught ' . get_class( $threw ) . ': ' . $threw->getMessage(),
                                );
                        } elseif ( array() === $this->failures ) {
                                $results[] = array( 'test' => $method->getName(), 'passed' => true, 'message' => '' );
                        } else {
                                $results[] = array(
                                        'test'    => $method->getName(),
                                        'passed'  => false,
                                        'message' => implode( "\n", $this->failures ),
                                );
                        }
                }
                return $results;
        }

        protected function setUp(): void {}

        protected function tearDown(): void {}

        protected function assert( bool $condition, string $message = 'assertion failed' ): void {
                if ( ! $condition ) {
                        $this->failures[] = $message;
                }
        }

        protected function assertTrue( bool $condition, string $message = '' ): void {
                $this->assert( $condition, $message ?: 'expected true' );
        }

        protected function assertFalse( bool $condition, string $message = '' ): void {
                $this->assert( ! $condition, $message ?: 'expected false' );
        }

        protected function assertNull( mixed $value, string $message = '' ): void {
                $this->assert( null === $value, $message ?: 'expected null' );
        }

        protected function assertNotNull( mixed $value, string $message = '' ): void {
                $this->assert( null !== $value, $message ?: 'expected not null' );
        }

        protected function assertEquals( mixed $expected, mixed $actual, string $message = '' ): void {
                $this->assert( $expected === $actual, $message ?: sprintf( 'expected %s, got %s', var_export( $expected, true ), var_export( $actual, true ) ) );
        }

        protected function assertNotEquals( mixed $expected, mixed $actual, string $message = '' ): void {
                $this->assert( $expected !== $actual, $message ?: 'expected values to differ' );
        }

        protected function assertNotSame( mixed $expected, mixed $actual, string $message = '' ): void {
                $this->assert( $expected !== $actual, $message ?: 'expected different instances' );
        }

        protected function assertSame( mixed $expected, mixed $actual, string $message = '' ): void {
                $this->assertEquals( $expected, $actual, $message );
        }

        protected function assertCount( int $expected, mixed $subject, string $message = '' ): void {
                $count = is_countable( $subject ) ? count( $subject ) : -1;
                $this->assert( $expected === $count, $message ?: sprintf( 'expected count %d, got %d', $expected, $count ) );
        }

        protected function assertStringContains( string $needle, string $haystack, string $message = '' ): void {
                $this->assert( str_contains( $haystack, $needle ), $message ?: sprintf( 'expected "%s" in subject', $needle ) );
        }

        protected function assertStringNotContains( string $needle, string $haystack, string $message = '' ): void {
                $this->assert( ! str_contains( $haystack, $needle ), $message ?: sprintf( 'expected "%s" NOT in subject', $needle ) );
        }

        protected function assertInstanceOf( string $class, mixed $value, string $message = '' ): void {
                $this->assert( $value instanceof $class, $message ?: 'expected instance of ' . $class );
        }

        protected function assertArrayHasKey( string|int $key, mixed $subject, string $message = '' ): void {
                $this->assert( is_array( $subject ) && array_key_exists( $key, $subject ), $message ?: "expected key [{$key}]" );
        }

        protected function assertArrayNotHasKey( string|int $key, mixed $subject, string $message = '' ): void {
                $this->assert( ! is_array( $subject ) || ! array_key_exists( $key, $subject ), $message ?: "expected key [{$key}] absent" );
        }

        protected function assertGreaterThan( float $expected, float $actual, string $message = '' ): void {
                $this->assert( $actual > $expected, $message ?: "expected {$actual} > {$expected}" );
        }

        // ------------------------------------------------------------ helpers

        /**
         * Fresh admin WP_User from the shim.
         */
        protected function adminUser(): \WP_User {
                return new \WP_User( 1 );
        }

        /**
         * Editor-level user (no manage_options).
         */
        protected function editorUser(): \WP_User {
                return new \WP_User( 2, array(
                        'edit_posts' => true,
                        'edit_pages' => true,
                        'upload_files' => true,
                        'publish_posts' => true,
                        'delete_posts' => true,
                        'ai_os_use' => true,
                ) );
        }

        /**
         * Contributor: read + draft only, no publish, no edit_others.
         */
        protected function contributorUser(): \WP_User {
                return new \WP_User( 3, array(
                        'edit_posts' => true,
                        'ai_os_use' => true,
                ) );
        }

        /**
         * Anonymous (no caps).
         */
        protected function anonymousUser(): \WP_User {
                return new \WP_User( 4, array() );
        }

        /**
         * Reset shim state and REBUILD the kernel so container singletons
         * (Settings, PermissionEngine, registries) observe fresh state.
         */
        protected function resetPlugin(): void {
                __reset_shim();

                // Default settings + pre-applied migration marker. Uses the
                // real accessor functions (not a raw array poke) so this
                // stays correct regardless of the shim's internal storage
                // shape — e.g. options became blog-scoped for BUG-005.
                update_option( 'ai_os_settings', array() );
                update_option( 'ai_os_migrations', array( '202501010001', '202509060001' ) );

                // Rebuild the kernel for full isolation.
                $property = new \ReflectionProperty( \AIOS\Core\Plugin::class, 'instance' );
                $property->setAccessible( true );
                $property->setValue( null, null );

                global $ai_wp_os;
                $ai_wp_os = new \AIOS\Core\Plugin();
                $ai_wp_os->boot();
                __run_hook( 'plugins_loaded' );
        }
}
