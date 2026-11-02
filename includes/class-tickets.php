<?php
/**
 * Support tickets, with environment context, and the automatic ticket a
 * verification failure opens.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Opens tickets against `POST /v1/tickets`, and builds the environment
 * context every ticket carries.
 *
 * A ticket body is exactly the platform's schema: `plugin_slug`, `kind`,
 * `subject`, `body`, `context`. Nothing here ever sends or stores the site
 * key: it travels only as the request's bearer token, the same as every
 * other call through Api_Client.
 */
final class Tickets {
	/**
	 * Maximum number of stored tickets kept; older entries are dropped.
	 *
	 * @var int
	 */
	const MAX_TICKETS = 100;

	/**
	 * Maximum serialised size of a ticket's `context`, matching the platform's
	 * schema limit.
	 *
	 * @var int
	 */
	const MAX_CONTEXT_BYTES = 32768;

	/**
	 * How long an automatic verification-failure ticket is suppressed for the
	 * same slug and key id.
	 *
	 * @var int
	 */
	const DEDUPE_TTL = DAY_IN_SECONDS;

	/**
	 * Builds the environment context sent with every ticket.
	 *
	 * @param string|null $slug Plugin slug the ticket is about, or null when it is not about one plugin.
	 * @return array<string,mixed>
	 */
	public static function context( $slug = null ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$slug  = ( null === $slug || '' === $slug ) ? null : (string) $slug;
		$theme = wp_get_theme();

		return array(
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'client_version' => AFTERWARE_VERSION,
			'locale'         => get_locale(),
			'multisite'      => is_multisite(),
			'active_plugins' => Ticket_Context::active_plugins(),
			'theme'          => array(
				'slug'    => $theme->get_stylesheet(),
				'version' => (string) $theme->get( 'Version' ),
			),
			'plugin'         => Ticket_Context::plugin( $slug ),
		);
	}

	/**
	 * Opens a ticket. `$ticket` supplies `plugin_slug` (slug or null), `kind`,
	 * `subject` and `body`; any `context` it carries is merged over the
	 * environment context this method builds. `debug_log_tail` is added only
	 * when `$include_log` is true and `WP_CONTENT_DIR/debug.log` exists.
	 *
	 * @param array<string,mixed> $ticket      Ticket fields.
	 * @param bool                $include_log Whether to attach the debug log tail.
	 * @return array<string,mixed>|WP_Error The decoded `{ ticket_id }` response, or an error.
	 */
	public static function open( array $ticket, $include_log = false ) {
		$slug = ( isset( $ticket['plugin_slug'] ) && '' !== (string) $ticket['plugin_slug'] )
			? (string) $ticket['plugin_slug']
			: null;

		$context = self::context( $slug );
		if ( isset( $ticket['context'] ) && is_array( $ticket['context'] ) ) {
			$context = array_merge( $context, $ticket['context'] );
		}
		if ( $include_log ) {
			$tail = Debug_Log::tail();
			if ( null !== $tail ) {
				$context['debug_log_tail'] = $tail;
			}
		}

		$body = self::enforce_context_limit(
			array(
				'plugin_slug' => $slug,
				'kind'        => isset( $ticket['kind'] ) ? (string) $ticket['kind'] : '',
				'subject'     => isset( $ticket['subject'] ) ? (string) $ticket['subject'] : '',
				'body'        => isset( $ticket['body'] ) ? (string) $ticket['body'] : '',
				'context'     => $context,
			)
		);

		$client = new Api_Client( AFTERWARE_API_BASE, Options::get( Options::SITE_KEY ) );
		$result = $client->ticket( $body );

		if ( is_wp_error( $result ) ) {
			if ( 'afterware_api_429' === $result->get_error_code() ) {
				Activity::log( 'ticket.rate_limited', $slug, array(), $result->get_error_message() );
			}
			return $result;
		}
		if ( ! isset( $result['ticket_id'] ) ) {
			return new WP_Error( 'afterware_bad_response', __( 'Afterware ticket response was missing required fields.', 'afterware' ) );
		}

		$id = (string) $result['ticket_id'];
		self::store( $id, $body['kind'], $body['subject'], $slug );
		Activity::log(
			'ticket',
			$slug,
			array(
				'id'   => $id,
				'kind' => $body['kind'],
			)
		);

		return $result;
	}

