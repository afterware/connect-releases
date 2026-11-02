<?php
/**
 * Output through the real WP-CLI.
 *
 * @package Afterware
 */

namespace Afterware\CLI;

use WP_CLI;
use WP_CLI\Utils;

/**
 * Output's production implementation: every call is a thin pass-through to
 * \WP_CLI, which is only ever loaded when a real `wp` process is running.
 */
final class WP_CLI_Output implements Output {

	/**
	 * @param string $message Message to print.
	 * @return void
	 */
	public function line( $message ) {
		WP_CLI::line( $message );
	}

	/**
	 * @param string $message Message to print.
	 * @return void
	 */
	public function success( $message ) {
		WP_CLI::success( $message );
	}

	/**
	 * @param string $message Message to print.
	 * @return void
	 */
	public function warning( $message ) {
		WP_CLI::warning( $message );
	}

	/**
	 * @param string $message Message to print.
	 * @return void
	 */
	public function error( $message ) {
		WP_CLI::error( $message );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows   Row data, each keyed by field name.
	 * @param array<int,string>              $fields Field names to show, in order.
	 * @return void
	 */
	public function table( array $rows, array $fields ) {
		Utils\format_items( 'table', $rows, $fields );
	}

	/**
	 * @param string $question Question to ask.
	 * @param bool   $skip     Whether to proceed without asking.
	 * @return void
	 */
	public function confirm( $question, $skip = false ) {
		WP_CLI::confirm( $question, array( 'yes' => $skip ) );
	}
}
