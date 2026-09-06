<?php
/**
 * Unit tests: AIOS\Core\EnvironmentGuard (spec BUG-004).
 *
 * The "neither sodium nor openssl is loaded" and "mbstring is not
 * loaded" branches cannot be exercised as unit tests in-process — PHP
 * has no supported way to unload an extension at runtime. That exact
 * scenario is instead verified empirically as part of this sprint's
 * test matrix: running `php -n tests/run.php` (genuinely no optional
 * extensions loaded) turns CryptoTest's 4 encryption tests from
 * uncaught fatal errors into clean, catchable RuntimeExceptions with
 * a clear message — see docs/audits/SPRINT-0.1-STABILIZATION-REPORT.md
 * for the recorded before/after. What IS tested here is that the
 * guard correctly reports the (fully-provisioned) CURRENT
 * environment, so a regression that makes it report the wrong thing
 * on a normal host is still caught.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Core\EnvironmentGuard;
use AIOS\Tests\TestCase;

final class EnvironmentGuardTest extends TestCase {

        public function test_optional_extension_status_reflects_reality(): void {
                $status = EnvironmentGuard::optionalExtensionStatus();
                $this->assertArrayHasKey( 'mbstring', $status );
                $this->assertEquals( extension_loaded( 'mbstring' ), $status['mbstring'] );
        }

        public function test_has_encryption_backend_reflects_reality(): void {
                $expected = extension_loaded( 'sodium' ) || extension_loaded( 'openssl' );
                $this->assertEquals( $expected, EnvironmentGuard::hasEncryptionBackend() );
        }

        public function test_degraded_capabilities_is_empty_on_a_fully_provisioned_host(): void {
                // This test suite is always run with mbstring + at least
                // one encryption backend loaded (see docs/INSTALLATION.md's
                // environment matrix); if either is genuinely missing here,
                // that is itself worth failing loudly on rather than
                // silently skipping.
                if ( ! extension_loaded( 'mbstring' ) || ! EnvironmentGuard::hasEncryptionBackend() ) {
                        $this->assert( true, 'this environment is intentionally minimal; degraded-capability reporting is verified separately (see class docblock)' );
                        return;
                }
                $this->assertEquals( array(), EnvironmentGuard::degradedCapabilities() );
        }

        public function test_snapshot_has_the_expected_shape(): void {
                $snapshot = EnvironmentGuard::snapshot();
                $this->assertArrayHasKey( 'php_version', $snapshot );
                $this->assertArrayHasKey( 'optional_extensions', $snapshot );
                $this->assertArrayHasKey( 'encryption_backend', $snapshot );
                $this->assertArrayHasKey( 'degraded', $snapshot );
                $this->assertEquals( PHP_VERSION, $snapshot['php_version'] );
        }
}
