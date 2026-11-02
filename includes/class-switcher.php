<?php
/**
 * Switching a plugin to the Afterware build, and back.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Replaces an abandoned plugin with the Afterware-maintained build of it, and
 * puts the original back on request.
 *
 * The order is the whole design: back up what is on disk, download the package
 * and verify it against the signed manifest, write down where the backup went,
 * and only then let WordPress touch the plugins directory. Everything that can
 * fail before that last step fails with the site untouched. Both ends with a
 * best-effort rescan, because the stored report is now wrong about this plugin.
 *
 * The record is written *before* the install and completed after, because the
 * dangerous moment is the one in between: the upgrader deletes the plugin
 * directory before it writes the new files, so a PHP fatal or a timeout there
 * leaves a site with no plugin at all. A record that says "pending, the backup
 * is here" turns that into one click to recover; no record would turn it into a
 * support ticket.
 *
 * Nothing here decides what to trust: Updates::download_verified() does, on the
 * same terms as an ordinary update, and Switch_Guard decides what is allowed.
 */
final class Switcher {

	/**
	 * Whether a plugin can be switched to its Afterware build right now,
	 * against a manifest refreshed first if the cached one is stale.
	 *
	 * The difference from Switch_Guard::can_switch() is the refresh, and it is
	 * the difference between the two kinds of caller. A screen drawing a row
	 * asks the guard, which only ever reads what is already stored. Anything
	 * asking this — the admin's switch button, `wp afterware switch` — is
	 * about to act on the answer, so it is worth one request to make sure the
	 * answer is current.
	 *
	 * @param string $slug Plugin slug.
	 * @return true|WP_Error
	 */
	public static function can_switch( $slug ) {
		Manifest_Cache::get( (string) $slug );
		return Switch_Guard::can_switch( $slug );
	}

	/**
	 * Whether a switched plugin can be put back the way it was.
	 *
	 * @param string $slug Plugin slug.
	 * @return true|WP_Error
	 */
	public static function can_revert( $slug ) {
		return Switch_Guard::can_revert( $slug );
	}

	/**
	 * Backs the installed plugin up and installs the Afterware build over it.
	 *
	 * @param string $slug Plugin slug.
	 * @return array{from:string,to:string,backup:string}|WP_Error
	 */
	public static function switch_to_afterware( $slug ) {
		$slug = (string) $slug;

		// Refreshes a stale manifest before deciding: see can_switch() above.
		$allowed = self::can_switch( $slug );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file = (string) Inventory::file_for( $slug );
		$from        = self::installed_version( $plugin_file );
		$to          = (string) Switch_Guard::latest_version( $slug );

		$backup = Backups::create( $slug, $from );
		if ( is_wp_error( $backup ) ) {
			return self::failed( 'switch_failed', $slug, $backup );
		}

		$package = Updates::download_verified( $slug, $to );
		if ( is_wp_error( $package ) ) {
			return self::failed( 'switch_failed', $slug, $package );
		}

		Switch_Record::open(
			$slug,
			array(
				'upstream_version' => $from,
				'plugin_file'      => $plugin_file,
				'was_active'       => is_plugin_active( $plugin_file ),
				'backup'           => $backup['path'],
				'backup_sha256'    => $backup['sha256'],
			)
		);

		$proof     = Verified_Install::take_file( $package );
		$installed = Switch_Install::replace( $plugin_file, $package );
		wp_delete_file( $package );
		if ( is_wp_error( $installed ) ) {
			return self::install_failed( $slug, $backup['path'], $installed );
		}

		Switch_Record::complete( $slug );
		Verified_Install::record_switch( $plugin_file, $proof );
		self::log_switch( $slug, $from, $to, $backup );
		Connection::scan_quietly();

		return array(
			'from'   => $from,
			'to'     => $to,
			'backup' => $backup['path'],
		);
	}

