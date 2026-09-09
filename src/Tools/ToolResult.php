<?php
/**
 * ToolResult — the executor's outward envelope.
 *
 * Carries either structured data or a StructuredError, plus a flag
 * for the approval-required case (a *successful* protocol response
 * whose payload tells the client an approval was queued).
 *
 * @package AIOS\Tools
 */

declare( strict_types=1 );

namespace AIOS\Tools;

use AIOS\Support\StructuredError;

final class ToolResult {

	private bool $ok;
	private bool $isApprovalRequest = false;

	/**
	 * @var array<string, mixed>
	 */
	private array $data;

	private ?StructuredError $error;

	/**
	 * Approval id when isApprovalRequest.
	 */
	private ?int $approvalId = null;

	/**
	 * Redacted/untrusted-wrapped textual content blocks for MCP.
	 *
	 * @var array<int, array{type: string, text: string}>
	 */
	private array $textContent = array();

	private function __construct( bool $ok, array $data, ?StructuredError $error ) {
		$this->ok    = $ok;
		$this->data  = $data;
		$this->error = $error;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function success( array $data = array() ): self {
		return new self( true, $data, null );
	}

	public static function failure( StructuredError $error ): self {
		return new self( false, array(), $error );
	}

	/**
	 * The approval-gated case: nothing executed, an approval record
	 * was created, and the client is told how to proceed.
	 */
	public static function approvalRequired( int $approval_id, array $meta = array() ): self {
		$result                    = new self( true, array(), null );
		$result->isApprovalRequest = true;
		$result->approvalId        = $approval_id;
		$result->data              = $meta;
		return $result;
	}

	public function ok(): bool {
		return $this->ok;
	}

	public function isError(): bool {
		return ! $this->ok;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function data(): array {
		return $this->data;
	}

	public function error(): ?StructuredError {
		return $this->error;
	}

	public function isApprovalRequest(): bool {
		return $this->isApprovalRequest;
	}

	public function approvalId(): ?int {
		return $this->approvalId;
	}

	/**
	 * Attach a textual content block (MCP content[]).
	 */
	public function withText( string $text ): self {
		$this->textContent[] = array(
			'type' => 'text',
			'text' => $text,
		);
		return $this;
	}

	/**
	 * @return array<int, array{type: string, text: string}>
	 */
	public function textContent(): array {
		return $this->textContent;
	}

	/**
	 * Uniform REST payload.
	 *
	 * @return array<string, mixed>
	 */
	public function toArray(): array {
		return array(
			'ok'                => $this->ok,
			'data'              => $this->data,
			'error'             => $this->error?->toArray(),
			'approval_required' => $this->isApprovalRequest,
			'approval_id'       => $this->approvalId,
		);
	}
}
