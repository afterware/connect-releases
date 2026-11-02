<?php
/**
 * The preconditions a switch and a revert have to meet.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Answers "may this happen?" for switching and reverting, separately from
 * Switcher, which answers "do it".
 *
 * Every check is side-effect free: rendering a row must not create a directory,
 * fetch a package or write an option. Screens and operations share each refusal.
 */
final class Switch_Guard {

	/**
	 * Whether a plugin can be switched to its Afterware build right now.
	 *
	 * @param string $slug Plugin slug.
	 * @return true|WP_Error
	 */
	public static function can_switch( $slug ) {
		return self::switch_offer( $slug )['allowed'];
	}

	/**
	 * Return the decision and offered version from one verified cache read.
	 *
	 * @param string $slug Plugin slug.
	 * @return array{allowed:true|WP_Error,latest:string|null}
	 */
	public static function switch_offer( $slug ) {
		$slug     = (string) $slug;
		$manifest = Manifest_Cache::peek( $slug );
		$latest   = is_array( $manifest ) ? $manifest['latest'] : null;

		return array(
			'latest'  => $latest,
			'allowed' => self::decide_switch( $slug, $latest, $manifest ),
		);
	}

	/**
	 * The preconditions themselves, given the version already looked up.
	 *
	 * @param string                   $slug   Plugin slug.
	 * @param string|null              $latest   The Afterware build on offer, or null.
	 * @param array<string,mixed>|null $manifest Verified cache read.
	 * @return true|WP_Error
	 */
	private static function decide_switch( $slug, $latest, $manifest ) {
		$allowed = self::may_manage_plugins();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		// Checked before "is it installed" and "is it already switched": after a
		// switch that failed mid-install the plugin directory can be gone, which
		// would otherwise read as "not installed" and hide the real problem —
		// and a pending switch is not "already switched" either, it is unfinished.
		$record = Switch_Record::get( $slug );
		if ( null !== $record && ( ! empty( $record['pending'] ) || null !== $record['failed_at'] ) ) {
			return new WP_Error(
				'afterware_switch_pending',
				sprintf(
					/* translators: %s: plugin name. */
					__( 'A previous switch of %s did not finish. Revert it first, then try again.', 'afterware' ),
					Inventory::name_for( $slug )
				)
			);
		}

		$plugin_file = Inventory::file_for( $slug );
		if ( null === $plugin_file ) {
			return self::not_installed( $slug );
		}

		if ( self::is_network_active( $plugin_file ) ) {
			return new WP_Error(
				'afterware_network_active',
				sprintf(
					/* translators: %s: plugin name. */
					__( '%s is active for the whole network. Afterware Connect switches plugins per site only, so this one stays as it is.', 'afterware' ),
					Inventory::name_for( $slug )
				)
			);
		}

		if ( '.' === dirname( $plugin_file ) ) {
			// A plugin that is one loose file in wp-content/plugins has no
			// directory to replace, and Plugin_Upgrader only installs
			// directories. Refusing is honest; guessing is not.
			return new WP_Error(
				'afterware_single_file',
				sprintf(
					/* translators: %s: plugin name. */
					__( '%s is a single-file plugin, which Afterware cannot switch yet.', 'afterware' ),
					Inventory::name_for( $slug )
				)
			);
		}

		if ( null !== $record ) {
			return new WP_Error(
				'afterware_already_switched',
				sprintf(
					/* translators: %s: plugin name. */
					__( '%s is already on Afterware. Revert it first if you want to go back to the original.', 'afterware' ),
					Inventory::name_for( $slug )
				)
			);
		}

		$access = Update_Access::error();
		if ( null !== $access ) {
			return $access;
		}
		if ( null === $latest ) {
			return new WP_Error(
				'afterware_no_build',
				sprintf(
					/* translators: %s: plugin name. */
					__( 'Afterware has no verified build of %s to switch to yet.', 'afterware' ),
					Inventory::name_for( $slug )
				)
			);
		}

		$version_error = Switch_Version::error( $plugin_file, $latest );
		if ( null !== $version_error ) {
			return $version_error;
		}

		$requirements = Runtime_Requirements::for_latest( $manifest );
		if ( null !== $requirements ) {
			return $requirements;
		}

		$ready = Switch_Install::ready();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		return Backups::writable();
	}

