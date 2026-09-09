<?php
/**
 * Approvals endpoints: queue, decide, bulk-safe (spec §8).
 *
 * @package AIOS\Rest\Controllers
 */

declare( strict_types=1 );

namespace AIOS\Rest\Controllers;

use AIOS\Audit\AuditLogger;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Security\PermissionEngine;
use AIOS\Settings\Settings;
use AIOS\Tools\ToolRegistry;
use AIOS\Tools\ToolExecutor;
use WP_REST_Request;
use WP_REST_Response;

final class ApprovalsController extends AbstractController {

	public function register( string $rest_namespace ): void {
		register_rest_route(
			$rest_namespace,
			'/approvals',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'list' ),
				'permission_callback' => array( $this, 'canApprove' ),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/approvals/(?P<id>\d+)/approve',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approve' ),
				'permission_callback' => array( $this, 'canApprove' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/approvals/(?P<id>\d+)/reject',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'reject' ),
				'permission_callback' => array( $this, 'canApprove' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);

		register_rest_route(
			$rest_namespace,
			'/approvals/approve-safe',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'approveSafe' ),
				'permission_callback' => array( $this, 'canApprove' ),
			)
		);
	}

	/**
	 * GET /approvals — pending + recent history.
	 */
	public function list( /* WP_REST_Request $request */ ): WP_REST_Response {
		/** @var ApprovalRepository $approvals */
		$approvals = $this->container->get( ApprovalRepository::class );

		return $this->json(
			array(
				'pending' => $this->shape( $approvals->pending( 50 ) ),
				'history' => $this->shape( $approvals->history( 50 ) ),
			)
		);
	}

	/**
	 * POST /approvals/{id}/approve — claim + execute.
	 */
	public function approve( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_nonce',
						'message' => 'Nonce verification failed.',
					),
				),
				403
			);
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_unauthenticated',
						'message' => 'Authentication required.',
					),
				),
				401
			);
		}

		/** @var ApprovalRepository $approvals */
		$approvals = $this->container->get( ApprovalRepository::class );

		$approval = $approvals->claimPending( $id, 'approved', (int) $user->ID );
		if ( null === $approval ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'approval.claim_failed',
						'message' => 'Approval is missing, already decided, or expired.',
					),
				),
				409
			);
		}

		// Execute through the standard pipeline (permissions still apply).
		/** @var ToolRegistry $tools */
		$tools = $this->container->get( ToolRegistry::class );
		/** @var ToolExecutor $executor */
		$executor = $this->container->get( ToolExecutor::class );

		$tool = $tools->get( (string) $approval['tool'] );
		if ( null === $tool ) {
			$approvals->markExecuted( $id, 'error', wp_json_encode( array( 'error' => 'tool unknown' ) ), null );
			return $this->json(
				array(
					'error' => array(
						'code'    => 'tool.unknown',
						'message' => 'The approved tool no longer exists.',
					),
				),
				404
			);
		}

		$result = $executor->executeApproved( $tool, (array) ( $approval['args'] ?? array() ), $user, $id );

		// Audit the decision itself.
		/** @var AuditLogger $audit */
		$audit = $this->container->get( AuditLogger::class );
		$audit->log(
			array(
				'user'        => $user,
				'client'      => 'rest',
				'tool'        => (string) $approval['tool'],
				'action'      => 'approval.approved',
				'args'        => (array) ( $approval['args'] ?? array() ),
				'risk'        => (int) $approval['risk'],
				'status'      => $result->ok() ? AuditLogger::STATUS_OK : AuditLogger::STATUS_ERROR,
				'error'       => $result->error()?->message(),
				'approval_id' => $id,
			)
		);

		return $this->json(
			array(
				'approved' => true,
				'result'   => $result->toArray(),
			)
		);
	}

	/**
	 * POST /approvals/{id}/reject.
	 */
	public function reject( WP_REST_Request $request ): WP_REST_Response {
		$id = (int) $request->get_param( 'id' );

		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_nonce',
						'message' => 'Nonce verification failed.',
					),
				),
				403
			);
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_unauthenticated',
						'message' => 'Authentication required.',
					),
				),
				401
			);
		}

		/** @var ApprovalRepository $approvals */
		$approvals = $this->container->get( ApprovalRepository::class );
		$approval  = $approvals->claimPending( $id, 'rejected', (int) $user->ID );

		if ( null === $approval ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'approval.claim_failed',
						'message' => 'Approval is missing, already decided, or expired.',
					),
				),
				409
			);
		}

		/** @var AuditLogger $audit */
		$audit = $this->container->get( AuditLogger::class );
		$audit->log(
			array(
				'user'        => $user,
				'client'      => 'rest',
				'tool'        => (string) $approval['tool'],
				'action'      => 'approval.rejected',
				'args'        => (array) ( $approval['args'] ?? array() ),
				'risk'        => (int) $approval['risk'],
				'status'      => AuditLogger::STATUS_REJECTED,
				'approval_id' => $id,
			)
		);

		return $this->json( array( 'rejected' => true ) );
	}

	/**
	 * POST /approvals/approve-safe — "approve all safe changes":
	 * everything pending at or below the active mode's auto level.
	 */
	public function approveSafe( WP_REST_Request $request ): WP_REST_Response {
		if ( ! $this->verifyNonce( $request ) ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_nonce',
						'message' => 'Nonce verification failed.',
					),
				),
				403
			);
		}

		$user = wp_get_current_user();
		if ( ! $user->exists() ) {
			return $this->json(
				array(
					'error' => array(
						'code'    => 'ai_os_unauthenticated',
						'message' => 'Authentication required.',
					),
				),
				401
			);
		}

		/** @var PermissionEngine $permissions */
		$permissions = $this->container->get( PermissionEngine::class );
		/** @var ApprovalRepository $approvals */
		$approvals = $this->container->get( ApprovalRepository::class );
		/** @var ToolRegistry $tools */
		$tools = $this->container->get( ToolRegistry::class );
		/** @var ToolExecutor $executor */
		$executor = $this->container->get( ToolExecutor::class );

		$threshold = $permissions->ceilingFor( $user, null );
		$ids       = $approvals->pendingIdsAtOrBelow( $threshold );

		$decided     = 0;
		$executed_ok = 0;
		$failed      = array();

		foreach ( $ids as $id ) {
			$approval = $approvals->claimPending( $id, 'approved', (int) $user->ID );
			if ( null === $approval ) {
				continue;
			}
			++$decided;

			$tool = $tools->get( (string) $approval['tool'] );
			if ( null === $tool ) {
				$failed[] = array(
					'id'     => $id,
					'reason' => 'tool unknown',
				);
				$approvals->markExecuted( $id, 'error', wp_json_encode( array( 'error' => 'tool unknown' ) ), null );
				continue;
			}

			$result = $executor->executeApproved( $tool, (array) ( $approval['args'] ?? array() ), $user, $id );
			if ( $result->ok() ) {
				++$executed_ok;
			} else {
				$failed[] = array(
					'id'     => $id,
					'reason' => $result->error()?->message() ?? 'execution failed',
				);
			}
		}

		return $this->json(
			array(
				'approved'    => $decided,
				'executed_ok' => $executed_ok,
				'failed'      => $failed,
				'threshold'   => $threshold,
			)
		);
	}

	/**
	 * Shape approval rows for the UI (args redacted).
	 *
	 * @param array<int, array<string, mixed>> $rows
	 * @return array<int, array<string, mixed>>
	 */
	private function shape( array $rows ): array {
		$shaped = array();
		foreach ( $rows as $row ) {
			unset( $row['args_json'], $row['execution_result'] );
			$row['args'] = \AIOS\Support\Sanitize::redact( (array) ( $row['args'] ?? array() ) );
			$shaped[]    = $row;
		}
		return $shaped;
	}
}
