<?php
/**
 * Integration tests: closed-whitelist operation rehydration (Sprint
 * 0.3A Phase 2 durable-persistence pass, Package 4). OperationRegistry
 * is the ONLY place a persisted type string becomes a class instance
 * — these tests exist specifically to prove nothing outside the
 * whitelist can ever be instantiated, and every malformed-input shape
 * fails closed rather than fataling.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Mutation\ChangeSet;
use AIOS\Mutation\MutationException;
use AIOS\Mutation\Operations\FileCreateOperation;
use AIOS\Mutation\Operations\MetadataUpdateOperation;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\OperationRegistry;
use AIOS\Security\PathGuard;
use AIOS\Tests\TestCase;

final class OperationRegistryTest extends TestCase {

	private string $tempRoot;

	private PathGuard $pathGuard;

	protected function setUp(): void {
		$this->resetPlugin();
		$this->tempRoot = rtrim( sys_get_temp_dir(), '/\\' ) . '/aios-registry-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $this->tempRoot, 0777, true );
		$this->pathGuard = new PathGuard( $this->tempRoot );
	}

	protected function tearDown(): void {
		foreach ( glob( $this->tempRoot . '/*' ) ?: array() as $file ) {
			@unlink( $file );
		}
		@rmdir( $this->tempRoot );
	}

	public function test_round_trip_preserves_id_and_operations(): void {
		$op = new OptionUpdateOperation( 'aios_registry_option', 'value-1' );
		$cs = new ChangeSet( 7, array( $op ), array( 'reason' => 'test' ), 3 );

		$payload    = OperationRegistry::serialize( $cs );
		$rehydrated = OperationRegistry::rehydrate( $payload );

		$this->assertEquals( $cs->id(), $rehydrated->id() );
		$this->assertEquals( $cs->siteId(), $rehydrated->siteId() );
		$this->assertEquals( $cs->principalUserId(), $rehydrated->principalUserId() );
		$this->assertCount( 1, $rehydrated->operations() );
		$this->assertEquals( 'option.update', $rehydrated->operations()[0]->type() );
		$this->assertEquals( 'aios_registry_option', $rehydrated->operations()[0]->target() );
	}

	public function test_round_trip_preserves_fingerprint(): void {
		$op = new OptionUpdateOperation( 'aios_registry_fp_option', 'value-x' );
		$cs = new ChangeSet( 1, array( $op ) );

		$original_fp   = \AIOS\Mutation\ChangeSetFingerprint::compute( $cs );
		$rehydrated    = OperationRegistry::rehydrate( OperationRegistry::serialize( $cs ) );
		$rehydrated_fp = \AIOS\Mutation\ChangeSetFingerprint::compute( $rehydrated );

		$this->assertEquals( $original_fp, $rehydrated_fp, 'a round trip through serialize/rehydrate must not change the fingerprint' );
	}

	public function test_file_operation_round_trips_with_path_guard(): void {
		$op = new FileCreateOperation( 'registry-test.txt', 'hello world', $this->pathGuard );
		$cs = new ChangeSet( 1, array( $op ) );

		$rehydrated = OperationRegistry::rehydrate( OperationRegistry::serialize( $cs ), $this->pathGuard );
		$this->assertEquals( 'file.create', $rehydrated->operations()[0]->type() );
		$this->assertEquals( 'registry-test.txt', $rehydrated->operations()[0]->target() );
	}

	public function test_file_operation_without_path_guard_fails_closed(): void {
		$op = new FileCreateOperation( 'registry-test.txt', 'hello world', $this->pathGuard );
		$cs = new ChangeSet( 1, array( $op ) );

		try {
			OperationRegistry::rehydrate( OperationRegistry::serialize( $cs ) ); // no PathGuard passed
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'registry.missing_path_guard', $e->errorCode() );
		}
	}

	public function test_unknown_operation_type_fails_closed(): void {
		$payload = array(
			'schema_version'    => OperationRegistry::SCHEMA_VERSION,
			'change_set_id'     => 'cs_fake',
			'site_id'           => 1,
			'principal_user_id' => 1,
			'principal_type'    => 'user',
			'metadata'          => array(),
			'operations'        => array( array( 'type' => 'shell.exec', 'spec' => array( 'command' => 'rm -rf /' ) ) ),
		);
		try {
			OperationRegistry::rehydrate( $payload );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'registry.unknown_operation_type', $e->errorCode() );
		}
	}

	public function test_unsupported_schema_version_fails_closed(): void {
		$payload = array(
			'schema_version'    => 999,
			'change_set_id'     => 'cs_fake',
			'site_id'           => 1,
			'principal_user_id' => 1,
			'principal_type'    => 'user',
			'metadata'          => array(),
			'operations'        => array( array( 'type' => 'option.update', 'spec' => array( 'option' => 'x', 'value' => 'y' ) ) ),
		);
		try {
			OperationRegistry::rehydrate( $payload );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'registry.unsupported_schema_version', $e->errorCode() );
		}
	}

	public function test_malformed_payload_missing_operations_fails_closed(): void {
		$payload = array(
			'schema_version'    => OperationRegistry::SCHEMA_VERSION,
			'change_set_id'     => 'cs_fake',
			'site_id'           => 1,
			'principal_user_id' => 1,
			'principal_type'    => 'user',
			'metadata'          => array(),
			// 'operations' missing entirely.
		);
		try {
			OperationRegistry::rehydrate( $payload );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'registry.malformed_payload', $e->errorCode() );
		}
	}

	public function test_malformed_operation_spec_missing_required_field_fails_closed(): void {
		$payload = array(
			'schema_version'    => OperationRegistry::SCHEMA_VERSION,
			'change_set_id'     => 'cs_fake',
			'site_id'           => 1,
			'principal_user_id' => 1,
			'principal_type'    => 'user',
			'metadata'          => array(),
			'operations'        => array( array( 'type' => 'option.update', 'spec' => array( 'value' => 'y' ) ) ), // missing "option"
		);
		try {
			OperationRegistry::rehydrate( $payload );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'registry.malformed_payload', $e->errorCode() );
		}
	}

	public function test_protected_option_spec_fails_closed_via_operations_own_validation(): void {
		$payload = array(
			'schema_version'    => OperationRegistry::SCHEMA_VERSION,
			'change_set_id'     => 'cs_fake',
			'site_id'           => 1,
			'principal_user_id' => 1,
			'principal_type'    => 'user',
			'metadata'          => array(),
			'operations'        => array( array( 'type' => 'option.update', 'spec' => array( 'option' => 'ai_os_settings', 'value' => 'x' ) ) ),
		);
		try {
			OperationRegistry::rehydrate( $payload );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'registry.malformed_payload', $e->errorCode() );
		}
	}

	public function test_build_never_uses_dynamic_class_instantiation_for_unknown_types(): void {
		// A type string that happens to look like a real, instantiable
		// PHP class name must still be refused — this is the concrete
		// regression test for "never new $class from untrusted input".
		try {
			OperationRegistry::build( \AIOS\Mutation\Operations\FileCreateOperation::class, array( 'path' => 'x', 'content' => 'y' ), $this->pathGuard );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( MutationException $e ) {
			$this->assertEquals( 'registry.unknown_operation_type', $e->errorCode() );
		}
	}

	public function test_metadata_update_round_trips(): void {
		$op = new MetadataUpdateOperation( 1000, 'aios_meta_key', 'meta-value' );
		$cs = new ChangeSet( 1, array( $op ) );
		$rehydrated = OperationRegistry::rehydrate( OperationRegistry::serialize( $cs ) );

		$this->assertEquals( 'post.metadata_update', $rehydrated->operations()[0]->type() );
		$this->assertEquals( 'post:1000/aios_meta_key', $rehydrated->operations()[0]->target() );
	}
}
