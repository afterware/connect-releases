<?php
/**
 * Local activity log.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * A capped, newest-first log of what the plugin has done locally.
 *
 * Entries never carry secrets: callers pass only the data safe to display
 * and to have sat in the database (e.g. a site id, never a site key).
 */
final class Activity {

	/**
	 * Maximum number of entries kept; older entries are dropped.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * Appends an entry to the front of the log.
	 *
	 * @param string              $kind    Short machine-readable kind, e.g. 'connected', 'scan'.
	 * @param string|null         $slug    Plugin slug the entry is about, or null when it is not about one plugin.
	 * @param array<string,mixed> $data    Extra structured data, safe to store and display.
	 * @param string              $message Human-readable message.
	 * @return void
	 */
	public static function log( $kind, $slug, array $data = array(), $message = '' ) {
		$entries = self::all();
		array_unshift(
			$entries,
			array(
				'at'      => time(),
				'kind'    => (string) $kind,
				'slug'    => null === $slug ? null : (string) $slug,
				'data'    => $data,
				'message' => (string) $message,
			)
		);
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			$entries = array_slice( $entries, 0, self::MAX_ENTRIES );
		}
		Options::set( Options::ACTIVITY, $entries );
	}

	/**
	 * All entries, newest first.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function all() {
		$entries = Options::get( Options::ACTIVITY, array() );
		return is_array( $entries ) ? $entries : array();
	}

	/**
	 * What each kind is called on screen.
	 *
	 * The stored kinds are machine-readable and stay that way — an old entry
	 * must still mean what it meant when it was written — so the words a site
	 * owner reads live here, where they can be translated and changed without
	 * touching a single stored row. A kind with no entry falls back to itself.
	 *
	 * @return array<string,string>
	 */
	public static function labels() {
		return array(
			'connected'               => __( 'Connected', 'afterware' ),
			'disconnected'            => __( 'Disconnected', 'afterware' ),
			'scan'                    => __( 'Scan', 'afterware' ),
			'scan.failed'             => __( 'Scan failed', 'afterware' ),
			'inventory.skipped'       => __( 'Plugins skipped', 'afterware' ),
			'switched'                => __( 'Switched', 'afterware' ),
			'switch_failed'           => __( 'Switch failed', 'afterware' ),
			'reverted'                => __( 'Reverted', 'afterware' ),
			'revert_failed'           => __( 'Revert failed', 'afterware' ),
			'updated'                 => __( 'Updated', 'afterware' ),
			'verification_failed'     => __( 'Verification failed', 'afterware' ),
			'update_access_denied'    => __( 'Updates paused', 'afterware' ),
			'manifest_refresh_failed' => __( 'Build list unavailable', 'afterware' ),
			'reactivation_failed'     => __( 'Reactivation failed', 'afterware' ),
			'key_ring_empty'          => __( 'No signing keys', 'afterware' ),
			'key_ring_outdated'       => __( 'Signing key unknown', 'afterware' ),
			'key_ring_updated'        => __( 'Signing keys updated', 'afterware' ),
			'key_withdrawn'           => __( 'Signing key withdrawn', 'afterware' ),
			'key_ring_refused'        => __( 'Key ring refused', 'afterware' ),
			'key_ring_forgotten'      => __( 'Key ring forgotten', 'afterware' ),
			'ticket'                  => __( 'Ticket', 'afterware' ),
			'ticket.rate_limited'     => __( 'Ticket held back', 'afterware' ),
		);
	}

	/**
	 * Entries about a single plugin slug, newest first.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<int,array<string,mixed>>
	 */
	public static function for_slug( $slug ) {
		return array_values(
			array_filter(
				self::all(),
				static function ( $entry ) use ( $slug ) {
					return isset( $entry['slug'] ) && $entry['slug'] === $slug;
				}
			)
		);
	}
}
