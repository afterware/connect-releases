<?php
/**
 * Connects this site to Afterware: registration, scanning, disconnection.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * The single place that ties Options, Api_Client, Inventory, Report and
 * Activity together for the register / scan / disconnect flows.
 */
final class Connection {

	/**
	 * The single event a fresh connection books, so a site that has just been
	 * given a key does not wait for the next update check to learn which
	 * builds Afterware has signed for it.
	 *
	 * @var string
	 */
	const REFRESH_HOOK = 'afterware_refresh_manifests';

	/**
	 * How long after connecting that event runs. Long enough to be out of the
	 * way of the request that connected, short enough that a site owner still
	 * has the overview open when it lands.
	 *
	 * @var int
	 */
	const REFRESH_DELAY = 30;

	/**
	 * Whether this site has a stored site key.
	 *
	 * @return bool
	 */
	public static function is_connected() {
		$key = Options::get( Options::SITE_KEY );
		return is_string( $key ) && '' !== $key;
	}

	/**
	 * Registers this site with Afterware and stores the issued site key and id.
	 *
	 * @return true|WP_Error
	 */
	public static function register() {
		$client = new Api_Client( AFTERWARE_API_BASE, null );
		$result = $client->register( self::descriptor() );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! isset( $result['site_key'], $result['site_id'] ) ) {
			return new WP_Error( 'afterware_bad_response', __( 'Afterware registration response was missing required fields.', 'afterware' ) );
		}

		First_Install_Receipt::clear();
		Options::set( Options::SITE_KEY, (string) $result['site_key'] );
		Options::set( Options::SITE_ID, (string) $result['site_id'] );
		Options::set( Options::REGISTERED_AT, time() );

		Activity::log( 'connected', null, array( 'site_id' => $result['site_id'] ) );
		self::schedule_manifest_refresh();

