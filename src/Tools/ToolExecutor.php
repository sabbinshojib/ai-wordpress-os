<?php
/**
 * ToolExecutor — the single execution pipeline (spec §5/§7/§8/§9).
 *
 * Every tool invocation, from MCP, REST or the approval queue, runs
 * through exactly this pipeline:
 *
 *   1. resolve tool + availability
 *   2. validate arguments against the tool input schema
 *   3. permission check (level ceiling + ability permission callback)
 *   4. escalation blocklist (tools may never touch AI OS security state)
 *   5. rate limit
 *   6. approval gate (risk level vs active mode) → create approval,
 *      return approval_required; the ability is NOT executed
 *   7. execute ability callback (WordPress APIs only)
 *   8. audit log entry + observability record
 *
 * The executor is deliberately boring: no branching by transport, no
 * duplicated logic. That is what keeps REST, MCP and approvals
 * consistent by construction.
 *
 * @package AIOS\Tools
 */

declare( strict_types=1 );

namespace AIOS\Tools;

use AIOS\Abilities\AbilityRegistry;
use AIOS\Audit\AuditLogger;
use AIOS\Database\Repositories\ApprovalRepository;
use AIOS\Database\Repositories\ToolExecutionRepository;
use AIOS\Security\Authenticator;
use AIOS\Security\PermissionEngine;
use AIOS\Security\RateLimiter;
use AIOS\Settings\Settings;
use AIOS\Support\Sanitize;
use AIOS\Support\StructuredError;
use AIOS\Support\Validator;
use Throwable;
use WP_User;

final class ToolExecutor {

		/**
		 * Operations the agent may NEVER perform regardless of grants
		 * (spec §49 — AI safety). Phase 1 has no tools that could do
		 * these things; this list exists so that any future tool or
		 * third-party registration aimed at these targets is blocked at
		 * the executor level, not by convention.
		 *
		 * @var array<string, string[]>
		 */
	private const ESCALATION_BLOCKLIST = array(
		// tool name prefix → blocked arg values.
		'users.create'    => array(),
		'users.update'    => array( 'role' ),
		'settings.update' => array(),
		'options.update'  => array(
			'ai_os_settings',
			'ai_os_user_level_grants',
			'ai_os_migrations',
		),
	);

	private ToolRegistry $tools;
	private AbilityRegistry $abilities;
	private PermissionEngine $permissions;
	private AuditLogger $audit;
	private ToolExecutionRepository $executions;
	private ApprovalRepository $approvals;
	private Settings $settings;
	private Validator $validator;
	private RateLimiter $rateLimiter;

	public function __construct(
		ToolRegistry $tools,
		AbilityRegistry $abilities,
		PermissionEngine $permissions,
		AuditLogger $audit,
		ToolExecutionRepository $executions,
		ApprovalRepository $approvals,
		Settings $settings,
		RateLimiter $rateLimiter
	) {
			$this->tools       = $tools;
			$this->abilities   = $abilities;
			$this->permissions = $permissions;
			$this->audit       = $audit;
			$this->executions  = $executions;
			$this->approvals   = $approvals;
			$this->settings    = $settings;
			$this->rateLimiter = $rateLimiter;
			$this->validator   = new Validator();
	}

