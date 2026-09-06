<?php
/**
 * Integration tests: API key lifecycle + authenticator (spec §41).
 *
 * @package AIOS\Tests\Integration
 */

declare( strict_types=1 );

namespace AIOS\Tests\Integration;

use AIOS\Core\Plugin;
use AIOS\Database\Repositories\ApiKeyRepository;
use AIOS\Security\ApiKeyManager;
use AIOS\Security\Authenticator;
use AIOS\Tests\TestCase;

final class ApiKeyTest extends TestCase {

        private ApiKeyManager $manager;

        private ApiKeyRepository $repository;

        protected function setUp(): void {
                $this->resetPlugin();
                $this->manager    = Plugin::instance()->container()->get( ApiKeyManager::class );
                $this->repository = Plugin::instance()->container()->get( ApiKeyRepository::class );
        }

        public function test_key_generation_shape(): void {
                $key = ApiKeyManager::generate();
                $this->assert( preg_match( '/^aios_[A-Za-z0-9_-]{43}$/', $key ) === 1, "unexpected key format [{$key}]" );
                $this->assertNotEquals( $key, ApiKeyManager::generate(), 'keys must be unique' );
        }

        public function test_issue_and_authenticate_roundtrip(): void {
                $issued = $this->manager->issue( array(
                        'label'     => 'Claude laptop',
                        'user_id'   => 1,
                        'max_level' => 1,
                ) );

                $this->assertGreaterThan( 0, $issued['id'] );
                $raw = $issued['key'];

                // The stored record must contain the hash, never the raw key.
                $stored = $this->repository->findActiveByHash( hash( 'sha256', $raw, false ) );
                $this->assertNotNull( $stored, 'hash lookup must find the key' );
                $this->assertArrayNotHasKey( 'raw', $stored );

                // Authenticator path.
                $_SERVER['HTTP_X_AI_OS_KEY'] = $raw;
                $auth = new Authenticator( $this->repository, new \AIOS\Settings\Settings(), 'test' );
                $this->assertTrue( $auth->resolve( null ) );
                $this->assertEquals( 1, (int) $auth->user()->ID );
                $this->assertEquals( 'api_key', $auth->method );
                $this->assertEquals( 1, $auth->key_max_level );
                unset( $_SERVER['HTTP_X_AI_OS_KEY'] );
        }

        public function test_revoked_key_fails_authentication(): void {
                $issued = $this->manager->issue( array( 'label' => 'x', 'user_id' => 1, 'max_level' => 1 ) );
                $this->assertTrue( $this->manager->revoke( $issued['id'] ) );

                $_SERVER['HTTP_X_AI_OS_KEY'] = $issued['key'];
                $auth = new Authenticator( $this->repository, new \AIOS\Settings\Settings(), 'test' );
                $this->assertFalse( $auth->resolve( null ) );
                unset( $_SERVER['HTTP_X_AI_OS_KEY'] );
        }

        public function test_unknown_key_fails(): void {
                $_SERVER['HTTP_X_AI_OS_KEY'] = 'aios_totally_fake_key_1234567890123456789';
                $auth = new Authenticator( $this->repository, new \AIOS\Settings\Settings(), 'test' );
                $this->assertFalse( $auth->resolve( null ) );
                unset( $_SERVER['HTTP_X_AI_OS_KEY'] );
        }

        public function test_key_ceiling_enforced_by_executor(): void {
                // Admin user with a level-1 key attempting level-2 tool.
                $issued = $this->manager->issue( array( 'label' => 'limited', 'user_id' => 1, 'max_level' => 1 ) );

                $_SERVER['HTTP_X_AI_OS_KEY'] = $issued['key'];
                $auth = new Authenticator( $this->repository, new \AIOS\Settings\Settings(), 'test' );
                $this->assertTrue( $auth->resolve( null ) );

                $executor = Plugin::instance()->container()->get( \AIOS\Tools\ToolExecutor::class );
                $result   = $executor->execute( 'system.cache.flush', array(), $auth->user(), 'test', $auth );
                $this->assertTrue( $result->isError() );
                $this->assertEquals( 'permission.level_denied', $result->error()?->code() );
                unset( $_SERVER['HTTP_X_AI_OS_KEY'] );
        }

        public function test_rotation_revokes_old_key(): void {
                $issued = $this->manager->issue( array( 'label' => 'rotating', 'user_id' => 1, 'max_level' => 1 ) );

                $rotated = $this->manager->rotate( $issued['id'] );
                $this->assert( is_array( $rotated ), 'rotation must succeed' );
                $this->assertEquals( $issued['id'], $rotated['rotated_from'] );
                $this->assertNotEquals( $issued['key'], $rotated['key'] );

                // Old key no longer authenticates.
                $_SERVER['HTTP_X_AI_OS_KEY'] = $issued['key'];
                $auth = new Authenticator( $this->repository, new \AIOS\Settings\Settings(), 'test' );
                $this->assertFalse( $auth->resolve( null ) );
                unset( $_SERVER['HTTP_X_AI_OS_KEY'] );
        }

        public function test_manager_requires_user_id(): void {
                $threw = null;
                try {
                        $this->manager->issue( array( 'label' => 'orphan' ) );
                } catch ( \InvalidArgumentException $e ) {
                        $threw = $e;
                }
                $this->assertNotNull( $threw );
        }

        public function test_api_key_listing_never_exposes_hashes(): void {
                $issued = $this->manager->issue( array( 'label' => 'secretish', 'user_id' => 1, 'max_level' => 1 ) );
                $json   = wp_json_encode( $this->manager->list() );
                $this->assertStringNotContains( 'key_hash', (string) $json );
                $this->assertStringNotContains( $issued['key'], (string) $json, 'the raw key must never appear in listings' );
        }

        public function test_key_expiry_is_respected(): void {
                $issued = $this->manager->issue( array(
                        'label'      => 'temp',
                        'user_id'    => 1,
                        'max_level'  => 1,
                        'expires_at' => gmdate( 'Y-m-d', time() - DAY_IN_SECONDS ),
                ) );

                $_SERVER['HTTP_X_AI_OS_KEY'] = $issued['key'];
                $auth = new Authenticator( $this->repository, new \AIOS\Settings\Settings(), 'test' );
                $this->assertFalse( $auth->resolve( null ), 'expired keys must fail' );
                unset( $_SERVER['HTTP_X_AI_OS_KEY'] );
        }
}
