<?php
/**
 * The switch and revert handlers.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Switch_Guard;
use Afterware\Switcher;

/**
 * The two handlers that replace files on disk, kept apart from the rest so
 * the pattern they share is visible in one screenful.
 *
 * Both take the plugin's slug typed out by hand. The client script only ever
 * enables the button; the check that matters is the one here, on the server,
 * because a POST can arrive without the script having run at all.
 *
 * A trait rather than a class: these are Actions' own handlers and use its
 * guard, redirect and field helpers, but Actions stays a file you can read
 * end to end.
 */
trait Actions_Plugin {

	/**
	 * Switches a plugin to its Afterware build.
	 *
	 * @return void
	 */
	public static function switch_plugin() {
		$slug = self::confirmed_slug( 'switch' );

		$result = Switcher::switch_to_afterware( $slug );
		if ( is_wp_error( $result ) ) {
			self::remember_error( $result );
			self::back( Admin::PAGE . '-plugin', 'switch-failed:' . $result->get_error_code(), array( 'slug' => $slug ) );
		}

		self::back( Admin::PAGE . '-plugin', 'switched', array( 'slug' => $slug ) );
	}

	/**
	 * Puts a switched plugin back on its upstream copy.
	 *
	 * @return void
	 */
	public static function revert_plugin() {
		$slug = self::confirmed_slug( 'revert' );

		$result = Switcher::revert( $slug );
		if ( is_wp_error( $result ) ) {
			self::remember_error( $result );
			self::back( Admin::PAGE . '-plugin', 'revert-failed:' . $result->get_error_code(), array( 'slug' => $slug ) );
		}

		// A switch leaves a record behind that proves it happened; a revert
		// leaves the absence of one, which proves nothing on its own. This is
		// the note that lets the next request tell a real revert from a URL
		// somebody kept.
		set_transient( 'afterware_reverted_' . get_current_user_id(), $slug, self::REVERTED_TTL );

		self::back( Admin::PAGE . '-plugin', 'reverted', array( 'slug' => $slug ) );
	}

	/**
	 * The slug a checked, permitted and correctly confirmed request names.
	 *
	 * Returns only when all three hold; otherwise it ends the request the way
	 * that state deserves — a 403 for a missing nonce, and the plugin screen
	 * with an explanation for the other two.
	 *
	 * @param string $action 'switch' or 'revert'.
	 * @return string
	 */
	private static function confirmed_slug( $action ) {
		self::guard( $action );

		$slug = self::post_field( 'slug' );

		if ( is_wp_error( Switch_Guard::may_manage_plugins() ) ) {
			self::back( Admin::PAGE . '-plugin', 'not-allowed', array( 'slug' => $slug ) );
		}
		if ( '' === $slug || self::post_field( 'confirm' ) !== $slug ) {
			self::back( Admin::PAGE . '-plugin', 'confirm-mismatch', array( 'slug' => $slug ) );
		}

		return $slug;
	}
}
