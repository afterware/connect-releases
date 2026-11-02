<?php
/**
 * The upgrader plumbing a switch and a revert share.
 *
 * @package Afterware
 */

namespace Afterware;

use Automatic_Upgrader_Skin;
use Plugin_Upgrader;
use WP_Error;

/**
 * Installs a local ZIP over a plugin that is already installed, and puts its
 * activation state back afterwards.
 *
 * Switching and reverting are the same operation with different archives, so
 * both go through here. The package is always a local file that has already
 * been verified, and it stays that file: `Plugin_Upgrader::install()` passes no
 * plugin in its hook context, so `upgrader_pre_download` has nothing to tie a
 * package to and must never be the thing that authorises an install — nor the
 * thing that replaces it.
 */
final class Switch_Install {

	/**
	 * Slugs whose install has started and not yet returned. The shutdown
	 * handler reads this to tell "PHP died in the middle" apart from "the
	 * install finished, however it went".
	 *
	 * @var array<string,bool>
	 */
	private static $in_flight = array();

	/**
	 * Whether WordPress can write to the plugins directory on its own.
	 *
	 * Afterware only installs where the direct filesystem method applies. On a
	 * host that needs FTP or SSH credentials the switch is refused up front,
	 * rather than half-performed behind a credentials form nobody filled in.
	 *
	 * @return true|WP_Error
	 */
	public static function ready() {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( 'direct' === get_filesystem_method( array(), WP_PLUGIN_DIR, true ) && WP_Filesystem() ) {
			return true;
		}

		return new WP_Error(
			'afterware_fs',
			__( 'WordPress cannot write to the plugins directory without FTP credentials; switch from the Plugins screen is not available on this host yet.', 'afterware' )
		);
	}

	/**
	 * Installs a local package over an installed plugin, keeping the plugin's
	 * activation state.
	 *
	 * A plugin that was active is deactivated silently first, so the old code
	 * is not left running against files that are being replaced, and activated
	 * again afterwards — noisily, so the new build's activation hook runs. That
	 * also restores the previous state when the install itself fails.
	 *
	 * If PHP dies in the middle — the upgrader has cleared the plugin directory
	 * and not yet written the new files — a shutdown handler marks the record
	 * failed, so the site owner is told which backup to restore instead of
	 * finding a plugin that silently vanished.
	 *
	 * @param string    $plugin_file  Plugin file relative to WP_PLUGIN_DIR.
	 * @param string    $package_path Absolute path to a verified ZIP.
	 * @param bool|null $was_active   Force the activation state to restore, or null to
	 *                                read it off the site. A revert after a failed
	 *                                switch has to pass the state from before that
	 *                                switch: by then the plugin is already deactivated.
	 * @return true|WP_Error
	 */
	public static function replace( $plugin_file, $package_path, $was_active = null ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$plugin_file  = (string) $plugin_file;
		$package_path = (string) $package_path;
		$slug         = Inventory::slug_for( $plugin_file );
		$was_active   = ( null === $was_active ) ? is_plugin_active( $plugin_file ) : (bool) $was_active;
		$network_wide = is_multisite() && is_plugin_active_for_network( $plugin_file );

		self::$in_flight[ $slug ] = true;
		register_shutdown_function(
			static function () use ( $slug ) {
				if ( isset( self::$in_flight[ $slug ] ) ) {
					Switch_Record::fail( $slug, 'shutdown' );
				}
			}
		);

		if ( $was_active ) {
			deactivate_plugins( $plugin_file, true, $network_wide );
		}

		$installed = self::run_upgrader( $slug, $package_path );
		// Decision: the shutdown window closes here, before reactivate() runs,
		// deliberately. A fatal in the new build's own activation hook is the
		// new build's problem, not "PHP died mid-install" — by this point the
		// files are on disk and the install already returned. If activation
		// itself dies, the record is simply left `pending` (never completed by
		// Switcher), and can_revert() still honours a pending record, so this
		// is recoverable the same way any other unfinished switch is.
		unset( self::$in_flight[ $slug ] );

		if ( $was_active ) {
			self::reactivate( $plugin_file, $network_wide );
		}

		return $installed;
	}

