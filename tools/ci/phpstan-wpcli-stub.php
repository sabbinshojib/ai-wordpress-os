<?php
/**
 * PHPStan-only WP-CLI stub.
 *
 * WP_CLI / WP_CLI_Command / WP_CLI\Utils\format_items() only exist when
 * a site is booted through the `wp` binary; composer.json's
 * require-dev has no WP-CLI stub package, and php-stubs/wordpress-stubs
 * (used for core WP symbols) does not cover WP-CLI. This file declares
 * only the signatures CliCommands.php actually calls, for static
 * analysis only — it is never loaded at runtime.
 *
 * @package AIOS
 */

namespace {

	class WP_CLI_Command {}

	class WP_CLI {
		/**
		 * Real WP-CLI's `error()` defaults `$exit` to true, halting the
		 * process — CliCommands.php never passes `$exit = false`, so
		 * every call site here genuinely never returns.
		 */
		public static function error( string $message ): never {}
		public static function line( string $message ): void {}
		public static function success( string $message ): void {}
		public static function add_command( string $name, string $callable ): void {}
	}
}

namespace WP_CLI\Utils {

	/**
	 * @param array<int, array<string, mixed>> $items
	 * @param array<int, string>               $fields
	 */
	function format_items( string $format, array $items, array $fields ): void {}
}
