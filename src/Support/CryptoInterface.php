<?php
/**
 * The subset of AIOS\Support\Crypto's public surface a repository
 * depends on for encrypting/decrypting durable payloads. Exists so a
 * test can substitute a deterministic fault-injecting collaborator
 * (see tests/Support/FaultInjectingCrypto.php) without any production
 * code path changing: Crypto implements this, and every repository
 * constructor accepts the interface, so passing a real Crypto object
 * works exactly as before.
 *
 * @package AIOS\Support
 */

declare( strict_types=1 );

namespace AIOS\Support;

interface CryptoInterface {

	public function encrypt( string $plaintext ): string;

	public function decrypt( string $envelope ): ?string;
}
