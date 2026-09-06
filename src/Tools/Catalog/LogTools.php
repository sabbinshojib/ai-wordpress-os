<?php
/**
 * AI OS audit log tools (spec §4: logs.list / logs.get).
 *
 * The AI can review its own activity history — an important trust
 * feature — but only principals with management capability.
 *
 * @package AIOS\Tools\Catalog
 */

declare( strict_types=1 );

namespace AIOS\Tools\Catalog;

use AIOS\Abilities\Ability;
use AIOS\Abilities\AbilityResult;
use AIOS\Abilities\AbilityRegistry;
use AIOS\Tools\Tool;
use AIOS\Tools\ToolRegistry;
use AIOS\Audit\AuditLogger;

final class LogTools implements CatalogProviderInterface {

	public static function id(): string {
		return 'logs';
	}

	public static function isActive(): bool {
		return true;
	}

	public static function register( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		self::registerList( $abilities, $tools );
		self::registerGet( $abilities, $tools );
	}

	// --------------------------------------------------------------- logs.list

	private static function registerList( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register( Ability::make(
			array(
				'name'        => 'logs.list',
				'description' => 'Query the AI OS audit log: filter by user, tool, status (ok/error/blocked/rejected), risk level, date. Redacted.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'tool'    => array( 'type' => 'string', 'maxLength' => 190 ),
						'status'  => array( 'type' => 'string', 'enum' => array( 'ok', 'error', 'blocked', 'rejected', 'approval_required' ) ),
						'risk'    => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 4 ),
						'since'   => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}', 'description' => 'ISO date (UTC) lower bound.' ),
						'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
						'page'    => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'additionalProperties' => false,
				),
				'level'              => 0,
				'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'manage_options' ) || $user->has_cap( 'ai_os_approve' ),
				'executeCallback'    => static function ( array $args, $user ): AbilityResult {
					$logs = \AIOS\Core\Plugin::instance()?->container()?->get( \AIOS\Audit\AuditLogger::class );
					if ( null === $logs ) {
						return AbilityResult::error( 'logs.unavailable', 'The audit service is not available in this context.', 'unavailable' );
					}

					$limit   = (int) ( $args['limit'] ?? 25 );
					$page    = (int) ( $args['page'] ?? 1 );
					$filters = array();
					foreach ( array( 'user_id', 'tool', 'status', 'risk', 'since' ) as $key ) {
						if ( isset( $args[ $key ] ) ) {
							$filters[ $key ] = $args[ $key ];
						}
					}

					$rows = $logs->repository()->query( $filters, $limit, ( $page - 1 ) * $limit );

					$items = array();
					foreach ( $rows as $row ) {
						$items[] = array(
							'id'          => $row['id'],
							'occurred_at' => $row['occurred_at'],
							'user_id'     => $row['user_id'],
							'client'      => $row['client'],
							'tool'        => $row['tool'],
							'status'      => $row['status'],
							'risk'        => $row['risk'],
							'duration_ms' => $row['duration_ms'],
							'error'       => $row['error'],
						);
					}

					return AbilityResult::success( array( 'items' => $items, 'count' => count( $items ) ) );
				},
			)
		) );

		$tools->register( Tool::make(
			array(
				'name'            => 'logs.list',
				'description'     => 'Query the AI OS audit log with filters (user, tool, status, risk, date). Read-only, redacted.',
				'category'        => 'logs',
				'inputSchema'     => array(
					'type'       => 'object',
					'properties' => array(
						'user_id' => array( 'type' => 'integer', 'minimum' => 1 ),
						'tool'    => array( 'type' => 'string', 'maxLength' => 190 ),
						'status'  => array( 'type' => 'string', 'enum' => array( 'ok', 'error', 'blocked', 'rejected', 'approval_required' ) ),
						'risk'    => array( 'type' => 'integer', 'minimum' => 0, 'maximum' => 4 ),
						'since'   => array( 'type' => 'string', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}' ),
						'limit'   => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100 ),
						'page'    => array( 'type' => 'integer', 'minimum' => 1 ),
					),
					'additionalProperties' => false,
				),
				'riskLevel'       => 0,
				'permissionLevel' => 0,
				'confirmation'    => 'never',
			)
		) );
	}

	// ---------------------------------------------------------------- logs.get

	private static function registerGet( AbilityRegistry $abilities, ToolRegistry $tools ): void {
		$abilities->register( Ability::make(
			array(
				'name'        => 'logs.get',
				'description' => 'Get one audit log entry in full (redacted arguments, affected objects, approval linkage).',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required'   => array( 'id' ),
					'additionalProperties' => false,
				),
				'level'              => 0,
				'permissionCallback' => static fn( $user, array $args ): bool => $user->has_cap( 'manage_options' ) || $user->has_cap( 'ai_os_approve' ),
				'executeCallback'    => static function ( array $args, $user ): AbilityResult {
					$logs = \AIOS\Core\Plugin::instance()?->container()?->get( \AIOS\Audit\AuditLogger::class );
					if ( null === $logs ) {
						return AbilityResult::error( 'logs.unavailable', 'The audit service is not available in this context.', 'unavailable' );
					}

					$row = $logs->repository()->get( (int) ( $args['id'] ?? 0 ) );
					if ( null === $row ) {
						return AbilityResult::error( 'logs.not_found', 'No log entry found with that id.', 'not_found' );
					}

					unset( $row['args_json'] ); // Raw JSON column; decoded copy already present.
					return AbilityResult::success( $row );
				},
			)
		) );

		$tools->register( Tool::make(
			array(
				'name'            => 'logs.get',
				'description'     => 'Get one audit log entry in full (redacted). Read-only.',
				'category'        => 'logs',
				'inputSchema'     => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
					'required'   => array( 'id' ),
					'additionalProperties' => false,
				),
				'riskLevel'       => 0,
				'permissionLevel' => 0,
				'confirmation'    => 'never',
			)
		) );
	}
}
