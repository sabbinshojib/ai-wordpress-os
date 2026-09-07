<?php
/**
 * Deterministic test collaborator for Crypto fault-injection coverage
 * (Sprint 0.3A Phase 2 exit-gate closure, Package 4/5, matrix items
 * I/J). Wraps a real AIOS\Support\Crypto and delegates every call to
 * it unchanged except calls explicitly configured to fail —
 * encrypt() failure throws (matching Crypto's own real "no backend
 * available" contract — encrypt() has no null/false sentinel to
 * return, so a repository calling it must already be prepared for an
 * exception), decrypt() failure returns null (Crypto's own real
 * "could not decrypt" contract).
 *
 * Never used by any production code path.
 *
 * @package AIOS\Tests\Support
 */

declare( strict_types=1 );

namespace AIOS\Tests\Support;

use AIOS\Support\Crypto;
use AIOS\Support\CryptoInterface;

final class FaultInjectingCrypto implements CryptoInterface {

	private Crypto $inner;

	private bool $failEncrypt = false;

	private bool $failDecrypt = false;

	public function __construct( ?Crypto $inner = null ) {
		$this->inner = $inner ?? new Crypto();
	}

	public function failEncrypt(): void {
		$this->failEncrypt = true;
	}

	public function failDecrypt(): void {
		$this->failDecrypt = true;
	}

	public function encrypt( string $plaintext ): string {
		if ( $this->failEncrypt ) {
			throw new \RuntimeException( 'FaultInjectingCrypto: simulated encrypt() failure.' );
		}
		return $this->inner->encrypt( $plaintext );
	}

	public function decrypt( string $envelope ): ?string {
		if ( $this->failDecrypt ) {
			return null;
		}
		return $this->inner->decrypt( $envelope );
	}
}
