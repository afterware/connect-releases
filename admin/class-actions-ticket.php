<?php
/**
 * The new-ticket handler.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Notices;
use Afterware\Tickets;

/**
 * Opening a support ticket from the form, and giving the form back intact when
 * that does not work.
 *
 * A trait rather than a class: this is Actions' own handler and uses its
 * guard, redirect and field helpers, but a ticket has more to say about
 * failure than the other actions do and deserves its own file.
 */
trait Actions_Ticket {

	/**
	 * Opens a support ticket from the form on the Tickets screen.
	 *
	 * @return void
	 */
	public static function ticket_new() {
		self::guard( 'ticket_new' );

		$tickets = Admin::PAGE . '-tickets';
		$draft   = array(
			'plugin_slug' => self::post_field( 'plugin' ),
			'kind'        => self::post_field( 'kind' ),
			'subject'     => trim( self::post_field( 'subject' ) ),
			'body'        => trim( self::post_text( 'body' ) ),
			'include_log' => '' !== self::post_field( 'share_debug_log' ),
		);

		if ( ! isset( Tickets::kind_labels()[ $draft['kind'] ] ) || 'verification_failure' === $draft['kind'] ) {
			self::bounce_ticket( $draft, __( 'Choose whether this is a breakage or a question.', 'afterware' ) );
		}
		if ( '' === $draft['subject'] || '' === $draft['body'] ) {
			self::bounce_ticket( $draft, __( 'A ticket needs a subject and a description of what happened.', 'afterware' ) );
		}

		$result = Tickets::open( $draft, $draft['include_log'] );
		if ( is_wp_error( $result ) ) {
			self::bounce_ticket(
				$draft,
				sprintf(
					/* translators: %s: the reason the ticket could not be sent. */
					__( 'The ticket could not be sent (%s). Your text is still in the form below.', 'afterware' ),
					rtrim( trim( $result->get_error_message() ), '.' )
				)
			);
		}

		Notices::remove( 'ticket' );
		self::back( $tickets, 'ticket-opened' );
	}

	/**
	 * Sends a ticket that could not be opened back to the form with the words
	 * still in it.
	 *
	 * Losing what someone wrote is the one thing a form must never do, and a
	 * ticket is written at the worst moment of somebody's day. The draft lives
	 * for ten minutes in a transient of their own, long enough to land on the
	 * next page load and not long enough to surprise them later.
	 *
	 * @param array<string,mixed> $draft   What was typed.
	 * @param string              $message What to tell them.
	 * @return void
	 */
	private static function bounce_ticket( array $draft, $message ) {
		set_transient( 'afterware_ticket_draft_' . get_current_user_id(), $draft, self::DRAFT_TTL );
		Notices::add( 'ticket', 'error', $message );
		self::back( Admin::PAGE . '-tickets' );
	}
}
