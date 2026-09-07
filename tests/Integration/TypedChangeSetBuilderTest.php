<?php
/**
 * Integration tests: the typed Planner boundary (Sprint 0.3A Phase 2
 * hardening, Package 12). TypedChangeSetBuilder is the ONLY intended
 * upstream construction path for a future caller (Phase 3) — it must
 * accept nothing but typed, whitelisted OperationSpecification
 * instances and reject everything else, the same way
 * OperationRegistry (durable rehydration) does.
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Mutation\OperationSpecification;
use AIOS\Mutation\Operations\OptionUpdateOperation;
use AIOS\Mutation\TypedChangeSetBuilder;
use AIOS\Security\PathGuard;
use AIOS\Tests\TestCase;

final class TypedChangeSetBuilderTest extends TestCase {

	private TypedChangeSetBuilder $builder;

	protected function setUp(): void {
		$this->resetPlugin();
		$this->builder = new TypedChangeSetBuilder();
	}

	public function test_builds_a_valid_changeset_from_typed_specifications(): void {
		$spec = new OperationSpecification( OptionUpdateOperation::TYPE, array( 'option' => 'aios_planner_option', 'value' => 'v1' ) );
		$cs   = $this->builder->build( 1, array( $spec ) );

		$this->assertCount( 1, $cs->operations() );
		$this->assertEquals( 'option.update', $cs->operations()[0]->type() );
		$this->assertEquals( 'aios_planner_option', $cs->operations()[0]->target() );
	}

	public function test_rejects_unknown_operation_type_at_specification_construction(): void {
		try {
			new OperationSpecification( 'shell.exec', array( 'command' => 'rm -rf /' ) );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_rejects_a_dynamic_class_name_disguised_as_a_type(): void {
		try {
			new OperationSpecification( OptionUpdateOperation::class, array( 'option' => 'x', 'value' => 'y' ) );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_rejects_a_non_specification_entry(): void {
		try {
			/** @phpstan-ignore-next-line intentionally wrong type for the test */
			$this->builder->build( 1, array( array( 'type' => 'option.update', 'spec' => array() ) ) );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_rejects_empty_specification_list(): void {
		try {
			$this->builder->build( 1, array() );
			$this->assertTrue( false, 'expected InvalidArgumentException' );
		} catch ( \InvalidArgumentException $e ) {
			$this->assertTrue( true );
		}
	}

	public function test_rejects_malformed_spec_via_the_same_registry_validation(): void {
		$spec = new OperationSpecification( OptionUpdateOperation::TYPE, array( 'value' => 'y' ) ); // missing "option"
		try {
			$this->builder->build( 1, array( $spec ) );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( \AIOS\Mutation\MutationException $e ) {
			$this->assertEquals( 'registry.malformed_payload', $e->errorCode() );
		}
	}

	public function test_rejects_protected_option_target_via_operations_own_validation(): void {
		$spec = new OperationSpecification( OptionUpdateOperation::TYPE, array( 'option' => 'ai_os_settings', 'value' => 'x' ) );
		try {
			$this->builder->build( 1, array( $spec ) );
			$this->assertTrue( false, 'expected MutationException' );
		} catch ( \AIOS\Mutation\MutationException $e ) {
			$this->assertEquals( 'registry.malformed_payload', $e->errorCode() );
		}
	}

	public function test_file_operation_specification_builds_with_a_path_guard(): void {
		$temp = rtrim( sys_get_temp_dir(), '/\\' ) . '/aios-planner-test-' . bin2hex( random_bytes( 6 ) );
		mkdir( $temp, 0777, true );
		try {
			$spec = new OperationSpecification( \AIOS\Mutation\Operations\FileCreateOperation::TYPE, array( 'path' => 'planner-test.txt', 'content' => 'hi' ) );
			$cs   = $this->builder->build( 1, array( $spec ), array(), null, new PathGuard( $temp ) );
			$this->assertEquals( 'file.create', $cs->operations()[0]->type() );
		} finally {
			@unlink( $temp . '/planner-test.txt' );
			@rmdir( $temp );
		}
	}

	public function test_multiple_specifications_build_in_order(): void {
		$spec_a = new OperationSpecification( OptionUpdateOperation::TYPE, array( 'option' => 'aios_planner_a', 'value' => 'a' ) );
		$spec_b = new OperationSpecification( OptionUpdateOperation::TYPE, array( 'option' => 'aios_planner_b', 'value' => 'b' ) );
		$cs = $this->builder->build( 1, array( $spec_a, $spec_b ) );

		$this->assertEquals( 'aios_planner_a', $cs->operations()[0]->target() );
		$this->assertEquals( 'aios_planner_b', $cs->operations()[1]->target() );
	}
}
