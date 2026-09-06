<?php
/**
 * Unit tests: DI container.
 *
 * @package AIOS\Tests\Unit
 */

declare( strict_types=1 );

namespace AIOS\Tests\Unit;

use AIOS\Core\Container;
use AIOS\Core\ContainerException;
use AIOS\Tests\TestCase;

final class ContainerTest extends TestCase {

        public function test_bind_resolves_singleton(): void {
                $container = new Container();
                $container->bind( 'counter', static fn(): object => new class {} );
                $a = $container->get( 'counter' );
                $b = $container->get( 'counter' );
                $this->assertSame( $a, $b, 'shared binding must return the same instance' );
        }

        public function test_factory_binding_returns_new_instances(): void {
                $container = new Container();
                $container->bind( 'ephemeral', static fn() => new class {}, false );
                $this->assertNotSame( $container->get( 'ephemeral' ), $container->get( 'ephemeral' ) );
        }

        public function test_instance_registration(): void {
                $container = new Container();
                $object = new class {};
                $container->instance( 'thing', $object );
                $this->assertSame( $object, $container->get( 'thing' ) );
        }

        public function test_unknown_id_throws(): void {
                $container = new Container();
                $threw = null;
                try {
                        $container->get( 'does_not_exist_anywhere' );
                } catch ( \AIOS\Core\ContainerException $e ) {
                        $threw = $e;
                }
                $this->assertInstanceOf( ContainerException::class, $threw );
        }

        public function test_reflection_auto_resolution(): void {
                $container = new Container();

                $object = $container->get( \AIOS\Tests\Unit\Fixtures\DependencyNeedingService::class );
                $this->assertNotNull( $object->settings, 'constructor dependency must be auto-resolved' );
        }

        public function test_has(): void {
                $container = new Container();
                $container->bind( 'known', static fn(): int => 1 );
                $this->assertTrue( $container->has( 'known' ) );
                $this->assertTrue( $container->has( \AIOS\Tests\Unit\Fixtures\DependencyNeedingService::class ) );
                $this->assertFalse( $container->has( 'unknown_service' ) );
        }
}

namespace AIOS\Tests\Unit\Fixtures;

final class FakeSettings {
        public string $value = 'x';
}

final class DependencyNeedingService {
        public FakeSettings $settings;

        public function __construct( FakeSettings $settings ) {
                $this->settings = $settings;
        }
}
