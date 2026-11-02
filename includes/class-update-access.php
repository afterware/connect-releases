<?php
/**
 * Authoritative update delivery refusals, separate from signature failures.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/** Leaves installed code alone while stopping new downloads after an access denial. */
final class Update_Access {
	/**
	 * Handles only explicit access denials. Called during an action, never a render.
	 *
	 * @param WP_Error $error API failure.
	 * @return bool Whether the error denied access.
	 */
	public static function denied( WP_Error $error ) {
		$data = $error->get_error_data();
		if ( ! is_array( $data ) || ! isset( $data['status'] ) || ! in_array( $data['status'], array( 401, 402, 403 ), true ) ) {
			return false;
		}
		Options::set( Options::UPDATE_ACCESS, array( 'status' => $data['status'] ) );
		Options::delete( Options::MANIFESTS );
		// Keep offer provenance: context-free upgrader callbacks still need to be
		// recognized as ours. An offer never authorizes a package without a manifest.
		self::remove_pending_updates();
		Notices::add( 'update-access', 'warning', self::message( $data['status'] ), true );
		Activity::log( 'update_access_denied', null, array( 'status' => $data['status'] ), self::message( $data['status'] ) );
		return true;
	}

	/**
	 * Read-only access refusal for the download guard and administrator.
	 *
	 * @return WP_Error|null
	 */
	public static function error() {
		$saved  = Options::get( Options::UPDATE_ACCESS );
		$status = is_array( $saved ) && isset( $saved['status'] ) ? $saved['status'] : null;
		return in_array( $status, array( 401, 402, 403 ), true )
			? new WP_Error( 'afterware_update_access', self::message( $status ), array( 'status' => $status ) )
			: null;
	}

	/** Clears an access refusal after a structured successful update response. */
	public static function recovered(): void {
		if ( null !== self::error() ) {
			Options::delete( Options::UPDATE_ACCESS );
			Notices::remove( 'update-access' );
		}
	}

	/**
	 * Fixed copy; provider payloads never enter the notice or security tickets.
	 *
	 * @param int $status HTTP status.
	 * @return string
	 */
	private static function message( $status ) {
		return 402 === $status
			? __( 'Afterware updates are paused for this site. Check site coverage and billing in your Afterware account. Installed plugins keep working, and local reverts remain available.', 'afterware' )
			: __( 'Afterware could not authorize updates for this site. Check the connection in Afterware Settings or contact support. Installed plugins keep working, and local reverts remain available.', 'afterware' );
	}

	/** Removes only our pending updates, preserving other plugins and core metadata. */
	private static function remove_pending_updates(): void {
		$updates = get_site_transient( 'update_plugins' );
		if ( ! is_object( $updates ) || ! isset( $updates->response ) || ! is_array( $updates->response ) ) {
			return;
		}
		$changed = false;
		foreach ( Inventory::our_plugins() as $file => $slug ) {
			if ( isset( $updates->response[ $file ] ) ) {
				unset( $updates->response[ $file ] );
				$changed = true;
			}
		}
		if ( $changed ) {
			set_site_transient( 'update_plugins', $updates );
		}
	}
}
