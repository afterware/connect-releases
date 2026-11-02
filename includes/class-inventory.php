<?php
/**
 * Collects the site's plugin and theme inventory.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Builds the exact InventorySchema payload sent to POST /v1/inventory, and
 * identifies which installed plugins are already on an Afterware build.
 */
final class Inventory {

	/**
	 * Plugin files skipped by the most recent collect() call because their
	 * main file could not be hashed.
	 *
	 * @var array<int,string>
	 */
	private static $skipped = array();

	/**
	 * Collects the full inventory payload.
	 *
	 * @return array<string,mixed> Exactly the InventorySchema fields.
	 */
	public static function collect() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		return array(
			'schema_version' => 1,
			'site_url'       => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'php_version'    => PHP_VERSION,
			'multisite'      => is_multisite(),
			'locale'         => get_locale(),
			'client_version' => AFTERWARE_VERSION,
			'plugins'        => self::plugins(),
			'themes'         => self::themes(),
		);
	}

	/**
	 * The slug for a plugin file as returned by get_plugins(): the plugin's
	 * directory name, or the file's basename (without .php) for a single-file
	 * plugin that lives directly in the plugins directory.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR, e.g. 'demo-plugin/demo-plugin.php'.
	 * @return string
	 */
	public static function slug_for( $plugin_file ) {
		$dir = dirname( $plugin_file );
		if ( '.' !== $dir ) {
			return $dir;
		}
		return basename( $plugin_file, '.php' );
	}

	/**
	 * The installed plugin's display name for a slug, falling back to the
	 * slug itself when nothing by that slug is installed.
	 *
	 * Used wherever a message is written for a person to read: "Demo Plugin"
	 * means something to a site owner, "demo-plugin" does not.
	 *
	 * @param string $slug Plugin slug as derived by slug_for().
	 * @return string
	 */
	public static function name_for( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( get_plugins() as $file => $data ) {
			if ( self::slug_for( $file ) !== $slug ) {
				continue;
			}
			$name = isset( $data['Name'] ) ? (string) $data['Name'] : '';
			return '' === $name ? (string) $slug : $name;
		}
		return (string) $slug;
	}

	/**
	 * The plugin file installed under a slug, or null when nothing is.
	 *
	 * The inverse of slug_for(): the switcher works from a slug (that is what
	 * the manifest and the API name a plugin by) and needs the file WordPress
	 * activates and updates it by.
	 *
	 * @param string $slug Plugin slug as derived by slug_for().
	 * @return string|null
	 */
	public static function file_for( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( array_keys( get_plugins() ) as $file ) {
			if ( self::slug_for( (string) $file ) === (string) $slug ) {
				return (string) $file;
			}
		}
		return null;
	}

	/**
	 * The version in the header of whatever is installed under a slug, or ''
	 * when nothing is.
	 *
	 * Read from get_plugins(), which is the same header parse get_plugin_data()
	 * does and is cached for the whole request: a screen asks this once per row
	 * and must not read a file per row to do it.
	 *
	 * @param string $slug Plugin slug as derived by slug_for().
	 * @return string
	 */
	public static function version_for( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		foreach ( get_plugins() as $file => $data ) {
			if ( self::slug_for( (string) $file ) !== (string) $slug ) {
				continue;
			}
			return isset( $data['Version'] ) ? (string) $data['Version'] : '';
		}
		return '';
	}

	/**
	 * Plugin files skipped by the most recent collect() call because their
	 * main file could not be hashed (missing or unreadable). Those plugins
	 * are omitted from plugins[] entirely; the inventory payload itself
	 * never carries anything but the exact schema keys.
	 *
	 * @return array<int,string>
	 */
	public static function last_skipped() {
		return self::$skipped;
	}

	/**
	 * Installed plugins whose Update URI host matches AFTERWARE_UPDATES_HOST.
	 *
	 * @return array<string,string> Plugin file => slug.
	 */
	public static function our_plugins() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$ours = array();
		foreach ( get_plugins() as $file => $data ) {
			$uri = isset( $data['UpdateURI'] ) ? $data['UpdateURI'] : '';
			if ( '' === $uri ) {
				continue;
			}
			$host = wp_parse_url( $uri, PHP_URL_HOST );
			if ( is_string( $host ) && 0 === strcasecmp( $host, AFTERWARE_UPDATES_HOST ) ) {
				$ours[ $file ] = self::slug_for( $file );
			}
		}
		return $ours;
	}

	/**
	 * Builds the `plugins` entries of the inventory.
	 *
	 * A plugin whose main file cannot be hashed (missing or unreadable) is
	 * omitted entirely rather than sent with an empty hash: the platform's
	 * schema requires a 64-hex-char main_file_sha256, so one unreadable
	 * plugin file must not 400 the whole inventory. Skipped files are
	 * recorded for last_skipped() to report.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function plugins() {
		$active  = (array) get_option( 'active_plugins', array() );
		$network = (array) get_site_option( 'active_sitewide_plugins', array() );

		$out     = array();
		$skipped = array();
		foreach ( get_plugins() as $file => $data ) {
			$hash = Verifier::sha256_file( WP_PLUGIN_DIR . '/' . $file );
			if ( false === $hash ) {
				$skipped[] = $file;
				continue;
			}
			$uri = isset( $data['UpdateURI'] ) ? (string) $data['UpdateURI'] : '';

			$out[] = array(
				'slug'             => self::slug_for( $file ),
				'version'          => isset( $data['Version'] ) ? (string) $data['Version'] : '',
				'active'           => in_array( $file, $active, true ) || array_key_exists( $file, $network ),
				'main_file_sha256' => $hash,
				'readme_sha256'    => self::readme_sha256( $file ),
				'update_uri'       => '' === $uri ? null : $uri,
			);
		}
		self::$skipped = $skipped;
		return $out;
	}

	/**
	 * Hashes a plugin's readme, when it has one: readme.txt first, then
	 * README.txt, whichever exists first.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return string|null Lowercase hex digest, or null when there is no readme.
	 */
	private static function readme_sha256( $plugin_file ) {
		$dir = dirname( WP_PLUGIN_DIR . '/' . $plugin_file );
		foreach ( array( 'readme.txt', 'README.txt' ) as $name ) {
			$path = $dir . '/' . $name;
			if ( is_file( $path ) ) {
				$hash = Verifier::sha256_file( $path );
				return false === $hash ? null : $hash;
			}
		}
		return null;
	}

	/**
	 * Builds the `themes` entries of the inventory.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function themes() {
		$active_stylesheet = get_stylesheet();

		$out = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out[] = array(
				'slug'    => $stylesheet,
				'version' => (string) $theme->get( 'Version' ),
				'active'  => $active_stylesheet === $stylesheet,
			);
		}
		return $out;
	}
}