		return true;
	}

	/**
	 * Collects the inventory, posts it, and stores the returned risk report.
	 *
	 * When Inventory::collect() had to leave a plugin out because its main
	 * file could not be hashed, records one additional 'inventory.skipped'
	 * activity entry naming the skipped files.
	 *
	 * @return array<string,mixed>|WP_Error The risk report.
	 */
	public static function scan() {
		if ( ! self::is_connected() ) {
			return new WP_Error( 'afterware_not_connected', __( 'Connect this site to Afterware first.', 'afterware' ) );
		}

		// A scan is a fresh look at the site, and may run inside a long-lived
		// WP-CLI or cron process; let the manifest cache reach the API again.
		Manifest_Cache::reset_request_guard();

		$client    = new Api_Client( AFTERWARE_API_BASE, Options::get( Options::SITE_KEY ) );
		$inventory = Inventory::collect();
		$result    = $client->inventory( $inventory );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		Report::store( $result );
		Activity::log( 'scan', null, array( 'plugin_count' => count( $inventory['plugins'] ) ) );

		$skipped = Inventory::last_skipped();
		if ( array() !== $skipped ) {
			Activity::log(
				'inventory.skipped',
				null,
				array( 'files' => $skipped ),
				sprintf(
					/* translators: %d: number of plugins skipped. */
					__( '%d plugin(s) could not be hashed and were left out of the inventory.', 'afterware' ),
					count( $skipped )
				)
			);
		}

		return $result;
	}

	/**
	 * Runs a scan whose failure is worth writing down but never worth raising.
	 *
	 * The two callers are the daily cron event and the end of a switch or a
	 * revert: neither has a person waiting on the answer, and in both the site
	 * is already exactly where it should be — all that is missing is a fresh
	 * report. A site that was never connected has nothing to scan and nothing
	 * to say about it, which is why that one code is passed over in silence.
	 *
	 * @return void
	 */
	public static function scan_quietly() {
		$result = self::scan();
		if ( ! is_wp_error( $result ) || 'afterware_not_connected' === $result->get_error_code() ) {
			return;
		}

		Activity::log(
			'scan.failed',
			null,
			array( 'code' => $result->get_error_code() ),
			$result->get_error_message()
		);
	}

	/**
	 * Adopts a site key issued directly by support (the `wp afterware connect`
	 * path), rather than one this site obtained itself via register().
	 *
	 * Confirms the key against the API with GET /report *before* storing
	 * anything: the candidate key is passed to Api_Client explicitly, never
	 * through Options, so an unconfirmed key can never leak into a real
	 * request elsewhere. Nothing is written — not SITE_KEY, not SITE_ID, not
	 * REGISTERED_AT — unless that call comes back a plain success; a 401, a
	 * 5xx, and a transport failure all leave the site exactly as they found
	 * it, including any key it was already connected with.
	 *
	 * A 2xx is not on its own proof of anything either: an empty body, an
	 * HTML error page from a proxy and a captive portal's redirect all decode
	 * to something that is not a report. The response has to actually look
	 * like one — a `schema_version` and a `plugins` list — before the key is
	 * believed and stored.
	 *
	 * @param string $key Site key issued by support.
	 * @return array<string,mixed>|WP_Error The risk report on success.
	 */
	public static function adopt_key( $key ) {
		$key = (string) $key;

		$client = new Api_Client( AFTERWARE_API_BASE, $key );
		$result = $client->report();
		if ( is_wp_error( $result ) ) {
			if ( 401 === self::error_status( $result ) ) {
				return new WP_Error( 'afterware_bad_key', __( 'Afterware rejected that site key.', 'afterware' ) );
			}
			return $result;
		}
		if ( ! isset( $result['schema_version'] ) || ! isset( $result['plugins'] ) || ! is_array( $result['plugins'] ) ) {
			return new WP_Error(
				'afterware_bad_report',
				__( 'Afterware answered, but not with a report. Nothing was saved; try again in a minute.', 'afterware' )
			);
		}

		First_Install_Receipt::clear();
		Options::set( Options::SITE_KEY, $key );
		if ( isset( $result['site']['site_id'] ) ) {
			Options::set( Options::SITE_ID, (string) $result['site']['site_id'] );
		} else {
			Options::delete( Options::SITE_ID );
		}
		Options::set( Options::REGISTERED_AT, time() );
		Activity::log( 'connected', null, array() );
		self::schedule_manifest_refresh();

		return $result;
	}

	/**
	 * Removes the stored site key, id and registration time.
	 *
	 * @return void
	 */
	public static function disconnect() {
		First_Install_Receipt::clear();
		Options::delete( Options::SITE_KEY );
		Options::delete( Options::SITE_ID );
		Options::delete( Options::REGISTERED_AT );
		Options::delete( Options::CLAIM_CODE );

		Activity::log( 'disconnected', null );
	}

	/**
	 * Books the one-off manifest fetch a newly connected site needs.
	 *
	 * A site that has just been given a key has a report but nothing verified,
	 * so the overview says "Unavailable" about plugins it has, in the same
	 * breath, called switchable. Drawing a screen may not go and fetch a
	 * manifest, and the next update check can be twelve hours away; this books
	 * the fetch for half a minute from now instead. One event at a time: two
	 * registrations in a row are one cold site, not two.
	 *
	 * @return void
	 */
	private static function schedule_manifest_refresh() {
		if ( false === wp_next_scheduled( self::REFRESH_HOOK ) ) {
			wp_schedule_single_event( time() + self::REFRESH_DELAY, self::REFRESH_HOOK );
		}
	}

	/**
	 * The HTTP status Api_Client attached to a WP_Error, or 0 when it has none.
	 *
	 * @param WP_Error $error Error returned by Api_Client.
	 * @return int
	 */
	private static function error_status( WP_Error $error ) {
		$data = $error->get_error_data();
		return ( is_array( $data ) && isset( $data['status'] ) ) ? (int) $data['status'] : 0;
	}

	/**
	 * Builds the RegisterRequestSchema descriptor for this site.
	 *
	 * @return array<string,mixed>
	 */
	private static function descriptor() {
		return array(
			'site_url'       => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'client_version' => AFTERWARE_VERSION,
			'locale'         => get_locale(),
			'multisite'      => is_multisite(),
		);
	}
}