		/**
		 * Execute a tool call for an authenticated principal.
		 *
		 * @param string            $tool_name e.g. "content.create_post".
		 * @param array<string, mixed> $arguments
		 * @param WP_User          $user      The acting user.
		 * @param string           $client    Transport label ("mcp", "rest", "approval").
		 * @param ?Authenticator   $auth      Optional, for key ceilings + IP.
		 */
	public function execute( string $tool_name, array $arguments, WP_User $user, string $client = 'mcp', ?Authenticator $auth = null ): ToolResult {
			$start  = microtime( true );
			$result = $this->run( $tool_name, $arguments, $user, $client, $auth );
			$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

			// Observability + audit happen for every outcome except the
			// pre-resolution failures (they still get audited below).
			$tool = $this->tools->get( $tool_name );
			$risk = $tool?->riskLevel() ?? 0;

			$audit_status = match ( true ) {
					$result->isApprovalRequest() => AuditLogger::STATUS_APPROVAL,
					! $result->ok()              => AuditLogger::STATUS_ERROR,
					default                      => AuditLogger::STATUS_OK,
			};
			$blocked = null !== $result->error() && 'permission' === $result->error()->type()
					&& str_contains( $result->error()->code(), 'blocked' );
		if ( $blocked ) {
				$audit_status = AuditLogger::STATUS_BLOCKED;
		}

			$this->audit->log(
				array(
					'user'             => $user,
					'client'           => $client,
					'principal_type'   => $auth?->method ?? 'user',
					'tool'             => $tool_name,
					'action'           => 'execute',
					'args'             => $arguments,
					'risk'             => $risk,
					'status'           => $audit_status,
					'error'            => $result->error()?->message(),
					'affected_objects' => null,
					'duration_ms'      => $ms,
					'ip'               => $auth?->clientIp(),
					'approval_id'      => $result->approvalId(),
				)
			);

			$this->executions->insert(
				$tool_name,
				(int) $user->ID,
				$client,
				$result->ok() && ! $result->isApprovalRequest(),
				$ms,
				$result->error()?->code() ?? ( $result->isApprovalRequest() ? 'approval_required' : '' )
			);

			return $result;
	}

		/**
		 * The pipeline itself.
		 *
		 * @param array<string, mixed> $arguments
		 */
	private function run( string $tool_name, array $arguments, WP_User $user, string $client, ?Authenticator $auth ): ToolResult {
			// 1. Resolve tool + availability.
			$tool = $this->tools->get( $tool_name );
		if ( null === $tool ) {
				return ToolResult::failure(
					StructuredError::notFound( 'tool.unknown', "Unknown tool [{$tool_name}]." )
				);
		}
		if ( ! $tool->isAvailable() ) {
				return ToolResult::failure(
					StructuredError::execution( 'tool.unavailable', "Tool [{$tool_name}] is not available in this environment (missing integration or capability)." )
				);
		}

			$ability = $this->abilities->get( $tool->abilityName() );
		if ( null === $ability ) {
				return ToolResult::failure(
					StructuredError::execution( 'tool.ability_missing', "Tool [{$tool_name}] has no backing ability." )
				);
		}

			// 2. Baseline access.
		if ( ! PermissionEngine::canUse( $user ) ) {
				return ToolResult::failure(
					StructuredError::permission( 'permission.no_ai_os_access', 'Your account is not permitted to use AI WordPress OS.' )
				);
		}

			// 3. Escalation blocklist.
			$block_error = $this->checkEscalationBlocklist( $tool_name, $arguments );
		if ( null !== $block_error ) {
				return ToolResult::failure( $block_error );
		}

			// 4. Validate arguments.
			$violations = $this->validator->validateArguments( $arguments, $tool->inputSchema() );
		if ( ! empty( $violations ) ) {
				return ToolResult::failure(
					StructuredError::validation( 'tool.input_invalid', 'Tool arguments failed validation.', array( 'violations' => $violations ) )
				);
		}

			// 5. Rate limit.
			$this->rateLimiter->configure( $this->settings->rateLimitExecutions() );
			$principal = 'user:' . (int) $user->ID;
		if ( ! $this->rateLimiter->allow( $principal, 'executions' ) ) {
				return ToolResult::failure(
					StructuredError::rateLimit( 'Too many tool executions. Slow down.', $this->rateLimiter->meta( $principal, 'executions' ) )
				);
		}

			// 6. Approval gate — evaluated BEFORE the auto-execution
			//    ceiling: the ceiling governs what runs unattended;
			//    the approval queue is exactly how humans authorize
			//    beyond it (spec §7/§8/§59).
			$needs_approval = $tool->requiresApproval()
					&& $this->permissions->requiresApproval( $tool->riskLevel() )
					&& ! $this->permissions->autoApproveSafe();

			/**
			 * Filter the approval decision for a tool call (integrations
			 * may add stricter rules; they cannot weaken the engine's).
			 *
			 * @param bool  $needs_approval
			 * @param Tool  $tool
			 * @param array $arguments
			 * @param WP_User $user
			 */
			$needs_approval = apply_filters( 'ai_os_requires_approval', $needs_approval, $tool, $arguments, $user );

		if ( $needs_approval ) {
				// API key scoping caps even approval REQUESTS: a key
				// bound to level 1 may not queue level 2+ work.
				$key_max = 'api_key' === ( $auth?->method ?? '' ) ? $auth->key_max_level : null;
			if ( null !== $key_max && $tool->riskLevel() > $key_max ) {
					return ToolResult::failure( $this->permissions->denied( $user, $tool_name, $tool->riskLevel(), $key_max ) );
			}
				// The requesting principal must still satisfy the
				// ability's fine-grained permission callback.
			if ( ! $ability->checkPermission( $user, $arguments ) ) {
					return ToolResult::failure(
						StructuredError::permission( 'permission.ability_denied', 'This action is not permitted for your account in this context.' )
					);
			}
				return $this->queueApproval( $tool, $ability, $arguments, $user, $client, $auth );
		}

			// 7. Auto-execution: full ceiling (mode + WP caps + key).
			$key_max = 'api_key' === ( $auth?->method ?? '' ) ? $auth->key_max_level : null;
		if ( ! $this->permissions->can( $user, $tool->permissionLevel(), $key_max ) ) {
				return ToolResult::failure( $this->permissions->denied( $user, $tool_name, $tool->permissionLevel(), $key_max ) );
		}
		if ( ! $ability->checkPermission( $user, $arguments ) ) {
				return ToolResult::failure(
					StructuredError::permission( 'permission.ability_denied', 'This action is not permitted for your account in this context.' )
				);
		}

			// 8. Execute.
			return $this->runAbility( $tool, $ability, $arguments, $user, $client, $auth, null );
	}

