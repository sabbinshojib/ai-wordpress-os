<?php
/**
 * Secret encryption at rest, with a versioned ciphertext envelope and
 * key-rotation support (SEC-M5).
 *
 * Preference order for new encryption:
 *   1. libsodium (ext-sodium / paragonie-sodiumcompat) — XSalsa20-Poly1305
 *   2. OpenSSL AES-256-GCM (available on virtually all WP hosts)
 *
 * Envelope format (current):
 *
 *   "aios{ENVELOPE_VERSION}:{backend}:{key_version}:{base64(payload)}"
 *
 *   e.g. "aios1:sodium:2:eNo3....=="
 *
 *   - "aios{N}"     literal tag + envelope-format version (metadata only)
 *   - {backend}     "sodium" | "openssl" — which algorithm produced payload
 *   - {key_version} which key (current or previous) the payload was
 *                   encrypted under — an opaque integer id, never the
 *                   key material itself
 *   - payload       sodium:  nonce(24) . ciphertext
 *                   openssl: iv(12) . tag(16) . ciphertext
 *
 * The envelope carries only non-secret metadata. It never contains a
 * key, a derived key, or plaintext. This format cannot collide with
 * the pre-SEC-M5 wire format below, because that format is pure
 * base64 and base64's alphabet does not include ":" — any blob
 * containing ":" is unambiguously a new-style envelope, and any blob
 * without it is unambiguously legacy.
 *
 * Legacy format (pre-SEC-M5, still decryptable, never produced by
 * encrypt() anymore): base64( version_byte . payload )
 *   version_byte 1 (sodium):  nonce(24) . ciphertext
 *   version_byte 2 (openssl): iv(12) . tag(16) . ciphertext
 * Legacy ciphertext has no key-version metadata, so decrypting it is
 * attempted against the current key and then every configured
 * previous key, in that order; the result is always flagged
 * "legacy" so callers can choose to re-encrypt it (see reencrypt()).
 *
 * The key is derived deterministically from the WP auth salt when no
 * dedicated key is configured, so no additional secret storage is
 * required. A dedicated key can be set by the admin (and is
 * preferred). Multisite is unaffected: AUTH_KEY/AUTH_SALT are
 * network-wide wp-config.php constants, so key derivation is
 * identical on every site of the network.
 *
 * Nothing in this class ever logs, throws, or returns a plaintext,
 * key, or derived-key value inside an error message — failures are
 * reported as short, generic, non-secret error codes only.
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

final class Crypto implements CryptoInterface {

        /**
         * Envelope format version (metadata only — not a secret).
         * Bumped only if the envelope's own structure changes; the
         * "backend" field is what varies between sodium and OpenSSL.
         */
        private const ENVELOPE_VERSION = 1;

        private const BACKEND_SODIUM  = 'sodium';
        private const BACKEND_OPENSSL = 'openssl';

        /**
         * Pre-envelope (legacy) wire-format version bytes.
         */
        private const LEGACY_SODIUM_VERSION  = 1;
        private const LEGACY_OPENSSL_VERSION = 2;

        /**
         * Non-secret error codes returned by decryptWithMeta(). Never
         * derived from, or containing, plaintext or key material.
         */
        public const ERROR_MALFORMED           = 'malformed';
        public const ERROR_UNSUPPORTED_VERSION  = 'unsupported_version';
        public const ERROR_BACKEND_UNAVAILABLE  = 'backend_unavailable';
        public const ERROR_UNKNOWN_KEY_VERSION  = 'unknown_key_version';
        public const ERROR_AUTH_FAILED          = 'auth_failed';

        private int $currentKeyVersion;

        /**
         * @var array<int, string> key_version => 32-byte raw key
         */
        private array $keysByVersion = array();

        /**
         * @param string|null          $dedicated_key        32-byte raw key for the CURRENT key version.
         *                                                    Null derives the key from the WP auth salt (unchanged
         *                                                    behavior from pre-SEC-M5 Crypto).
         * @param array<int, string>   $previous_key_versions Optional map of key_version => 32-byte raw key for
         *                                                    keys that are no longer current but must still be
         *                                                    decryptable (rotation support). Never used to encrypt.
         * @param int                  $current_key_version   The version id the current key represents. Defaults
         *                                                    to 1, matching the implicit single-key behavior that
         *                                                    existed before rotation support was added.
         */
        public function __construct(
                ?string $dedicated_key = null,
                array $previous_key_versions = array(),
                int $current_key_version = 1
        ) {
                if ( $current_key_version < 1 ) {
                        throw new \InvalidArgumentException( 'ai-os: current_key_version must be a positive integer.' );
                }

                foreach ( $previous_key_versions as $version => $key ) {
                        if ( ! is_int( $version ) || $version < 1 || ! is_string( $key ) || '' === $key ) {
                                throw new \InvalidArgumentException( 'ai-os: previous_key_versions must map positive integer versions to non-empty raw key strings.' );
                        }
                        $this->keysByVersion[ $version ] = $key;
                }

                $this->currentKeyVersion = $current_key_version;
                // Current key always takes precedence over any previous
                // entry registered under the same version id.
                $this->keysByVersion[ $current_key_version ] = $dedicated_key ?? self::deriveKeyFromEnvironment();
        }

        /**
         * Derive a 32-byte key from the site's auth salt.
         *
         * Uses hash('sha256') over the salt so any string salt yields a
         * valid key length. Falls back to a constant when no salt exists
         * (edge case: tests / unusual environments).
         */
        private static function deriveKeyFromEnvironment(): string {
                $salt = '';
                if ( defined( 'AUTH_KEY' ) && is_string( AUTH_KEY ) && '' !== AUTH_KEY ) {
                        $salt = AUTH_KEY;
                } elseif ( defined( 'AUTH_SALT' ) && is_string( AUTH_SALT ) && '' !== AUTH_SALT ) {
                        $salt = AUTH_SALT;
                } elseif ( function_exists( 'wp_salt' ) ) {
                        try {
                                $salt = (string) wp_salt( 'auth' );
                        } catch ( \Throwable $e ) {
                                $salt = '';
                        }
                }
                if ( '' === $salt ) {
                        $salt = 'ai-wordpress-os-development-salt';
                }
                return hash( 'sha256', $salt, true );
        }

        public function currentKeyVersion(): int {
                return $this->currentKeyVersion;
        }

        /**
         * Encrypt under the CURRENT key version, using sodium when
         * available and falling back to OpenSSL AES-256-GCM. Never falls
         * back to an insecure/unauthenticated scheme: if neither backend
         * is loaded, this throws rather than silently doing nothing safe.
         */
        public function encrypt( string $plaintext ): string {
                $key = $this->keysByVersion[ $this->currentKeyVersion ];

                if ( function_exists( 'sodium_crypto_secretbox' ) ) {
                        $nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                        $ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, self::sodiumKeyFor( $key ) );
                        return self::buildEnvelope( self::BACKEND_SODIUM, $this->currentKeyVersion, $nonce . $ciphertext );
                }

                if ( ! function_exists( 'openssl_encrypt' ) ) {
                        // Neither backend is available on this host. Fail
                        // loudly and catchably here rather than letting PHP
                        // itself fatal a few lines below with "Call to
                        // undefined function openssl_encrypt()" — there is
                        // no safe plaintext fallback for "encryption".
                        throw new \RuntimeException( 'ai-os: no encryption backend available (neither the "sodium" nor the "openssl" PHP extension is loaded).' );
                }

                $iv         = random_bytes( 12 );
                $tag        = '';
                $ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
                if ( false === $ciphertext ) {
                        throw new \RuntimeException( 'ai-os: AES-256-GCM encryption failed.' );
                }
                return self::buildEnvelope( self::BACKEND_OPENSSL, $this->currentKeyVersion, $iv . $tag . $ciphertext );
        }

        /**
         * Simple decrypt: current-version envelope, legacy envelope, or
         * previous-key envelope, all handled transparently. Returns null
         * on ANY failure (malformed, unsupported version, wrong/unknown
         * key, tampered ciphertext, auth failure, missing backend) —
         * never a partial or corrupted plaintext. Callers that need to
         * know *why* it failed, or whether the value was legacy, should
         * use decryptWithMeta() instead.
         */
        public function decrypt( string $envelope ): ?string {
                $result = $this->decryptWithMeta( $envelope );
                return $result['ok'] ? $result['plaintext'] : null;
        }

        /**
         * Decrypt with full, structured, non-secret diagnostics.
         *
         * @return array{
         *   ok: bool,
         *   plaintext: ?string,
         *   legacy: bool,
         *   key_version: ?int,
         *   backend: ?string,
         *   error: ?string
         * }
         */
        public function decryptWithMeta( string $envelope ): array {
                if ( preg_match( '/^aios(\d+):(.*)$/s', $envelope, $matches ) ) {
                        return $this->decryptEnvelope( (int) $matches[1], $matches[2] );
                }
                return $this->decryptLegacy( $envelope );
        }

        /**
         * Re-encrypt an envelope (current-version, previous-key, or
         * legacy) under the CURRENT key version and preferred backend.
         * Returns the new envelope, or null if the input could not be
         * decrypted at all. Plaintext only ever exists in a local
         * variable for the duration of this call; it is never logged,
         * returned, or persisted here — the caller receives ciphertext
         * only.
         */
        public function reencrypt( string $envelope ): ?string {
                $result = $this->decryptWithMeta( $envelope );
                if ( ! $result['ok'] ) {
                        return null;
                }
                return $this->encrypt( $result['plaintext'] );
        }

        /**
         * @return array{ok: bool, plaintext: ?string, legacy: bool, key_version: ?int, backend: ?string, error: ?string}
         */
        private function decryptEnvelope( int $envelope_version, string $rest ): array {
                if ( self::ENVELOPE_VERSION !== $envelope_version ) {
                        return self::failure( self::ERROR_UNSUPPORTED_VERSION, false, null, null );
                }

                $parts = explode( ':', $rest, 3 );
                if ( 3 !== count( $parts ) ) {
                        return self::failure( self::ERROR_MALFORMED, false, null, null );
                }
                [ $backend, $key_version_raw, $payload_b64 ] = $parts;

                if ( ! in_array( $backend, array( self::BACKEND_SODIUM, self::BACKEND_OPENSSL ), true ) ) {
                        return self::failure( self::ERROR_MALFORMED, false, null, null );
                }
                if ( ! ctype_digit( $key_version_raw ) || '0' === $key_version_raw ) {
                        return self::failure( self::ERROR_MALFORMED, false, null, $backend );
                }
                $key_version = (int) $key_version_raw;

                $payload = base64_decode( $payload_b64, true );
                if ( false === $payload ) {
                        return self::failure( self::ERROR_MALFORMED, false, $key_version, $backend );
                }

                if ( ! isset( $this->keysByVersion[ $key_version ] ) ) {
                        return self::failure( self::ERROR_UNKNOWN_KEY_VERSION, false, $key_version, $backend );
                }
                $key = $this->keysByVersion[ $key_version ];

                $attempt = self::decryptPayload( $backend, $payload, $key );
                if ( ! $attempt['available'] ) {
                        return self::failure( self::ERROR_BACKEND_UNAVAILABLE, false, $key_version, $backend );
                }
                if ( null === $attempt['plaintext'] ) {
                        return self::failure( self::ERROR_AUTH_FAILED, false, $key_version, $backend );
                }

                return array(
                        'ok'          => true,
                        'plaintext'   => $attempt['plaintext'],
                        'legacy'      => false,
                        'key_version' => $key_version,
                        'backend'     => $backend,
                        'error'       => null,
                );
        }

        /**
         * @return array{ok: bool, plaintext: ?string, legacy: bool, key_version: ?int, backend: ?string, error: ?string}
         */
        private function decryptLegacy( string $blob ): array {
                $raw = base64_decode( $blob, true );
                if ( false === $raw || strlen( $raw ) < 2 ) {
                        return self::failure( self::ERROR_MALFORMED, false, null, null );
                }

                $version_byte = ord( $raw[0] );
                $body         = substr( $raw, 1 );

                if ( self::LEGACY_SODIUM_VERSION === $version_byte ) {
                        $backend = self::BACKEND_SODIUM;
                } elseif ( self::LEGACY_OPENSSL_VERSION === $version_byte ) {
                        $backend = self::BACKEND_OPENSSL;
                } else {
                        return self::failure( self::ERROR_UNSUPPORTED_VERSION, true, null, null );
                }

                // No key-version metadata exists in the legacy format, so
                // try the current key first, then every previous key,
                // in descending version order (most-recently-superseded
                // first — the most likely match after a single rotation).
                $candidates = array( $this->currentKeyVersion => $this->keysByVersion[ $this->currentKeyVersion ] );
                $others     = $this->keysByVersion;
                unset( $others[ $this->currentKeyVersion ] );
                krsort( $others );
                $candidates += $others;

                $backend_available = true;
                foreach ( $candidates as $version => $key ) {
                        $attempt = self::decryptPayload( $backend, $body, $key );
                        if ( ! $attempt['available'] ) {
                                $backend_available = false;
                                break;
                        }
                        if ( null !== $attempt['plaintext'] ) {
                                return array(
                                        'ok'          => true,
                                        'plaintext'   => $attempt['plaintext'],
                                        'legacy'      => true,
                                        'key_version' => $version,
                                        'backend'     => $backend,
                                        'error'       => null,
                                );
                        }
                }

                if ( ! $backend_available ) {
                        return self::failure( self::ERROR_BACKEND_UNAVAILABLE, true, null, $backend );
                }
                return self::failure( self::ERROR_AUTH_FAILED, true, null, $backend );
        }

        /**
         * @return array{available: bool, plaintext: ?string} `available`
         *         is false only when the required PHP extension is not
         *         loaded (a distinct array field, not a sentinel value,
         *         so a decrypted plaintext can never be misread as this
         *         condition). When `available` is true, a null
         *         `plaintext` means authentication/tag failure or a
         *         too-short payload — never a partial/corrupted result.
         */
        private static function decryptPayload( string $backend, string $payload, string $key ): array {
                if ( self::BACKEND_SODIUM === $backend ) {
                        if ( ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
                                return array( 'available' => false, 'plaintext' => null );
                        }
                        $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                        if ( strlen( $payload ) <= $nonce_len ) {
                                return array( 'available' => true, 'plaintext' => null );
                        }
                        $nonce      = substr( $payload, 0, $nonce_len );
                        $ciphertext = substr( $payload, $nonce_len );
                        $plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, self::sodiumKeyFor( $key ) );
                        return array( 'available' => true, 'plaintext' => false === $plain ? null : $plain );
                }

                // openssl
                if ( ! function_exists( 'openssl_decrypt' ) ) {
                        return array( 'available' => false, 'plaintext' => null );
                }
                // iv(12) + tag(16) + ciphertext. An empty plaintext
                // legitimately produces a 28-byte payload (iv + tag, zero
                // ciphertext bytes) and openssl_decrypt() authenticates it
                // through the tag exactly like any other length — rejecting
                // length 28 here turned every round-tripped empty string
                // into an auth-failure null on openssl-only hosts (sodium
                // hosts were unaffected). Only STRICTLY shorter payloads
                // are structurally invalid.
                if ( strlen( $payload ) < 28 ) {
                        return array( 'available' => true, 'plaintext' => null );
                }
                $iv         = substr( $payload, 0, 12 );
                $tag        = substr( $payload, 12, 16 );
                $ciphertext = substr( $payload, 28 );
                $plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
                return array( 'available' => true, 'plaintext' => false === $plain ? null : $plain );
        }

        /**
         * @return array{ok: bool, plaintext: ?string, legacy: bool, key_version: ?int, backend: ?string, error: ?string}
         */
        private static function failure( string $error, bool $legacy, ?int $key_version, ?string $backend ): array {
                return array(
                        'ok'          => false,
                        'plaintext'   => null,
                        'legacy'      => $legacy,
                        'key_version' => $key_version,
                        'backend'     => $backend,
                        'error'       => $error,
                );
        }

        private static function buildEnvelope( string $backend, int $key_version, string $binary_payload ): string {
                return sprintf(
                        'aios%d:%s:%d:%s',
                        self::ENVELOPE_VERSION,
                        $backend,
                        $key_version,
                        base64_encode( $binary_payload )
                );
        }

        /**
         * Sodium accepts 32-byte keys only; ours already is.
         */
        private static function sodiumKeyFor( string $key ): string {
                return substr( $key, 0, 32 );
        }

        /**
         * Secure comparison helper for keyed lookups.
         */
        public static function equals( string $a, string $b ): bool {
                return hash_equals( $a, $b );
        }

        /**
         * Stable hash used for API-key storage (sha256, not reversible,
         * constant time comparison via hash_equals at lookup time).
         */
        public static function keyHash( string $raw_key ): string {
                return hash( 'sha256', $raw_key, false );
        }
}
