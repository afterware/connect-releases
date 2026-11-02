<?php
/**
 * What happens when something fails verification.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * One place for the policy every verification failure follows.
 *
 * A manifest whose signature does not verify and a package whose hash does not
 * match are the same event as far as the site owner is concerned: nothing was
 * installed, and somebody needs to look at it. Both are recorded in the
 * activity log with the technical detail, surfaced as a persistent, plain
 * language admin notice, and reported to Afterware as a ticket.
 *
 * The notice only claims Afterware knows about the failure when a ticket was
 * actually accepted, so the message never over-promises on a site that could
 * not reach the API.
 *
 * Nothing here ever records key material, signatures or file contents.
 */
final class Verification_Failure {

	/**
	 * Records a verification failure everywhere it needs to appear.
	 *
	 * @param string              $slug    Plugin slug the failure is about.
	 * @param string              $reason  Machine-readable reason code, e.g. 'afterware_bad_signature'.
	 * @param string              $detail  Technical detail for the log and the ticket.
	 * @param array<string,mixed> $context Extra data safe to store and send.
	 * @return bool Whether support was notified for this plugin/key in the current dedupe window.
	 */
	public static function report( $slug, $reason, $detail, array $context = array() ) {
		$slug = (string) $slug;

		Activity::log(
			'verification_failed',
			$slug,
			array_merge( array( 'reason' => (string) $reason ), $context ),
			(string) $detail
		);

		$ticketed = self::open_ticket( $slug, (string) $reason, $context );
		Notices::add( 'verify-' . $slug, 'error', self::message( $slug, $ticketed ), true );

		return $ticketed;
	}

	/**
	 * Records a failure and returns the error to hand back to WordPress, so
	 * the upgrader shows the same plain-language message as the notice.
	 *
	 * @param string              $slug    Plugin slug.
	 * @param string              $code    WP_Error code, also used as the reason.
	 * @param string              $detail  Technical detail for the log and the ticket.
	 * @param array<string,mixed> $context Extra data safe to store and send.
	 * @return WP_Error
	 */
	public static function to_wp_error( $slug, $code, $detail, array $context = array() ) {
		$ticketed = self::report( $slug, $code, $detail, $context );
		return new WP_Error( $code, self::message( $slug, $ticketed ) );
	}

	/**
	 * Asks Tickets to open an automatic verification-failure ticket.
	 *
	 * Tickets lands in a later task; until then a failure is still logged and
	 * shown, it simply is not reported to Afterware automatically. The callable
	 * is built at runtime so this file never hard-depends on a class that may
	 * not exist yet.
	 *
	 * @param string              $slug    Plugin slug.
	 * @param string              $reason  Machine-readable reason code.
	 * @param array<string,mixed> $context Extra data safe to send.
	 * @return bool
	 */
	private static function open_ticket( $slug, $reason, array $context ) {
		$auto_ticket = array( __NAMESPACE__ . '\\Tickets', 'auto_verification_failure' );
		if ( ! is_callable( $auto_ticket ) ) {
			return false;
		}
		$sent   = (bool) call_user_func( $auto_ticket, $slug, $reason, $context );
		$key_id = isset( $context['key_id'] ) && is_string( $context['key_id'] ) ? $context['key_id'] : '';
		return $sent || Tickets::verification_failure_reported( $slug, $key_id );
	}

	/**
	 * The message a site owner reads. Names the plugin, not the slug, says what
	 * did not happen rather than what went wrong internally, and gives one
	 * thing to do next.
	 *
	 * @param string $slug     Plugin slug.
	 * @param bool   $ticketed Whether a ticket was accepted.
	 * @return string
	 */
	private static function message( $slug, $ticketed ) {
		$message = sprintf(
			/* translators: %s: plugin name. */
			__( 'Afterware could not verify the update for %s. The update was not installed and your site was not changed. Check the Afterware activity log, or contact support if this keeps happening.', 'afterware' ),
			Inventory::name_for( $slug )
		);

		if ( $ticketed ) {
			$message .= ' ' . __( 'Afterware has been notified.', 'afterware' );
		}

		return $message;
	}
}
