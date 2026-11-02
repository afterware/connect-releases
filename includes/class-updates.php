<?php
/**
 * Update offers and the WordPress update hooks.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * The trust core's front door: three WordPress hooks, in the order core runs
 * them.
 *
 * 1. `update_plugins_<host>` — during an update check, offers the manifest's
 *    latest release and records the offer (see Offers).
 * 2. `upgrader_pre_download` — when the user clicks update, in a later
 *    request, hands the package to Package_Guard before WordPress sees it.
 * 3. `upgrader_process_complete` — after an install, records what actually
 *    changed on disk and clears the plugin's verification notice.
 *
 * Nothing in this class decides what to trust: Manifest_Cache does the
 * verifying, Package_Guard guards the download, and Verification_Failure
 * handles every refusal.
 */
final class Updates {

	/**
	 * Registers the three update hooks. Wired on every request, not only in
	 * the admin: update checks and the upgrader also run during cron and
	 * WP-CLI.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_filter( 'update_plugins_' . AFTERWARE_UPDATES_HOST, array( $this, 'filter_update' ), 10, 4 );
		// Last, deliberately: a handler running after ours could replace the file
		// we verified with one we never checked.
		add_filter( 'upgrader_pre_download', array( $this, 'pre_download' ), PHP_INT_MAX, 4 );
		add_action( 'upgrader_process_complete', array( $this, 'after_upgrade' ), 10, 2 );
		add_filter( 'upgrader_post_install', array( Verified_Install::class, 'after_install' ), PHP_INT_MAX, 3 );
	}

	/**
	 * The verified manifest for a slug, or null when there is none to trust.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public static function manifest_for( $slug ) {
		return Manifest_Cache::get( $slug );
	}

	/**
	 * Answers WordPress's update check for a plugin served from our host.
	 *
	 * Returns false rather than the incoming $update when there is nothing to
	 * offer: for a plugin whose Update URI points at Afterware this plugin is
	 * the only legitimate source, so "we have nothing verified" has to mean no
	 * update, not whatever another handler left in place.
	 *
	 * @param array<string,mixed>|false $update      Update data from earlier handlers.
	 * @param array<string,mixed>       $plugin_data Plugin headers.
	 * @param string                    $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @param string[]                  $locales     Installed locales; unused, there are no translation packages yet.
	 * @return array<string,mixed>|false
	 */
	public function filter_update( $update, $plugin_data, $plugin_file, $locales = array() ) {
		unset( $update, $locales );

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// A WP-CLI process can live for hours, so a guard set at process
			// start would answer a much later update check from a stale cache.
			// Clearing it here costs nothing: the refresh fetches every one of
			// our slugs at once, so the plugins checked after the first still
			// read a fresh entry without another request.
			Manifest_Cache::reset_request_guard();
		}

		$slug     = Inventory::slug_for( (string) $plugin_file );
		$manifest = self::manifest_for( $slug );
		if ( null === $manifest || null === $manifest['latest'] ) {
			return false;
		}

		$installed = isset( $plugin_data['Version'] ) ? (string) $plugin_data['Version'] : '';
		if ( '' === $installed || ! version_compare( (string) $manifest['latest'], $installed, '>' ) ) {
			return false;
		}

		$release = self::release( $manifest, (string) $manifest['latest'] );
		if ( null === $release ) {
			return false;
		}

		Offers::remember( $slug, $release, $installed );

