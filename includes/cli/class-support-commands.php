<?php
/**
 * The `connect`, `tickets` and `ticket` wp-cli commands.
 *
 * @package Afterware
 */

namespace Afterware\CLI;

use Afterware\Connection;
use Afterware\Tickets;

/**
 * Adopting a support-issued site key, and opening or listing tickets, from
 * the CLI.
 *
 * Split out of Commands to keep that file a manageable size. Every method
 * assumes a `$this->output` (an Output) from the class that uses this trait.
 */
trait Support_Commands {

	/**
	 * Stores a site key issued by support and confirms it against the API.
	 *
	 * ## OPTIONS
	 *
	 * --key=<key>
	 * : The site key.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware connect --key=abc123...
	 *
	 * @param string[]            $args       Positional args; unused.
	 * @param array<string,mixed> $assoc_args Associative args: `key`.
	 * @return void
	 */
	public function connect( $args = array(), $assoc_args = array() ) {
		unset( $args );

		// At least 32 URL-safe base64 characters: enough to rule out a
		// truncated paste or a placeholder, without pinning the length of a
		// key Afterware issues and may lengthen.
		$key = isset( $assoc_args['key'] ) ? (string) $assoc_args['key'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{32,}$/', $key ) ) {
			$this->output->error( __( 'Usage: wp afterware connect --key=<site key>', 'afterware' ) );
			return;
		}

		$result = Connection::adopt_key( $key );
		if ( is_wp_error( $result ) ) {
			$this->output->error( $result->get_error_message() );
			return;
		}

		$this->output->success( __( 'Connected to Afterware.', 'afterware' ) );
	}

	/**
	 * Lists the tickets opened with Afterware support.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware tickets
	 *
	 * @param string[]            $args       Positional args; unused.
	 * @param array<string,mixed> $assoc_args Associative args; unused.
	 * @return void
	 */
	public function tickets( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );

		if ( ! $this->require_connection() ) {
			return;
		}

		$rows = array();
		foreach ( Tickets::all() as $ticket ) {
			$rows[] = array(
				'id'         => (string) $ticket['id'],
				'kind'       => (string) $ticket['kind'],
				'subject'    => (string) $ticket['subject'],
				'slug'       => null === $ticket['slug'] ? '' : (string) $ticket['slug'],
				'created_at' => gmdate( 'Y-m-d H:i:s', (int) $ticket['created_at'] ),
			);
		}
		if ( array() === $rows ) {
			$this->output->line( __( 'No tickets have been opened yet.', 'afterware' ) );
			return;
		}
		$this->output->table( $rows, array( 'id', 'kind', 'subject', 'slug', 'created_at' ) );
	}

	/**
	 * Opens a support ticket about a plugin.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin slug the ticket is about.
	 *
	 * --subject=<subject>
	 * : Ticket subject.
	 *
	 * [--body=<body>]
	 * : Ticket body.
	 *
	 * [--kind=<kind>]
	 * : One of: question, breakage. Default: question.
	 *
	 * [--log]
	 * : Attach the tail of the debug log.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware ticket demo-plugin --subject="Broke after update" --kind=breakage --log
	 *
	 * @param string[]            $args       Positional args: the plugin slug.
	 * @param array<string,mixed> $assoc_args Associative args: `subject`, `body`, `kind`, `log`.
	 * @return void
	 */
	public function ticket( $args, $assoc_args = array() ) {
		if ( ! $this->require_connection() ) {
			return;
		}

		$slug    = isset( $args[0] ) ? (string) $args[0] : '';
		$subject = isset( $assoc_args['subject'] ) ? (string) $assoc_args['subject'] : '';
		if ( '' === $slug || '' === $subject ) {
			$this->output->error(
				__( 'Usage: wp afterware ticket <slug> --subject=<subject> [--body=<body>] [--kind=question|breakage] [--log]', 'afterware' )
			);
			return;
		}

		$kind = isset( $assoc_args['kind'] ) ? (string) $assoc_args['kind'] : 'question';
		if ( ! in_array( $kind, array( 'question', 'breakage' ), true ) ) {
			$this->output->error( __( '--kind must be one of: question, breakage.', 'afterware' ) );
			return;
		}

		$result = Tickets::open(
			array(
				'plugin_slug' => $slug,
				'kind'        => $kind,
				'subject'     => $subject,
				'body'        => isset( $assoc_args['body'] ) ? (string) $assoc_args['body'] : '',
			),
			! empty( $assoc_args['log'] )
		);

		if ( is_wp_error( $result ) ) {
			$this->output->error( $result->get_error_message() );
			return;
		}

		$this->output->success(
			sprintf(
				/* translators: %s: ticket id. */
				__( 'Ticket opened: %s', 'afterware' ),
				(string) $result['ticket_id']
			)
		);
	}
}
