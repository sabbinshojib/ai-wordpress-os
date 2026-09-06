<?php
/**
 * Secret encryption at rest.
 *
 * Preference order:
 *   1. libsodium (ext-sodium / paragonie-sodiumcompat) — XSalsa20-Poly1305
 *   2. OpenSSL AES-256-GCM (available on virtually all WP hosts)
 *
 * Ciphertext layout: base64( version_byte . payload )
 *   sodium:  "1" . nonce(24) . ciphertext
 *   openssl: "2" . iv(12) . tag(16) . ciphertext
 *
 * The key is derived deterministically from the WP auth salt when no
 * dedicated key is configured, so no additional secret storage is
 * required. A dedicated key can be set by the admin (and is preferred).
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

final class Crypto {

        private const SODIUM_VERSION = 1;
        private const OPENSSL_VERSION = 2;

        private string $key;

        public function __construct( ?string $dedicated_key = null ) {
                $this->key = $dedicated_key ?? self::deriveKeyFromEnvironment();
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

        public function encrypt( string $plaintext ): string {
                if ( function_exists( 'sodium_crypto_secretbox' ) ) {
                        $nonce      = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
                        $ciphertext = sodium_crypto_secretbox( $plaintext, $nonce, $this->sodiumKey() );
                        return self::b64( chr( self::SODIUM_VERSION ) . $nonce . $ciphertext );
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
                $ciphertext = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
                if ( false === $ciphertext ) {
                        throw new \RuntimeException( 'ai-os: AES-256-GCM encryption failed.' );
                }
                return self::b64( chr( self::OPENSSL_VERSION ) . $iv . $tag . $ciphertext );
        }

        public function decrypt( string $blob ): ?string {
                $raw = base64_decode( $blob, true );
                if ( false === $raw || strlen( $raw ) < 2 ) {
                        return null;
                }

                $version = ord( $raw[0] );
                $body    = substr( $raw, 1 );

                if ( self::SODIUM_VERSION === $version && function_exists( 'sodium_crypto_secretbox_open' ) ) {
                        $nonce_len = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
                        if ( strlen( $body ) <= $nonce_len ) {
                                return null;
                        }
                        $nonce      = substr( $body, 0, $nonce_len );
                        $ciphertext = substr( $body, $nonce_len );
                        $plain      = sodium_crypto_secretbox_open( $ciphertext, $nonce, $this->sodiumKey() );
                        return false === $plain ? null : $plain;
                }

                if ( self::OPENSSL_VERSION === $version ) {
                        if ( ! function_exists( 'openssl_decrypt' ) ) {
                                // No backend to decrypt an openssl-tagged blob
                                // with. Consistent with every other "cannot
                                // process this input" case here: return null
                                // rather than let PHP fatal.
                                return null;
                        }
                        // iv(12) + tag(16) + ciphertext
                        if ( strlen( $body ) <= 28 ) {
                                return null;
                        }
                        $iv         = substr( $body, 0, 12 );
                        $tag        = substr( $body, 12, 16 );
                        $ciphertext = substr( $body, 28 );
                        $plain      = openssl_decrypt( $ciphertext, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag );
                        return false === $plain ? null : $plain;
                }

                return null;
        }

        /**
         * Sodium accepts 32-byte keys only; ours already is.
         */
        private function sodiumKey(): string {
                return substr( $this->key, 0, 32 );
        }

        private static function b64( string $binary ): string {
                return base64_encode( $binary );
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