	/**
	 * Installs the backup over the Afterware build and drops the record.
	 *
	 * The backup file itself is kept: a revert must never be the thing that
	 * destroys the only copy of the original plugin.
	 *
	 * @param string $slug Plugin slug.
	 * @return array{from:string,to:string,backup:string}|WP_Error
	 */
	public static function revert( $slug ) {
		$slug    = (string) $slug;
		$allowed = Switch_Guard::can_revert( $slug );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$backup = Backups::validated( $slug );
		if ( is_wp_error( $backup ) ) {
			return self::failed( 'revert_failed', $slug, $backup );
		}

		// After a switch that died mid-install the plugin directory is gone, so
		// get_plugins() no longer knows the file. The record does.
		$record       = (array) Switch_Record::get( $slug );
		$installed_as = Inventory::file_for( $slug );
		$plugin_file  = ( null === $installed_as ) ? (string) $record['plugin_file'] : $installed_as;
		$from         = self::installed_version( $plugin_file );
		$to           = (string) $record['upstream_version'];

		$installed = Switch_Install::replace( $plugin_file, $backup, $record['was_active'] );
		if ( is_wp_error( $installed ) ) {
			return self::failed( 'revert_failed', $slug, $installed );
		}

		Switch_Record::forget( $slug );
		Notices::remove( 'reactivate-' . $slug );
		Notices::remove( 'verify-' . $slug );
		Activity::log(
			'reverted',
			$slug,
			array(
				'from' => $from,
				'to'   => $to,
			),
			sprintf(
				/* translators: 1: plugin name, 2: Afterware version, 3: restored version. */
				__( '%1$s was reverted from the Afterware build %2$s to the original %3$s.', 'afterware' ),
				Inventory::name_for( $slug ),
				$from,
				$to
			)
		);

		Connection::scan_quietly();

		return array(
			'from'   => $from,
			'to'     => $to,
			'backup' => $backup,
		);
	}

	/**
	 * Records a switch that landed.
	 *
	 * @param string                                             $slug   Plugin slug.
	 * @param string                                             $from   Version replaced.
	 * @param string                                             $to     Version installed.
	 * @param array{path:string,sha256:string,skipped_links:int} $backup The backup that was made.
	 * @return void
	 */
	private static function log_switch( $slug, $from, $to, array $backup ) {
		Activity::log(
			'switched',
			$slug,
			array(
				'from'          => $from,
				'to'            => $to,
				'backup'        => $backup['path'],
				'skipped_links' => $backup['skipped_links'],
			),
			sprintf(
				/* translators: 1: plugin name, 2: previous version, 3: Afterware version. */
				__( '%1$s was switched to the Afterware build, from %2$s to %3$s.', 'afterware' ),
				Inventory::name_for( $slug ),
				$from,
				$to
			)
		);
	}

	/**
	 * Handles an install that did not complete: the record stays, marked
	 * failed, and the site owner is told in one sentence where their previous
	 * copy is and how to get it back.
	 *
	 * @param string   $slug   Plugin slug.
	 * @param string   $backup Absolute path to the backup.
	 * @param WP_Error $error  What the upgrader said.
	 * @return WP_Error
	 */
	private static function install_failed( $slug, $backup, WP_Error $error ) {
		Switch_Record::fail( $slug, $error->get_error_code() );

		Activity::log(
			'switch_failed',
			$slug,
			array(
				'code'   => $error->get_error_code(),
				'backup' => $backup,
			),
			$error->get_error_message()
		);

		return new WP_Error(
			'afterware_install_failed',
			sprintf(
				/* translators: 1: plugin name, 2: absolute path to the backup file. */
				__( 'The install did not complete. Your previous copy of %1$s is saved at %2$s; use Revert to restore it.', 'afterware' ),
				Inventory::name_for( $slug ),
				$backup
			)
		);
	}

	/**
	 * The version currently on disk.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return string
	 */
	private static function installed_version( $plugin_file ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_file( $path ) ) {
			return '';
		}
		$data = get_plugin_data( $path, false, false );
		return (string) $data['Version'];
	}

	/**
	 * Logs a failure that happened after the work had started, and hands the
	 * error straight back. Verification failures are already reported in full
	 * by Verification_Failure; this is the record that a switch was attempted.
	 *
	 * @param string   $kind  Activity kind, 'switch_failed' or 'revert_failed'.
	 * @param string   $slug  Plugin slug.
	 * @param WP_Error $error The failure.
	 * @return WP_Error
	 */
	private static function failed( $kind, $slug, WP_Error $error ) {
		Activity::log( $kind, $slug, array( 'code' => $error->get_error_code() ), $error->get_error_message() );
		return $error;
	}
}