	/**
	 * Runs Plugin_Upgrader over a local package, with the package pinned.
	 *
	 * The upgrader passes the path through `upgrader_pre_download` before it
	 * looks at it, so any handler on that filter could hand it a different file
	 * to install. A closure at the very end of the chain returns the verified
	 * path for the duration of this one install, and is removed either way.
	 *
	 * @param string $slug         Plugin slug, which the install must land under.
	 * @param string $package_path Absolute path to a verified ZIP.
	 * @return true|WP_Error
	 */
	private static function run_upgrader( $slug, $package_path ) {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$pin = static function ( $reply, $package ) use ( $package_path ) {
			return ( (string) $package === $package_path ) ? $package_path : $reply;
		};
		add_filter( 'upgrader_pre_download', $pin, PHP_INT_MAX, 2 );

		// Automatic_Upgrader_Skin::header() opens an output buffer without
		// setting done_header, so WP_Upgrader_Skin::error() opens a second one
		// that footer() never closes. On a failed switch that buffer would
		// swallow the rest of the page, so unwind to where we started.
		$buffers  = ob_get_level();
		$upgrader = new Plugin_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->install( $package_path, array( 'overwrite_package' => true ) );

		remove_filter( 'upgrader_pre_download', $pin, PHP_INT_MAX );
		while ( ob_get_level() > $buffers ) {
			ob_end_clean();
		}

		if ( is_wp_error( $result ) ) {
			return new WP_Error( 'afterware_install_failed', $result->get_error_message() );
		}
		if ( true !== $result ) {
			return self::install_failed();
		}

		// The archive decides the directory it unpacks into. An archive that
		// landed anywhere but this plugin's own directory has not replaced the
		// plugin, whatever else it did.
		$destination = ( is_array( $upgrader->result ) && isset( $upgrader->result['destination_name'] ) )
			? (string) $upgrader->result['destination_name']
			: '';
		if ( $destination !== $slug ) {
			return self::install_failed();
		}

		return true;
	}

	/**
	 * The generic install failure. The caller adds the sentence about the
	 * backup, since it is the one that knows where the backup went.
	 *
	 * @return WP_Error
	 */
	private static function install_failed() {
		return new WP_Error(
			'afterware_install_failed',
			__( 'WordPress could not install the plugin files.', 'afterware' )
		);
	}

	/**
	 * Activates a plugin again after its files were replaced, and says so
	 * plainly when that fails: the files did change, so silence would leave the
	 * site quietly missing a plugin it had a moment ago.
	 *
	 * When the plugin directory itself is missing, files were never replaced
	 * at all: the install died after the upgrader cleared it and before it
	 * wrote anything back. That is a different, more honest sentence than
	 * "replaced but could not activate" — activate_plugin() would fail on a
	 * missing file too, but blaming activation would misname what happened.
	 *
	 * @param string $plugin_file  Plugin file relative to WP_PLUGIN_DIR.
	 * @param bool   $network_wide Whether it was active for the whole network.
	 * @return void
	 */
	private static function reactivate( $plugin_file, $network_wide ) {
		$slug = Inventory::slug_for( $plugin_file );

		if ( ! is_file( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			$message = sprintf(
				/* translators: %s: plugin name. */
				__( 'The install of %s did not complete; use Revert to restore it.', 'afterware' ),
				Inventory::name_for( $slug )
			);
			Activity::log( 'reactivation_failed', $slug, array( 'code' => 'afterware_missing' ), $message );
			Notices::add( 'reactivate-' . $slug, 'warning', $message, true );
			return;
		}

		$activated = activate_plugin( $plugin_file, '', $network_wide );
		if ( ! is_wp_error( $activated ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: plugin name. */
			__( 'Afterware replaced the files for %s, but WordPress could not activate it again. Activate it from the Plugins screen, or revert to the previous version.', 'afterware' ),
			Inventory::name_for( $slug )
		);

		Activity::log( 'reactivation_failed', $slug, array( 'code' => $activated->get_error_code() ), $message );
		Notices::add( 'reactivate-' . $slug, 'warning', $message, true );
	}
}
