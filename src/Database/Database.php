<?php
/**
 * wpdb access layer: table names, prefixing, and a prepare() facade.
 *
 * All SQL in the plugin goes through this class. Repositories are the
 * only callers — controllers/tools never talk to wpdb directly.
 *
 * @package AIOS\Database
 */

declare( strict_types=1 );

namespace AIOS\Database;

final class Database {

        public const TABLE_AUDIT_LOGS      = 'audit_logs';
        public const TABLE_TOOL_EXECUTIONS = 'tool_executions';
        public const TABLE_APPROVALS       = 'approvals';
        public const TABLE_API_KEYS        = 'api_keys';
        public const TABLE_RATE_LIMITS     = 'rate_limits';

        /**
         * Canonical table list (migration + uninstall reference).
         *
         * @var string[]
         */
        public const ALL_TABLES = array(
                self::TABLE_AUDIT_LOGS,
                self::TABLE_TOOL_EXECUTIONS,
                self::TABLE_APPROVALS,
                self::TABLE_API_KEYS,
                self::TABLE_RATE_LIMITS,
        );

        /**
         * Fully-prefixed table name.
         *
         * e.g. wp_ai_os_audit_logs
         */
        public function table( string $short_name ): string {
                global $wpdb;
                return $wpdb->prefix . 'ai_os_' . $short_name;
        }

        /**
         * Charset/collation clause for CREATE TABLE statements.
         */
        public function charsetCollate(): string {
                global $wpdb;
                return $wpdb->get_charset_collate();
        }

        /**
         * Prepared query facade.
         *
         * @param string   $sql    With placeholders.
         * @param mixed ...$values Bound values.
         */
        public function prepare( string $sql, ...$values ): string {
                global $wpdb;
                if ( array() === $values ) {
                        return $sql;
                }
                $prepared = $wpdb->prepare( $sql, ...$values );
                return is_string( $prepared ) ? $prepared : '';
        }

        /**
         * Run a prepared query, return affected/inserted semantics.
         *
         * @return int|null Insert ID on INSERT (when generated), else row count.
         */
        public function query( string $sql ): ?int {
                global $wpdb;
                $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- callers pass prepared SQL.
                if ( '0' !== (string) $wpdb->insert_id && 0 !== (int) $wpdb->insert_id ) {
                        return (int) $wpdb->insert_id;
                }
                return (int) $wpdb->rows_affected;
        }

        /**
         * SELECT rows as associative arrays.
         *
         * @return array<int, array<string, mixed>>
         */
        public function getResults( string $sql ): array {
                global $wpdb;
                $rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                return is_array( $rows ) ? $rows : array();
        }

        /**
         * SELECT a single row.
         *
         * @return array<string, mixed>|null
         */
        public function getRow( string $sql ): ?array {
                global $wpdb;
                $row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
                return is_array( $row ) ? $row : null;
        }

        /**
         * Scalar fetch.
         */
        public function getVar( string $sql ): mixed {
                global $wpdb;
                return $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        }

        /**
         * Insert helper.
         *
         * @param array<string, mixed> $data
         */
        public function insert( string $table, array $data ): ?int {
                global $wpdb;
                $ok = $wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB
                if ( false === $ok ) {
                        return null;
                }
                return (int) $wpdb->insert_id;
        }

        /**
         * Update helper.
         *
         * @param array<string, mixed> $data
         * @param array<string, mixed> $where
         */
        public function update( string $table, array $data, array $where ): int {
                global $wpdb;
                $ok = $wpdb->update( $table, $data, $where ); // phpcs:ignore WordPress.DB
                return false === $ok ? 0 : (int) $ok;
        }

        /**
         * Escape a value for a LIKE clause (escapeshaped LIKE wildcards).
         */
        public function escLike( string $text ): string {
                global $wpdb;
                if ( method_exists( $wpdb, 'esc_like' ) ) {
                        return (string) $wpdb->esc_like( $text );
                }
                return addcslashes( $text, '_%\\' );
        }

        /**
         * Latest DB error (internal logging only, never returned to clients).
         */
        public function lastError(): string {
                global $wpdb;
                return is_string( $wpdb->last_error ) ? $wpdb->last_error : '';
        }
}
