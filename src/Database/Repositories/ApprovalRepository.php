<?php
/**
 * Approval queue repository.
 *
 * @package AIOS\Database\Repositories
 */

declare( strict_types=1 );

namespace AIOS\Database\Repositories;

use AIOS\Database\Database;

final class ApprovalRepository {

        public const STATUS_PENDING   = 'pending';
        public const STATUS_APPROVED  = 'approved';
        public const STATUS_REJECTED  = 'rejected';
        public const STATUS_EXPIRED   = 'expired';
        public const STATUS_EXECUTED  = 'executed';

        private Database $db;

        public function __construct( Database $db ) {
                $this->db = $db;
        }

        private function table(): string {
                return $this->db->table( Database::TABLE_APPROVALS );
        }

        /**
         * Create a pending approval.
         *
         * @param array<string, mixed> $data {
         *   user_id: int, client: string, tool: string, args: array,
         *   risk: int, reason: string, preview: ?string, expires_minutes: int
         * }
         */
        public function create( array $data ): int {
                $expires = max( 1, (int) ( $data['expires_minutes'] ?? 15 ) );

                $id = $this->db->insert(
                        $this->table(),
                        array(
                                'created_at'  => current_time( 'mysql', true ),
                                'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + $expires * MINUTE_IN_SECONDS ),
                                'user_id'     => (int) ( $data['user_id'] ?? 0 ),
                                'client'      => mb_substr( (string) ( $data['client'] ?? '' ), 0, 64 ),
                                'tool'        => mb_substr( (string) ( $data['tool'] ?? '' ), 0, 190 ),
                                'args_json'   => wp_json_encode( $data['args'] ?? array() ),
                                'risk'        => max( 0, min( 4, (int) ( $data['risk'] ?? 0 ) ) ),
                                'reason'      => mb_substr( (string) ( $data['reason'] ?? '' ), 0, 5000 ),
                                'preview'     => isset( $data['preview'] ) && is_string( $data['preview'] ) ? $data['preview'] : null,
                                'status'      => self::STATUS_PENDING,
                        )
                );
                return $id ?? 0;
        }

        /**
         * Fetch + hydrate one approval.
         *
         * @return array<string, mixed>|null
         */
        public function get( int $id ): ?array {
                $sql = $this->db->prepare( 'SELECT * FROM ' . $this->table() . ' WHERE id = %d', $id );
                $row = $this->db->getRow( $sql );
                return $row ? $this->hydrate( $row ) : null;
        }

        /**
         * Pending approvals (admin queue). Pending items past their expiry
         * are lazily marked expired before listing.
         *
         * @return array<int, array<string, mixed>>
         */
        public function pending( int $limit = 50 ): array {
                $this->expireStale();

                $sql = $this->db->prepare(
                        'SELECT * FROM ' . $this->table() . ' WHERE status = %s ORDER BY id DESC LIMIT %d',
                        self::STATUS_PENDING,
                        max( 1, min( 200, $limit ) )
                );
                return array_map( array( $this, 'hydrate' ), $this->db->getResults( $sql ) );
        }

        /**
         * Recent decisions (approved/rejected/expired/executed) history.
         *
         * @return array<int, array<string, mixed>>
         */
        public function history( int $limit = 50 ): array {
                $sql = $this->db->prepare(
                        'SELECT * FROM ' . $this->table() . ' WHERE status != %s ORDER BY id DESC LIMIT %d',
                        self::STATUS_PENDING,
                        max( 1, min( 200, $limit ) )
                );
                return array_map( array( $this, 'hydrate' ), $this->db->getResults( $sql ) );
        }

        /**
         * Pending count (dashboard badge).
         */
        public function pendingCount(): int {
                $sql = $this->db->prepare( 'SELECT COUNT(*) FROM ' . $this->table() . ' WHERE status = %s', self::STATUS_PENDING );
                return (int) $this->db->getVar( $sql );
        }

        /**
         * Atomically claim a pending approval for decision, enforcing TTL.
         *
         * Returns the hydrated approval when it is still pending and
         * unexpired; null when it was decided/expired meanwhile.
         *
         * @return array<string, mixed>|null
         */
        public function claimPending( int $id, string $decision, int $decided_by ): ?array {
                $approval = $this->get( $id );
                if ( null === $approval ) {
                        return null;
                }
                if ( self::STATUS_PENDING !== $approval['status'] ) {
                        return null;
                }
                if ( strtotime( (string) $approval['expires_at'] ) < time() ) {
                        $this->markExpired( $id );
                        return null;
                }

                $decision_status = self::STATUS_REJECTED === $decision ? self::STATUS_REJECTED : self::STATUS_APPROVED;
                $updated = $this->db->update(
                        $this->table(),
                        array(
                                'status'     => $decision_status,
                                'decided_by' => $decided_by,
                                'decided_at' => current_time( 'mysql', true ),
                        ),
                        array( 'id' => $id, 'status' => self::STATUS_PENDING )
                );

                if ( 0 === $updated ) {
                        return null; // Raced with another decision.
                }

                $approval['status']     = $decision_status;
                $approval['decided_by'] = $decided_by;
                $approval['decided_at'] = current_time( 'mysql', true );
                return $approval;
        }

        /**
         * Mark a pending approval as approved AND executed with results.
         */
        public function markExecuted( int $id, string $execution_status, ?string $result_json, ?int $log_id ): void {
                $this->db->update(
                        $this->table(),
                        array(
                                'status'            => self::STATUS_EXECUTED,
                                'execution_status'  => mb_substr( $execution_status, 0, 20 ),
                                'execution_result'  => $result_json,
                                'execution_log_id'  => $log_id,
                        ),
                        array( 'id' => $id )
                );
        }

        public function markExpired( int $id ): void {
                $this->db->update(
                        $this->table(),
                        array( 'status' => self::STATUS_EXPIRED ),
                        array( 'id' => $id, 'status' => self::STATUS_PENDING )
                );
        }

        /**
         * Bulk: ids of every still-pending approval at/below a risk level.
         *
         * @return int[]
         */
        public function pendingIdsAtOrBelow( int $risk ): array {
                $this->expireStale();
                $sql = $this->db->prepare(
                        'SELECT id FROM ' . $this->table() . ' WHERE status = %s AND risk <= %d',
                        self::STATUS_PENDING,
                        max( 0, min( 4, $risk ) )
                );
                return array_map( 'intval', array_column( $this->db->getResults( $sql ), 'id' ) );
        }

        private function expireStale(): void {
                $sql = $this->db->prepare(
                        'UPDATE ' . $this->table() . ' SET status = %s WHERE status = %s AND expires_at < UTC_TIMESTAMP()',
                        self::STATUS_EXPIRED,
                        self::STATUS_PENDING
                );
                $this->db->query( $sql );
        }

        /**
         * @param array<string, mixed> $row
         * @return array<string, mixed>
         */
        private function hydrate( array $row ): array {
                $row['id']        = (int) ( $row['id'] ?? 0 );
                $row['user_id']   = (int) ( $row['user_id'] ?? 0 );
                $row['risk']      = (int) ( $row['risk'] ?? 0 );
                $row['status']    = (string) ( $row['status'] ?? 'pending' );
                $row['args']      = null === ( $row['args_json'] ?? null ) ? array() : ( json_decode( (string) $row['args_json'], true ) ?: array() );
                $row['decided_by'] = null === ( $row['decided_by'] ?? null ) ? null : (int) $row['decided_by'];
                $row['decided_at'] = $row['decided_at'] ?? null;
                return $row;
        }
}
