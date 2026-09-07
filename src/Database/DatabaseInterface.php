<?php
/**
 * The subset of AIOS\Database\Database's public surface a repository
 * depends on. Exists so a test can substitute a deterministic
 * fault-injecting collaborator (see tests/Support/FaultInjectingDatabase.php)
 * without any production code path changing: Database implements this,
 * and every repository constructor accepts the interface, so passing a
 * real Database object works exactly as before — nothing in production
 * ever constructs or depends on anything but the real Database.
 *
 * @package AIOS\Database
 */

declare( strict_types=1 );

namespace AIOS\Database;

interface DatabaseInterface {

	public function table( string $short_name ): string;

	public function charsetCollate(): string;

	public function prepare( string $sql, ...$values ): string;

	/**
	 * @return int|null Insert ID on INSERT (when generated), else row count.
	 */
	public function query( string $sql ): ?int;

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getResults( string $sql ): array;

	/**
	 * @return array<string, mixed>|null
	 */
	public function getRow( string $sql ): ?array;

	public function getVar( string $sql ): mixed;

	/**
	 * @param array<string, mixed> $data
	 */
	public function insert( string $table, array $data ): ?int;

	/**
	 * @param array<string, mixed> $data
	 * @param array<string, mixed> $where
	 */
	public function update( string $table, array $data, array $where ): int;

	public function escLike( string $text ): string;

	public function lastError(): string;
}