	/**
	 * Opens an automatic ticket for a verification failure, deduped per slug
	 * and key id for 24 hours.
	 *
	 * The dedupe transient is set only after the platform actually accepts
	 * the ticket: a send that fails (refused, rate-limited, unreachable)
	 * leaves nothing behind, so the next verification failure for the same
	 * slug and key id tries again instead of being silently swallowed for a
	 * day by a request that never landed.
	 *
	 * @param string              $slug    Plugin slug the failure is about.
	 * @param string              $reason  Machine-readable reason code.
	 * @param array<string,mixed> $context Extra data safe to send, carrying `key_id` when known.
	 * @return bool True only when the platform accepted a new ticket.
	 */
	public static function auto_verification_failure( $slug, $reason, array $context ) {
		$slug   = (string) $slug;
		$key_id = ( isset( $context['key_id'] ) && is_string( $context['key_id'] ) ) ? $context['key_id'] : '';

		$dedupe_key = self::dedupe_key( $slug, $key_id );
		if ( false !== get_transient( $dedupe_key ) ) {
			return false;
		}

		$result = self::open(
			array(
				'plugin_slug' => $slug,
				'kind'        => 'verification_failure',
				'subject'     => sprintf(
					/* translators: %s: plugin slug. */
					__( 'Verification failed for %s', 'afterware' ),
					$slug
				),
				'body'        => (string) $reason,
				'context'     => $context,
			)
		);

		if ( is_wp_error( $result ) ) {
			return false;
		}

		set_transient( $dedupe_key, time(), self::DEDUPE_TTL );
		return true;
	}

	/**
	 * Whether a ticket for this plugin/key was accepted within the dedupe window.
	 *
	 * @param string $slug Plugin slug.
	 * @param string $key_id Signing key id.
	 * @return bool
	 */
	public static function verification_failure_reported( $slug, $key_id ) {
		return false !== get_transient( self::dedupe_key( (string) $slug, (string) $key_id ) );
	}

	/**
	 * What each ticket kind is called on screen.
	 *
	 * `breakage` and `question` are the two the form offers;
	 * `verification_failure` is only ever written by auto_verification_failure()
	 * and is listed here so an automatic ticket reads like the others.
	 *
	 * @return array<string,string>
	 */
	public static function kind_labels() {
		return array(
			'breakage'             => __( 'Breakage', 'afterware' ),
			'question'             => __( 'Question', 'afterware' ),
			'verification_failure' => __( 'Verification failure', 'afterware' ),
		);
	}

	/**
	 * Every stored ticket, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$tickets = Options::get( Options::TICKETS, array() );
		return is_array( $tickets ) ? $tickets : array();
	}

	/**
	 * The transient key an auto-ticket's 24 hour dedupe window is stored under.
	 *
	 * @param string $slug   Plugin slug.
	 * @param string $key_id Signing key id, or '' when not known.
	 * @return string
	 */
	private static function dedupe_key( $slug, $key_id ) {
		return 'afterware_ticket_' . md5( $slug . '|' . $key_id );
	}

	/**
	 * Records a newly opened ticket, newest first, capped at MAX_TICKETS.
	 *
	 * @param string      $id      Ticket id returned by the platform.
	 * @param string      $kind    Ticket kind.
	 * @param string      $subject Ticket subject.
	 * @param string|null $slug    Plugin slug, or null.
	 * @return void
	 */
	private static function store( $id, $kind, $subject, $slug ) {
		$tickets = self::all();
		array_unshift(
			$tickets,
			array(
				'id'         => $id,
				'kind'       => $kind,
				'subject'    => $subject,
				'created_at' => time(),
				'slug'       => $slug,
			)
		);
		if ( count( $tickets ) > self::MAX_TICKETS ) {
			$tickets = array_slice( $tickets, 0, self::MAX_TICKETS );
		}
		Options::set( Options::TICKETS, $tickets );
	}

	/**
	 * Drops `debug_log_tail` first, then `active_plugins`, when the encoded
	 * context would exceed the platform's 32 KB limit, noting the drop.
	 *
	 * @param array<string,mixed> $body Ticket body with `context` already built.
	 * @return array<string,mixed>
	 */
	private static function enforce_context_limit( array $body ) {
		$context = $body['context'];
		$encoded = wp_json_encode( $context );
		if ( is_string( $encoded ) && strlen( $encoded ) <= self::MAX_CONTEXT_BYTES ) {
			return $body;
		}

		$truncated = false;

		if ( array_key_exists( 'debug_log_tail', $context ) ) {
			unset( $context['debug_log_tail'] );
			$truncated = true;
			$encoded   = wp_json_encode( $context );
		}

		if ( ( ! is_string( $encoded ) || strlen( $encoded ) > self::MAX_CONTEXT_BYTES ) && array_key_exists( 'active_plugins', $context ) ) {
			unset( $context['active_plugins'] );
			$truncated = true;
		}

		if ( $truncated ) {
			$context['truncated'] = true;
		}

		$body['context'] = $context;
		return $body;
	}
}
