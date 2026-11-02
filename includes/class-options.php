<?php
/**
 * Typed access to the plugin's options.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Central place for every option name the plugin stores.
 *
 * Every option is stored with autoload off except the user-editable settings,
 * which are read on most admin page loads and small enough to autoload.
 */
final class Options {

	/**
	 * The site key issued at registration. Never logged or displayed again
	 * once returned by the API.
	 *
	 * @var string
	 */
	const SITE_KEY = 'afterware_site_key';

	/**
	 * The site id issued at registration.
	 *
	 * @var string
	 */
	const SITE_ID = 'afterware_site_id';

	/** @var string One pending installation report or its registration-bound acknowledgement. */
	const FIRST_INSTALL = 'afterware_first_install';

	/** @var string Private expiring code, bound to its creator and this site's key. */
	const CLAIM_CODE = 'afterware_claim_code';

	/**
	 * Unix timestamp of a successful registration.
	 *
	 * @var string
	 */
	const REGISTERED_AT = 'afterware_registered_at';

	/**
	 * The last risk report received, with its receipt time.
	 *
	 * @var string
	 */
	const REPORT = 'afterware_report';

	/**
	 * Cached verified update manifests.
	 *
	 * @var string
	 */
	const MANIFESTS = 'afterware_manifests';

	/** @var string Last authoritative HTTP access denial; absent after recovery. */
	const UPDATE_ACCESS = 'afterware_update_access';

	/**
	 * Updates offered to WordPress, keyed by package URL, with the SHA-256
	 * the signed manifest gave for each. Read by the pre-download hook, which
	 * runs in a different request than the update check that wrote it.
	 *
	 * @var string
	 */
	const OFFERED = 'afterware_offered';

	/**
	 * Record of plugins switched to an Afterware build.
	 *
	 * @var string
	 */
	const SWITCHED = 'afterware_switched';

	/**
	 * The last key ring fetched from keys.afterware.io and verified against a
	 * key this site already trusted, with the id of the key that vouched for it.
	 *
	 * Named `afterware_fetched_keys` rather than `afterware_key_ring`, which is
	 * already the name of the filter that narrows the trusted ring. One name for
	 * two different things would make either one unsearchable.
	 *
	 * @var string
	 */
	const KEY_RING = 'afterware_fetched_keys';

	/**
	 * The local activity log.
	 *
	 * @var string
	 */
	const ACTIVITY = 'afterware_activity';

	/**
	 * Persistent admin notices awaiting display.
	 *
	 * @var string
	 */
	const NOTICES = 'afterware_notices';

	/**
	 * User-editable settings; the only option autoloaded.
	 *
	 * @var string
	 */
	const SETTINGS = 'afterware_settings';

	/**
	 * Tickets opened with Afterware support, newest first.
	 *
	 * @var string
	 */
	const TICKETS = 'afterware_tickets';

	/**
	 * Reads an option.
	 *
	 * @param string $name          One of this class's option name constants.
	 * @param mixed  $default_value Value to return when the option is not set.
	 * @return mixed
	 */
	public static function get( $name, $default_value = null ) {
		return get_option( $name, $default_value );
	}

	/**
	 * Writes an option. Autoload is off for everything except settings.
	 *
	 * @param string $name  One of this class's option name constants.
	 * @param mixed  $value Value to store.
	 * @return bool
	 */
	public static function set( $name, $value ) {
		return update_option( $name, $value, self::SETTINGS === $name );
	}

	/**
	 * Deletes an option.
	 *
	 * @param string $name One of this class's option name constants.
	 * @return bool
	 */
	public static function delete( $name ) {
		return delete_option( $name );
	}

	/**
	 * Reads one user-editable setting out of the SETTINGS option.
	 *
	 * Settings live together in one option so there is a single autoloaded
	 * row rather than one per toggle; this is the only place that has to know
	 * that, so nothing else handles the raw array.
	 *
	 * @param string $name          Setting name, e.g. 'share_debug_log'.
	 * @param mixed  $default_value Value to return when the setting is unset.
	 * @return mixed
	 */
	public static function setting( $name, $default_value = false ) {
		$settings = self::get( self::SETTINGS, array() );
		if ( ! is_array( $settings ) || ! array_key_exists( $name, $settings ) ) {
			return $default_value;
		}
		return $settings[ $name ];
	}

	/**
	 * Writes one user-editable setting, leaving the others alone.
	 *
	 * @param string $name  Setting name.
	 * @param mixed  $value Value to store.
	 * @return bool
	 */
	public static function set_setting( $name, $value ) {
		$settings = self::get( self::SETTINGS, array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}
		$settings[ $name ] = $value;
		return self::set( self::SETTINGS, $settings );
	}
}
