<?php
/**
 * Tool execution observability repository.
 *
 * @package AIOS\Database\Repositories
 */

declare( strict_types=1 );

namespace AIOS\Database\Repositories;

use AIOS\Database\Database;
use AIOS\Support\Strings;

final class ToolExecutionRepository {

	private Database $db;

	public function __construct( Database $db ) {
		$this->db = $db;
	}

	private function table(): string {
		return $this->db->table( Database::TABLE_TOOL_EXECUTIONS );
	}

	public function insert( string $tool, int $user_id, string $client, bool $success, int $duration_ms, string $error_code = '' ): int {
		$id = $this->db->insert(
			$this->table(),
			array(
				'occurred_at'  => current_time( 'mysql', true ),
				'tool'         => Strings::truncate( $tool, 190 ),
				'user_id'      => $user_id,
				'client'       => Strings::truncate( $client, 64 ),
				'success'      => $success ? 1 : 0,
				'duration_ms'  => max( 0, $duration_ms ),
				'error_code'   => Strings::truncate( $error_code, 120 ),
			)
		);
		return $id ?? 0;
	}

	/**
	 * Top-used tools + error rates (dashboard observability card).
	 *
	 * @return array<int, array{tool: string, calls: int, errors: int, avg_ms: float}>
	 */
	public function toolStats( int $days = 7, int $limit = 12 ): array {
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$sql   = $this->db->prepare(
			'SELECT tool,
				COUNT(*) AS calls,
				SUM(1 - success) AS errors,
				AVG(duration_ms) AS avg_ms
			FROM ' . $this->table() . '
			WHERE occurred_at >= %s
			GROUP BY tool
			ORDER BY calls DESC
			LIMIT %d',
			$since,
			max( 1, min( 50, $limit ) )
		);

		$rows = array();
		foreach ( $this->db->getResults( $sql ) as $row ) {
			$rows[] = array(
				'tool'   => (string) $row['tool'],
				'calls'  => (int) $row['calls'],
				'errors' => (int) ( $row['errors'] ?? 0 ),
				'avg_ms' => (float) $row['avg_ms'],
			);
		}
		return $rows;
	}

	/**
	 * Overall health counters for the last N days.
	 *
	 * @return array{calls: int, errors: int, avg_ms: float}
	 */
	public function health( int $days = 7 ): array {
		$since = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$sql   = $this->db->prepare(
			'SELECT COUNT(*) AS calls, SUM(1 - success) AS errors, COALESCE(AVG(duration_ms), 0) AS avg_ms
			FROM ' . $this->table() . ' WHERE occurred_at >= %s',
			$since
		);
		$row   = $this->db->getRow( $sql ) ?? array();
		return array(
			'calls'  => (int) ( $row['calls'] ?? 0 ),
			'errors' => (int) ( $row['errors'] ?? 0 ),
			'avg_ms' => (float) ( $row['avg_ms'] ?? 0 ),
		);
	}

	/**
	 * Retention purge (runs alongside the audit purge).
	 */
	public function purgeOlderThan( int $days ): int {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
		$sql    = $this->db->prepare( 'DELETE FROM ' . $this->table() . ' WHERE occurred_at < %s', $cutoff );
		return (int) $this->db->query( $sql );
	}
}
