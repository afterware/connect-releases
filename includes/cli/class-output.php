<?php
/**
 * What a CLI command needs in order to talk to whoever is running it.
 *
 * @package Afterware
 */

namespace Afterware\CLI;

/**
 * The seam between Commands and WP-CLI.
 *
 * Commands never calls \WP_CLI::* directly: WP_CLI is not loaded under
 * PHPUnit, so every command talks to this interface instead, and a test
 * implementation stands in for the real one without needing WP-CLI at all.
 */
interface Output {

	/**
	 * Prints a plain line.
	 *
	 * @param string $message Message to print.
	 * @return void
	 */
	public function line( $message );

	/**
	 * Prints a success message.
	 *
	 * @param string $message Message to print.
	 * @return void
	 */
	public function success( $message );

	/**
	 * Prints a warning.
	 *
	 * @param string $message Message to print.
	 * @return void
	 */
	public function warning( $message );

	/**
	 * Reports an error. Ends the command: the WP-CLI implementation exits the
	 * process, the test implementation throws.
	 *
	 * @param string $message Message to print.
	 * @return void
	 */
	public function error( $message );

	/**
	 * Prints a table.
	 *
	 * @param array<int,array<string,mixed>> $rows    Row data, each keyed by field name.
	 * @param array<int,string>              $fields  Field names to show, in order.
	 * @return void
	 */
	public function table( array $rows, array $fields );

	/**
	 * Asks for confirmation before a destructive action, unless told to skip.
	 * Declining ends the command the same way error() does.
	 *
	 * @param string $question Question to ask.
	 * @param bool   $skip     Whether to proceed without asking (e.g. `--yes`).
	 * @return void
	 */
	public function confirm( $question, $skip = false );
}
