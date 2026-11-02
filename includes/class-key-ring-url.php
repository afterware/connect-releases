<?php
/**
 * The URLs Key_Ring_Fetch downloads the published ring from.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Two pinned URLs, each replaceable by a filter only on a local or
 * development site — the same guard Key_Ring applies to AFTERWARE_DEV_KEY_RING.
 *
 * AFTERWARE_KEY_RING_URL and AFTERWARE_KEY_RING_SIG_URL are not
 * wp-config.php-overridable (see their definition in afterware.php): pointing
 * the ring at a different host is a decision only a release gets to make, on
 * a production site. A local stub or a test fixture still needs to stand in
 * for keys.afterware.io, so the two filters below are honoured on the same
 * environments AFTERWARE_DEV_KEY_RING is, and nowhere else. Kept apart from
 * Key_Ring itself only to leave that file room to grow.
 */
final class Key_Ring_Url {

	/**
	 * The key ring document's URL.
	 *
	 * @return string
	 */
	public static function ring() {
		$default = AFTERWARE_KEY_RING_URL;
		if ( ! self::dev_environment() ) {
			return $default;
		}

		/**
		 * Filters the key ring document's URL. Only consulted on a local or
		 * development site.
		 *
		 * @param string $url The URL this build would otherwise use.
		 */
		/** @var mixed $filtered A filter may return anything; only a non-empty string replaces the default. */
		$filtered = apply_filters( 'afterware_key_ring_url', $default );
		return ( is_string( $filtered ) && '' !== $filtered ) ? $filtered : $default;
	}

	/**
	 * The key ring's detached signature URL. Same guard as ring().
	 *
	 * @return string
	 */
	public static function sig() {
		$default = AFTERWARE_KEY_RING_SIG_URL;
		if ( ! self::dev_environment() ) {
			return $default;
		}

		/**
		 * Filters the key ring's detached signature URL. Only consulted on a
		 * local or development site.
		 *
		 * @param string $url The URL this build would otherwise use.
		 */
		/** @var mixed $filtered A filter may return anything; only a non-empty string replaces the default. */
		$filtered = apply_filters( 'afterware_key_ring_sig_url', $default );
		return ( is_string( $filtered ) && '' !== $filtered ) ? $filtered : $default;
	}

	/**
	 * Whether a filter may replace either URL right now.
	 *
	 * @return bool
	 */
	private static function dev_environment() {
		return in_array( Key_Ring::environment_type(), Key_Ring::DEV_ENVIRONMENTS, true );
	}
}
