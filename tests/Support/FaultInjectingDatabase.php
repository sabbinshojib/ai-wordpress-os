<?php
/**
 * Deterministic test collaborator for repository fault-injection
 * coverage (Sprint 0.3A Phase 2 exit-gate closure, Package 4/5).
 * Wraps a real AIOS\Database\Database and delegates every call to it
 * UNCHANGED, except for calls explicitly configured to fail via
 * failOnCall()/failAlways() — those return exactly the same
 * "operation failed" sentinel real wpdb/Database would return
 * (null from insert()/query()/getRow()/getVar(), 0 from update(),
 * [] from getResults()) so a repository under test cannot tell the
 * difference between "wpdb really failed" and "this call was
 * configured to fail."
 *
 * Never used by any production code path — Database/DatabaseInterface
 * are unchanged in production; this class exists only under tests/.
 *
 * @package AIOS\Tests\Support
 */

declare( strict_types=1 );

namespace AIOS\Tests\Support;

use AIOS\Database\Database;
use AIOS\Database\DatabaseInterface;

final class FaultInjectingDatabase implements DatabaseInterface {

	private Database $inner;

	/** @var array<string, int> method => call count so far */
	private array $callCounts = array();

	/** @var array<string, true> method => always fail */
	private array $alwaysFail = array();

	/** @var array<string, array<int, true>> method => set of call numbers (1-based) to fail */
	private array $failOnCalls = array();

	public function __construct( ?Database $inner = null ) {
		$this->inner = $inner ?? new Database();
	}

	/**
	 * The $call_number-th call to $method (1-based) fails; every other
	 * call passes through to the real Database unchanged.
	 */
	public function failOnCall( string $method, int $call_number ): void {
		$this->failOnCalls[ $method ][ $call_number ] = true;
	}

	public function failAlways( string $method ): void {
		$this->alwaysFail[ $method ] = true;
	}

	public function callCount( string $method ): int {
		return $this->callCounts[ $method ] ?? 0;
	}

	private function shouldFail( string $method ): bool {
		$count = ( $this->callCounts[ $method ] ?? 0 ) + 1;
		$this->callCounts[ $method ] = $count;
		if ( isset( $this->alwaysFail[ $method ] ) ) {
			return true;
		}
		return isset( $this->failOnCalls[ $method ][ $count ] );
	}

	public function table( string $short_name ): string {
		return $this->inner->table( $short_name );
	}

	public function charsetCollate(): string {
		return $this->inner->charsetCollate();
	}

	public function prepare( string $sql, ...$values ): string {
		return $this->inner->prepare( $sql, ...$values );
	}

	public function query( string $sql ): ?int {
		if ( $this->shouldFail( 'query' ) ) {
			return null;
		}
		return $this->inner->query( $sql );
	}

	public function getResults( string $sql ): array {
		if ( $this->shouldFail( 'getResults' ) ) {
			return array();
		}
		return $this->inner->getResults( $sql );
	}

	public function getRow( string $sql ): ?array {
		if ( $this->shouldFail( 'getRow' ) ) {
			return null;
		}
		return $this->inner->getRow( $sql );
	}

	public function getVar( string $sql ): mixed {
		if ( $this->shouldFail( 'getVar' ) ) {
			return null;
		}
		return $this->inner->getVar( $sql );
	}

	public function insert( string $table, array $data ): ?int {
		if ( $this->shouldFail( 'insert' ) ) {
			return null;
		}
		return $this->inner->insert( $table, $data );
	}

	public function update( string $table, array $data, array $where ): int {
		if ( $this->shouldFail( 'update' ) ) {
			return 0;
		}
		return $this->inner->update( $table, $data, $where );
	}

	public function escLike( string $text ): string {
		return $this->inner->escLike( $text );
	}

	public function lastError(): string {
		return $this->inner->lastError();
	}
}
