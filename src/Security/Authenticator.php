<?php
/**
 * Principal resolution for external AI clients.
 *
 * Two real authentication paths (spec §41):
 *
 *  1. Application Passwords — WordPress-native Basic auth. Determined
 *     by core REST infrastructure (rest_authentication_errors etc.).
 *     We only consume the already-authenticated WP_User.
 *
 *  2. AI OS API Keys — X-AI-OS-Key header. SHA-256 hashed lookup in
 *     the api_keys table, bound to a WP user, capability allowlist,
 *     max permission level, expiry, revocation, last-used tracking.
 *
 * Cookie-based (nonce) auth also works for the admin dashboard.
 *
 * @package AIOS\Security
 */

declare( strict_types=1 );

namespace AIOS\Security;

use AIOS\Database\Repositories\ApiKeyRepository;
use AIOS\Settings\Settings;
use WP_User;

final class Authenticator {

	public const HEADER_NAME = 'X-AI-OS-Key';

	/**
	 * A resolved principal: the acting WP user + provenance metadata.
	 */
	public ?WP_User $user = null;

	/**
	 * 'app_password' | 'api_key' | 'cookie' | null (unauthenticated).
	 */
	public ?string $method = null;

	/**
	 * API-key ceiling when key-authenticated, else null.
	 */
	public ?int $key_max_level = null;

	/**
	 * API-key record id when key-authenticated.
	 */
	public ?int $key_id = null;

	/**
	 * Suggested client label for audit rows ("mcp", "rest", "admin").
	 */
	private string $client;

	private ApiKeyRepository $keys;

	private Settings $settings;

	public function __construct( ApiKeyRepository $keys, Settings $settings, string $client = 'rest' ) {
		$this->keys     = $keys;
		$this->settings = $settings;
		$this->client   = $client;
	}

	/**
	 * Resolve the current request's principal.
	 *
	 * Priority: X-AI-OS-Key header → WordPress auth state.
	 * Must run AFTER WordPress determined the REST user.
	 */
	public function resolve( ?WP_User $wp_user ): bool {
		if ( $this->user instanceof WP_User ) {
			return true; // Already resolved for this request.
		}

		// 1. AI OS API key.
		$raw_key = $this->currentKeyHeader();
		if ( null !== $raw_key && $this->settings->apiKeysEnabled() ) {
			return $this->authenticateWithKey( $raw_key );
		}

		// 2. Whatever core determined (app password / cookie / none).
		$candidate = $wp_user ?? null;
		if ( $candidate instanceof WP_User && $candidate->exists() ) {
			$this->user   = $candidate;
			$this->method = 'app_password_or_cookie';
			return true;
		}

		return false;
	}

	/**
	 * Header value, respecting apache/nginx header casings.
	 */
	private function currentKeyHeader(): ?string {
		$direct = $_SERVER[ 'HTTP_X_AI_OS_KEY' ] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( is_string( $direct ) && '' !== trim( $direct ) ) {
			return trim( $direct );
		}
		return null;
	}

	/**
	 * Validate an AI OS API key.
	 */
	private function authenticateWithKey( string $raw_key ): bool {
		// Format check: aios_ + base64url(32 bytes).
		if ( ! preg_match( '/^aios_[A-Za-z0-9_-]{30,60}$/', $raw_key ) ) {
			$this->method = null;
			return false;
		}

		$record = $this->keys->findActiveByHash( \AIOS\Support\Crypto::keyHash( $raw_key ) );
		if ( null === $record ) {
			return false;
		}

		$user = get_userdata( (int) $record['user_id'] );
		if ( ! $user instanceof WP_User || ! $user->exists() ) {
			return false;
		}

		$this->user          = $user;
		$this->method        = 'api_key';
		$this->key_max_level = (int) $record['max_level'];
		$this->key_id        = (int) $record['id'];

		$this->keys->touch( (int) $record['id'], $this->clientIp() );

		return true;
	}

	public function clientIp(): ?string {
		$ip = $_SERVER['REMOTE_ADDR'] ?? null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return is_string( $ip ) ? $ip : null;
	}

	/**
	 * Client label used in audit rows.
	 */
	public function clientLabel(): string {
		return $this->client;
	}

	/**
	 * Authenticated user (assert before use).
	 */
	public function user(): WP_User {
		if ( ! $this->user instanceof WP_User ) {
			throw new \LogicException( 'Authenticator: no authenticated principal.' );
		}
		return $this->user;
	}

	public function isAuthenticated(): bool {
		return $this->user instanceof WP_User && $this->user->exists();
	}

	/**
	 * Whether the request principal may use AI OS at all.
	 */
	public function canUseAiOs(): bool {
		return $this->isAuthenticated() && PermissionEngine::canUse( $this->user );
	}
}
