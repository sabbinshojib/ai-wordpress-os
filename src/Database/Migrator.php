<?php
/**
 * Migration runner.
 *
 * @package AIOS\Database
 */

declare( strict_types=1 );

namespace AIOS\Database;

use AIOS\Support\StructuredError;

final class Migrator {

	public const MIGRATIONS_OPTION = 'ai_os_migrations';

		/**
		 * @var array<int, class-string<MigrationInterface>>
		 */
	private array $migration_classes = array(
		Schema\Migration_202501010001_CoreTables::class,
		Schema\Migration_202509060001_RateLimits::class,
		Schema\Migration_202509060002_AuditIntegrity::class,
		Schema\Migration_202509070001_ChangeSets::class,
		Schema\Migration_202509070002_OperationJournal::class,
	);

		/**
		 * @param array<int, class-string<MigrationInterface>>|null $classes Override for tests.
		 */
	public function __construct( ?array $classes = null ) {
		if ( null !== $classes ) {
				$this->migration_classes = $classes;
		}
	}

		/**
		 * Run all pending migrations.
		 *
		 * @return array{applied: string[], failed: ?array{version: string, error: string}}
		 */
	public function migrate( Database $db ): array {
			$applied = $this->appliedVersions();
			$results = array(
				'applied' => array(),
				'failed'  => null,
			);

			$pending = $this->pending( $applied );

			foreach ( $pending as $class ) {
					/** @var MigrationInterface $migration */
					$migration = new $class();

				try {
					$success = $migration->up( $db );
				} catch ( \Throwable $e ) {
						$results['failed'] = array(
							'version' => $class::version(),
							'error'   => $e->getMessage(),
						);
						break;
				}

				if ( false === $success ) {
					$error = $db->lastError();
					if ( ! $error ) {
						$error = 'unknown migration failure';
					}
					$results['failed'] = array(
						'version' => $class::version(),
						'error'   => $error,
					);
					break;
				}

					$applied[]            = $class::version();
					$results['applied'][] = $class::version();
					$this->storeApplied( $applied );
			}

			return $results;
	}

		/**
		 * Whether all known migrations have been applied.
		 */
	public function isUpToDate(): bool {
			return array() === $this->pending( $this->appliedVersions() );
	}

		/**
		 * @param string[] $applied
		 * @return array<int, class-string<MigrationInterface>>
		 */
	private function pending( array $applied ): array {
			$pending = array();
		foreach ( $this->migration_classes as $class ) {
				$version = $class::version();
			if ( ! in_array( $version, $applied, true ) ) {
				$pending[] = $class;
			}
		}
			return $pending;
	}

		/**
		 * @return string[]
		 */
	public function appliedVersions(): array {
			$stored = get_option( self::MIGRATIONS_OPTION, array() );
			return is_array( $stored ) ? array_values( array_map( 'strval', $stored ) ) : array();
	}

		/**
		 * @param string[] $versions
		 */
	private function storeApplied( array $versions ): void {
			sort( $versions );
			update_option( self::MIGRATIONS_OPTION, $versions, true );
	}
}
