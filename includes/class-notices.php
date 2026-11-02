<?php
/**
 * Admin notices awaiting display.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * A tiny keyed store of notices for the admin to render.
 *
 * Notices are raised where a problem is detected — often during cron or an
 * update check, with no screen to write to — and rendered later on the
 * Afterware screens and the plugins screen. A persistent notice survives page
 * loads until something removes it by key (a failed verification stays up
 * until the update succeeds); a one-shot notice is shown once and dropped by
 * flush().
 *
 * Keys are stable and meaningful (`verify-<slug>`), so re-raising the same
 * problem replaces the notice instead of stacking duplicates.
 */
final class Notices {

	/**
	 * Maximum number of notices kept; the oldest are dropped past this.
	 *
	 * @var int
	 */
	const MAX_NOTICES = 20;

	/**
	 * Notice types the admin knows how to render.
	 *
	 * @var string[]
	 */
	const TYPES = array( 'error', 'warning', 'success', 'info' );

	/**
	 * Raises a notice, replacing any notice already under the same key.
	 *
	 * @param string $key        Stable key, e.g. 'verify-demo-plugin'.
	 * @param string $type       One of self::TYPES; anything else becomes 'info'.
	 * @param string $message    Plain-language message for a site owner.
	 * @param bool   $persistent Whether the notice survives being displayed.
	 * @return void
	 */
	public static function add( $key, $type, $message, $persistent = false ) {
		$notices = self::all();

		$notices[ (string) $key ] = array(
			'type'       => in_array( $type, self::TYPES, true ) ? (string) $type : 'info',
			'message'    => (string) $message,
			'persistent' => (bool) $persistent,
			'at'         => time(),
		);

		Options::set( Options::NOTICES, self::pruned( $notices ) );
	}

	/**
	 * Drops the oldest notices past the cap, one-shot notices first.
	 *
	 * A persistent notice is a problem nobody has fixed yet — a failed
	 * verification stays up until the update succeeds — so routine chatter
	 * must never push one out.
	 *
	 * @param array<string,array{type:string,message:string,persistent:bool,at:int}> $notices Notices to prune.
	 * @return array<string,array{type:string,message:string,persistent:bool,at:int}>
	 */
	private static function pruned( array $notices ) {
		if ( count( $notices ) <= self::MAX_NOTICES ) {
			return $notices;
		}

		uasort(
			$notices,
			static function ( array $a, array $b ) {
				if ( $a['persistent'] !== $b['persistent'] ) {
					return $a['persistent'] ? 1 : -1;
				}
				return $a['at'] - $b['at'];
			}
		);

		return array_slice( $notices, -self::MAX_NOTICES, null, true );
	}

	/**
	 * Removes a notice by key. Removing an absent key is a no-op.
	 *
	 * @param string $key Notice key.
	 * @return void
	 */
	public static function remove( $key ) {
		$notices = self::all();
		if ( ! isset( $notices[ (string) $key ] ) ) {
			return;
		}
		unset( $notices[ (string) $key ] );
		Options::set( Options::NOTICES, $notices );
	}

	/**
	 * Every notice awaiting display, oldest first. A pure read: use flush()
	 * when actually rendering, so one-shot notices are not shown twice.
	 *
	 * @return array<string,array{type:string,message:string,persistent:bool,at:int}>
	 */
	public static function all() {
		$notices = Options::get( Options::NOTICES, array() );
		if ( ! is_array( $notices ) ) {
			return array();
		}

		$out = array();
		foreach ( $notices as $key => $notice ) {
			if ( ! is_array( $notice ) || ! isset( $notice['type'], $notice['message'] ) ) {
				continue;
			}
			$out[ (string) $key ] = array(
				'type'       => (string) $notice['type'],
				'message'    => (string) $notice['message'],
				'persistent' => ! empty( $notice['persistent'] ),
				'at'         => isset( $notice['at'] ) ? (int) $notice['at'] : 0,
			);
		}
		return $out;
	}

	/**
	 * Returns every notice and keeps only the persistent ones, so a one-shot
	 * notice is displayed exactly once. Call this from the renderer.
	 *
	 * @return array<string,array{type:string,message:string,persistent:bool,at:int}>
	 */
	public static function flush() {
		$notices = self::all();

		$keep = array();
		foreach ( $notices as $key => $notice ) {
			if ( $notice['persistent'] ) {
				$keep[ $key ] = $notice;
			}
		}
		if ( count( $keep ) !== count( $notices ) ) {
			Options::set( Options::NOTICES, $keep );
		}

		return $notices;
	}
}