		return self::offer( $slug, $release, $plugin_data );
	}

	/**
	 * Hands a package WordPress is about to install to Package_Guard.
	 *
	 * @param string|false|WP_Error $reply      A download already produced by another handler, or false.
	 * @param string                $package    Package URL WordPress is about to download.
	 * @param mixed                 $upgrader   The WP_Upgrader running the install; unused.
	 * @param mixed                 $hook_extra Upgrader context; an array in practice, but a filter may pass anything.
	 * @return string|false|WP_Error
	 */
	public function pre_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		unset( $upgrader );
		$context = is_array( $hook_extra ) ? $hook_extra : array();
		Verified_Install::begin( $context );
		$result = Package_Guard::check( $reply, $package, $context );
		Verified_Install::bind( $result, $context );
		return $result;
	}

	/**
	 * Downloads a named version of a plugin and verifies it against the current
	 * signed manifest, returning the local path only when it checks out.
	 *
	 * The same authorisation and hash check `upgrader_pre_download` applies,
	 * reachable by name rather than by URL: the switcher installs a package
	 * outside an update, where WordPress supplies no plugin context for the
	 * filter to work from. A failure is already logged, noticed and ticketed by
	 * the time it is returned.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version to fetch, as named in the manifest.
	 * @return string|WP_Error
	 */
	public static function download_verified( $slug, $version ) {
		return Package_Guard::verified_download( $slug, $version );
	}

	/**
	 * Records an update of one of our plugins that actually happened.
	 *
	 * WordPress fires this hook once after a bulk run, including failed members.
	 * This local activity entry compares each installed header with its offer;
	 * it does not prove package verification. Verified_Install separately binds
	 * each package check to that plugin's individual post-install result.
	 *
	 * @param mixed $upgrader   The WP_Upgrader that ran; unused.
	 * @param mixed $hook_extra Upgrader context: action, type and the plugins involved.
	 * @return void
	 */
	public function after_upgrade( $upgrader, $hook_extra = array() ) {
		unset( $upgrader );

		if ( ! is_array( $hook_extra ) ) {
			return;
		}
		$type   = isset( $hook_extra['type'] ) ? $hook_extra['type'] : '';
		$action = isset( $hook_extra['action'] ) ? $hook_extra['action'] : '';
		if ( 'plugin' !== $type || 'update' !== $action ) {
			return;
		}

		foreach ( self::upgraded_files( $hook_extra ) as $plugin_file ) {
			$slug  = Inventory::slug_for( $plugin_file );
			$offer = Offers::for_slug( $slug );
			if ( null === $offer || ! self::is_installed( $plugin_file, $offer['version'] ) ) {
				continue;
			}

			Activity::log(
				'updated',
				$slug,
				array(
					'from' => $offer['from'],
					'to'   => $offer['version'],
				),
				sprintf(
					/* translators: 1: plugin name, 2: previous version, 3: new version. */
					__( '%1$s was updated from %2$s to %3$s.', 'afterware' ),
					Inventory::name_for( $slug ),
					$offer['from'],
					$offer['version']
				)
			);
			Notices::remove( 'verify-' . $slug );
			Offers::forget_slug( $slug );
		}
	}

	/**
	 * Whether the plugin on disk now reads as a given version.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @param string $version     Version the update was supposed to install.
	 * @return bool
	 */
	private static function is_installed( $plugin_file, $version ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_file( $path ) ) {
			return false;
		}

		$data = get_plugin_data( $path, false, false );
		return (string) $data['Version'] === (string) $version;
	}

	/**
	 * The update array wp_update_plugins() expects for an available update.
	 *
	 * Core supplies `id` and `plugin` itself and needs at least `version`; the
	 * optional `requires`, `tested` and `requires_php` come straight from the
	 * manifest's release entry and are only sent when the manifest has them.
	 *
	 * `autoupdate` is deliberately omitted: WordPress applies the site owner's
	 * per-plugin auto-update choice, and Afterware does not override it.
	 *
	 * @param string              $slug        Plugin slug.
	 * @param array<string,mixed> $release     The manifest release being offered.
	 * @param array<string,mixed> $plugin_data Plugin headers.
	 * @return array<string,mixed>
	 */
	private static function offer( $slug, array $release, array $plugin_data ) {
		$update_uri = ( isset( $plugin_data['UpdateURI'] ) && '' !== $plugin_data['UpdateURI'] )
			? (string) $plugin_data['UpdateURI']
			: 'https://' . AFTERWARE_UPDATES_HOST . '/' . $slug;

		$offer = array(
			'id'      => $update_uri,
			'slug'    => $slug,
			'version' => (string) $release['version'],
			'url'     => 'https://afterware.io/plugins/' . $slug,
			'package' => (string) $release['package_url'],
		);

		$optional = array(
			'requires'     => 'requires_wp',
			'tested'       => 'tested_wp',
			'requires_php' => 'requires_php',
		);
		foreach ( $optional as $offer_key => $release_key ) {
			if ( isset( $release[ $release_key ] ) && is_string( $release[ $release_key ] ) && '' !== $release[ $release_key ] ) {
				$offer[ $offer_key ] = $release[ $release_key ];
			}
		}

		return $offer;
	}

	/**
	 * The plugin files an upgrade touched: `plugins` for a bulk update,
	 * `plugin` for a single one.
	 *
	 * @param array<string,mixed> $hook_extra Upgrader context.
	 * @return array<int,string>
	 */
	private static function upgraded_files( array $hook_extra ) {
		if ( isset( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) ) {
			return array_values( array_map( 'strval', $hook_extra['plugins'] ) );
		}
		if ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) {
			return array( $hook_extra['plugin'] );
		}
		return array();
	}

	/**
	 * The release entry for a version, or null when the manifest has none.
	 *
	 * @param array<string,mixed> $manifest Parsed manifest.
	 * @param string              $version  Version to look up.
	 * @return array<string,mixed>|null
	 */
	private static function release( array $manifest, $version ) {
		foreach ( $manifest['releases'] as $release ) {
			if ( $version === $release['version'] ) {
				return $release;
			}
		}
		return null;
	}
}
