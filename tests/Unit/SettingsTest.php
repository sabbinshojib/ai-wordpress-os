<?php
/**
 * Unit tests: Settings model.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Settings\Settings;
use AIOS\Tests\TestCase;

final class SettingsTest extends TestCase {

	public function test_defaults_are_safe(): void {
		update_option( 'ai_os_settings', array() );
		$settings = new Settings();

		$this->assertEquals( Settings::MODE_SAFE, $settings->mode() );
		$this->assertEquals( 1, $settings->maxAutoLevel() );
		$this->assertEquals( 2, $settings->approvalThreshold() );
		$this->assertTrue( $settings->mcpEnabled() );
		$this->assertTrue( $settings->requireHttps() );
		$this->assertFalse( $settings->autoApproveSafe() );
		$this->assertFalse( $settings->removeDataOnUninstall() );
	}

	public function test_sanitize_rejects_invalid_mode(): void {
		update_option( 'ai_os_settings', array( 'mode' => 'yolo' ) );
		$settings = new Settings();
		$this->assertEquals( Settings::MODE_SAFE, $settings->mode(), 'invalid mode must fall back to safe' );
	}

	public function test_int_clamping(): void {
		update_option( 'ai_os_settings', array(
			'rate_limit_requests' => 100000,
			'approval_ttl_minutes' => -5,
		) );
		$settings = new Settings();
		$this->assertEquals( 10000, $settings->rateLimitRequests() );
		$this->assertEquals( 1, $settings->approvalTtlMinutes() );
	}

	public function test_update_and_save_roundtrip(): void {
		update_option( 'ai_os_settings', array() );
		$settings = new Settings();
		$settings->update( array( 'mode' => 'balanced', 'audit_retention_days' => 30 ) );

		$fresh = new Settings();
		$this->assertEquals( Settings::MODE_BALANCED, $fresh->mode() );
		$this->assertEquals( 30, $fresh->auditRetentionDays() );
	}

	public function test_mode_presets_complete(): void {
		foreach ( Settings::MODE_PRESETS as $preset ) {
			$this->assertArrayHasKey( 'max_level', $preset );
			$this->assertArrayHasKey( 'approval_at', $preset );
		}
	}
}