		/**
		 * Execute an approved action. Called by the approvals controller.
		 * The approval MUST already be claimed (approved).
		 *
		 * @param array<string, mixed> $arguments
		 */
	public function executeApproved( Tool $tool, array $arguments, WP_User $deciding_user, int $approval_id ): ToolResult {
			$start  = microtime( true );
			$result = $this->runApproved( $tool, $arguments, $deciding_user, $approval_id );
			$ms     = (int) round( ( microtime( true ) - $start ) * 1000 );

			// Approved executions carry their own audit entry so the
			// decision → execution story is complete no matter which
			// surface claimed the approval (REST, admin, bulk).
			$this->audit->log(
				array(
					'user'           => $deciding_user,
					'client'         => 'approval',
					'principal_type' => 'user',
					'tool'           => $tool->name(),
					'action'         => 'execute_approved',
					'args'           => $arguments,
					'risk'           => $tool->riskLevel(),
					'status'         => $result->ok() ? AuditLogger::STATUS_OK : AuditLogger::STATUS_ERROR,
					'error'          => $result->error()?->message(),
					'duration_ms'    => $ms,
					'approval_id'    => $approval_id,
				)
			);

			$this->executions->insert(
				$tool->name(),
				(int) $deciding_user->ID,
				'approval',
				$result->ok(),
				$ms,
				$result->error()?->code() ?? ''
			);

			return $result;
	}

		/**
		 * The approved-execution pipeline (permission + re-validation).
		 *
		 * @param array<string, mixed> $arguments
		 */
	private function runApproved( Tool $tool, array $arguments, WP_User $deciding_user, int $approval_id ): ToolResult {
			$ability = $this->abilities->get( $tool->abilityName() );
		if ( null === $ability ) {
				return ToolResult::failure( StructuredError::execution( 'tool.ability_missing', 'Tool has no backing ability.' ) );
		}

			// The deciding user must satisfy the CAPABILITY ceiling — an
			// explicit human approval authorizes beyond the mode ceiling
			// (that is what approval is for) but never beyond the user's
			// real WordPress capabilities.
		if ( $tool->permissionLevel() > $this->permissions->capabilityCeilingFor( $deciding_user ) ) {
				return ToolResult::failure( $this->permissions->denied( $deciding_user, $tool->name(), $tool->permissionLevel(), null ) );
		}

			// Re-validate arguments (approval payloads are stored data).
			$violations = $this->validator->validateArguments( $arguments, $tool->inputSchema() );
		if ( ! empty( $violations ) ) {
				return ToolResult::failure(
					StructuredError::validation( 'tool.input_invalid', 'Stored approval arguments failed re-validation.', array( 'violations' => $violations ) )
				);
		}

			return $this->runAbility( $tool, $ability, $arguments, $deciding_user, 'approval', null, $approval_id );
	}