	/**
	 * Whether a switched plugin can be put back the way it was.
	 *
	 * A pending/failed switch still permits revert. Archive integrity is checked
	 * before unpacking, separately from this side-effect-free readiness check.
	 *
	 * @param string $slug Plugin slug.
	 * @return true|WP_Error
	 */
	public static function can_revert( $slug ) {
		$slug = (string) $slug;

		$allowed = self::may_manage_plugins();
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( null === Switch_Record::get( $slug ) ) {
			return new WP_Error(
				'afterware_not_switched',
				sprintf(
					/* translators: %s: plugin name. */
					__( '%s is not on an Afterware build, so there is nothing to revert.', 'afterware' ),
					Inventory::name_for( $slug )
				)
			);
		}

		$backup = Backups::path( $slug );
		if ( null === $backup || ! is_file( $backup ) ) {
			return new WP_Error(
				'afterware_backup_missing',
				sprintf(
					/* translators: %s: absolute path to the backup file. */
					__( 'The backup Afterware made of this plugin is no longer at %s, so it cannot be restored. Reinstall the original plugin from its own source.', 'afterware' ),
					(string) $backup
				)
			);
		}

		return Switch_Install::ready();
	}

	/**
	 * The version of the Afterware build on offer, or null when there is none.
	 *
	 * Never fetches during render. A switch, update check or scan refreshes the
	 * cache itself before acting.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|null
	 */
	public static function latest_version( $slug ) {
		$manifest = Manifest_Cache::peek( $slug );
		if ( ! is_array( $manifest ) || ! is_string( $manifest['latest'] ) || '' === $manifest['latest'] ) {
			return null;
		}
		return $manifest['latest'];
	}

	/**
	 * Whether the current user may change the plugins on this site.
	 *
	 * Public because the admin has to ask the same question a step earlier
	 * than can_switch() does: a screen greys the switch card out and explains
	 * it before a slug is even in play, and the action handler refuses the
	 * POST before it reaches the switcher. One answer, one sentence, one
	 * place to change it.
	 *
	 * @return true|WP_Error
	 */
	public static function may_manage_plugins() {
		$allowed = is_multisite()
			? current_user_can( 'manage_network_plugins' )
			: current_user_can( 'install_plugins' );

		if ( $allowed ) {
			return true;
		}
		return new WP_Error( 'afterware_forbidden', __( 'You do not have permission to install or change plugins on this site.', 'afterware' ) );
	}

	/**
	 * Whether a plugin is switched on for every site in the network.
	 *
	 * Connect's activation, backups and switch record are per site. Replacing
	 * network-active files would affect every site without their consent.
	 * Those plugins remain inventoried and reported, but cannot be switched.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return bool
	 */
	private static function is_network_active( $plugin_file ) {
		if ( ! self::is_multisite() ) {
			return false;
		}
		$network = get_site_option( 'active_sitewide_plugins', array() );

		return is_array( $network ) && isset( $network[ $plugin_file ] );
	}

	/**
	 * Whether this install is a network.
	 *
	 * The seam exercises network refusal despite is_multisite() using a constant.
	 * WordPress's capability map still reads the real network state; it must
	 * not depend on this test seam.
	 *
	 * OR with the real answer so the filter can only turn protection on.
	 * Returning false on a real network must never let one site replace
	 * the files every site runs.
	 *
	 * @return bool
	 */
	public static function is_multisite() {
		/**
		 * Filters Afterware into treating this install as a network.
		 *
		 * Only true takes effect: the result is ORed with is_multisite(), so a
		 * filter can make a single site behave like a network but can never
		 * make a network stop behaving like one.
		 *
		 * @param bool $multisite Always false; return true to force the network checks on.
		 */
		return is_multisite() || (bool) apply_filters( 'afterware_is_multisite', false );
	}

	/**
	 * The error for a slug nothing is installed under.
	 *
	 * @param string $slug Plugin slug.
	 * @return WP_Error
	 */
	private static function not_installed( $slug ) {
		return new WP_Error(
			'afterware_not_installed',
			sprintf(
				/* translators: %s: plugin slug. */
				__( 'No plugin is installed under the slug %s.', 'afterware' ),
				$slug
			)
		);
	}
}
