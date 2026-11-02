<?php
/**
 * Bootstraps the plugin.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Wires activation and boot hooks for the plugin.
 */
final class Plugin {

	/**
	 * Runs on plugin activation.
	 *
	 * Refuses network activation on multisite (single-site only in this
	 * version), then schedules the daily scan and sets a transient the
	 * admin uses to run registration on the next admin request.
	 *
	 * WordPress passes $network_wide to the `activate_{plugin}` hook (true
	 * when activated network-wide, e.g. via `wp plugin activate --network`
	 * or the "Network Activate" link); the admin UI additionally appends
	 * `?networkwide=1` to the activation URL when activating from the
	 * network admin plugins screen, which is why both are checked.
	 *
	 * @param bool $network_wide Whether the plugin is being network-activated.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An activation hook runs before any nonce for this WordPress-core-supplied query var could exist, and this only ever refuses; it never acts on the value.
		if ( is_multisite() && ( $network_wide || isset( $_GET['networkwide'] ) ) ) {
			wp_die( esc_html__( 'Afterware Connect supports single sites in this version. Activate it per site instead of network-wide.', 'afterware' ) );
		}
		if ( ! wp_next_scheduled( 'afterware_daily_scan' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'afterware_daily_scan' );
		}
		set_transient( 'afterware_activated', 1, 10 * MINUTE_IN_SECONDS );
	}

	/**
	 * Runs on plugins_loaded: loads translations and wires the modules that exist.
	 *
	 * The `Admin\Admin` and `CLI\Commands` classes land in later tasks; guard
	 * with class_exists() so this boots cleanly until they do. Registration
	 * on the `afterware_activated` transient set by activate() happens in the
	 * admin task, not here.
	 *
	 * The update hooks are wired on every request, not only in the admin:
	 * WordPress checks for plugin updates and runs the upgrader from cron and
	 * WP-CLI as well, and an unverified package must be refused there too.
	 *
	 * The key ring refresh runs after the scan, on the same daily event.
	 * Key_Ring_Fetch::refresh() is one call that logs its own outcome to
	 * Activity and never throws, so a ring that cannot be reached or refused
	 * is quiet: nothing here renders, and this only ever runs on cron.
	 *
	 * @return void
	 */
	public static function boot() {
		load_plugin_textdomain( 'afterware', false, dirname( plugin_basename( AFTERWARE_FILE ) ) . '/languages' );
		add_action( 'afterware_daily_scan', array( Connection::class, 'scan_quietly' ) );
		add_action( 'afterware_daily_scan', array( __CLASS__, 'refresh_key_ring' ) );
		add_action( 'afterware_daily_scan', array( First_Install_Receipt::class, 'flush' ) );
		add_action( First_Install_Receipt::HOOK, array( First_Install_Receipt::class, 'flush' ) );
		add_action( Connection::REFRESH_HOOK, array( __CLASS__, 'refresh_manifests' ) );
		( new Updates() )->register_hooks();
		if ( is_admin() && class_exists( 'Afterware\\Admin\\Admin' ) ) {
			( new Admin\Admin() )->register_hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( 'Afterware\\CLI\\Commands' ) ) {
			\WP_CLI::add_command( 'afterware', CLI\Commands::class );
		}
	}

	/**
	 * Fetches a verified manifest for every plugin already served from
	 * Afterware, on the single event a fresh connection books.
	 *
	 * `Manifest_Cache::get()` refreshes at most once per request and fetches
	 * every one of our slugs in that one call, so the loop costs one `/updates`
	 * request however many plugins are on an Afterware build. The guard is
	 * cleared first because this may run inside a long-lived cron or WP-CLI
	 * process that has already looked once.
	 *
	 * @return void
	 */
	public static function refresh_manifests() {
		Manifest_Cache::reset_request_guard();
		foreach ( Inventory::our_plugins() as $slug ) {
			Manifest_Cache::get( $slug );
		}
	}

	/**
	 * Fetches and verifies the published key ring, on the daily cron event.
	 *
	 * A thin wrapper rather than hooking Key_Ring_Fetch::refresh() directly:
	 * do_action( 'afterware_daily_scan' ) is called with no argument of its
	 * own, and WordPress substitutes an empty string for every callback on
	 * the hook in that case (see do_action()'s handling of an empty $arg).
	 * refresh()'s first parameter is a transport seam that must stay null to
	 * use the real one, so this takes nothing and calls it with nothing.
	 *
	 * @return void
	 */
	public static function refresh_key_ring() {
		Key_Ring_Fetch::refresh();
	}
}