		/**
		 * @param array<string, mixed> $arguments
		 */
	private function runAbility( Tool $tool, $ability, array $arguments, WP_User $user, string $client, ?Authenticator $auth, ?int $approval_id ): ToolResult {
		try {
				$ability_result = $ability->execute( $arguments, $user );
		} catch ( Throwable $e ) {
				// Structured error outward, detail inward (spec §43).
				\AIOS\Logging\DebugLog::internal( $e, 'tool ' . $tool->name() );
				return ToolResult::failure(
					StructuredError::execution( 'tool.internal_error', 'The tool execution failed unexpectedly.' )
				);
		}

		if ( ! $ability_result->ok() ) {
				return ToolResult::failure( $ability_result->getError() ?? StructuredError::execution( 'tool.unknown_error', 'Unknown tool failure.' ) );
		}

			$result = ToolResult::success( $ability_result->data() );

		if ( null !== $approval_id ) {
				// Execution via the approval queue: complete the record.
				$this->approvals->markExecuted(
					$approval_id,
					'ok',
					wp_json_encode( $result->toArray() ),
					null
				);
		}

			return $result;
	}

		/**
		 * Queue an approval instead of executing (spec §8).
		 *
		 * @param array<string, mixed> $arguments
		 */
	private function queueApproval( Tool $tool, $ability, array $arguments, WP_User $user, string $client, ?Authenticator $auth ): ToolResult {
			$reason = sprintf(
				'Tool %s requires permission level %d (%s) which needs human approval under the %s security mode. The action has NOT been executed.',
				$tool->name(),
				$tool->riskLevel(),
				$tool->riskName(),
				$this->settings->mode()
			);

			$approval_id = $this->approvals->create(
				array(
					'user_id'         => (int) $user->ID,
					'client'          => $client,
					'tool'            => $tool->name(),
					'args'            => $arguments,
					'risk'            => $tool->riskLevel(),
					'reason'          => $reason,
					'preview'         => $this->buildPreview( $tool, $ability, $arguments ),
					'expires_minutes' => $this->settings->approvalTtlMinutes(),
				)
			);

		if ( 0 === $approval_id ) {
				return ToolResult::failure( StructuredError::execution( 'approval.store_failed', 'Could not create the approval request.' ) );
		}

			return ToolResult::approvalRequired(
				$approval_id,
				array(
					'approval_id' => $approval_id,
					'tool'        => $tool->name(),
					'risk_level'  => $tool->riskLevel(),
					'risk_name'   => $tool->riskName(),
					'reason'      => $reason,
					'expires_at'  => gmdate( 'c', time() + $this->settings->approvalTtlMinutes() * 60 ),
					'next_step'   => 'An administrator must approve this action in WP Admin → AI OS → Approvals, or via the approvals REST endpoints. The action has NOT been executed.',
				)
			);
	}

		/**
		 * Human-readable preview for the approval card.
		 *
		 * @param array<string, mixed> $arguments
		 */
	private function buildPreview( Tool $tool, $ability, array $arguments ): string {
			$redacted = Sanitize::redact( $arguments );
			$pretty   = wp_json_encode( $redacted, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! $pretty ) {
			$pretty = '{}';
		}
			return "Tool: {$tool->name()}\nArguments:\n{$pretty}";
	}

		/**
		 * Blocklist check (spec §49). Returns a structured error when the
		 * call is blocked, null otherwise.
		 *
		 * @param array<string, mixed> $arguments
		 */
	private function checkEscalationBlocklist( string $tool_name, array $arguments ): ?StructuredError {
			$blocked = false;

		foreach ( self::ESCALATION_BLOCKLIST as $prefix => $watched ) {
			if ( ! str_starts_with( $tool_name, $prefix ) ) {
				continue;
			}
			if ( array() === $watched ) {
					// Whole tool is blocklisted in Phase 1 (users.create,
					// settings update surfaces).
					$blocked = true;
					break;
			}
			foreach ( $watched as $field ) {
				if ( array_key_exists( $field, $arguments ) ) {
						$blocked = true;
						break 2;
				}
			}
		}

		if ( $blocked ) {
				return StructuredError::security(
					'permission.escalation_blocked',
					'This operation would change AI OS security state or administrative roles and is blocked by policy. It can only be performed by a logged-in administrator through the WordPress admin UI.'
				);
		}

			return null;
	}
}
