<?php
/**
 * Current disk versions and refusal of an older switch target.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/** Reads only local headers; never trusts a stale inventory as the installed version. */
final class Switch_Version {

	/**
	 * Read the actual file, even when get_plugins() has an older header cached.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return string
	 */
	public static function installed( $plugin_file ) {
		$path = WP_PLUGIN_DIR . '/' . $plugin_file;
		if ( ! is_file( $path ) ) {
			return '';
		}
		$headers = get_file_data( $path, array( 'Version' => 'Version' ), 'plugin' );
		return (string) $headers['Version'];
	}

	/**
	 * An unknown target gives no version decision; other guards still apply.
	 *
	 * @param string      $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @param string|null $latest      Offered version, verified before any installation.
	 * @return WP_Error|null
	 */
	public static function error( $plugin_file, $latest ) {
		if ( null === $latest || ! preg_match( '/^\d+(?:\.\d+)*$/', $latest ) ) {
			return null;
		}
		$installed = self::installed( $plugin_file );
		if ( '' === $installed || ! version_compare( $latest, $installed, '<' ) ) {
			return null;
		}
		return new WP_Error(
			'afterware_older_build',
			sprintf(
				/* translators: 1: offered Afterware version, 2: installed plugin version. */
				__( 'The Afterware build %1$s is older than your installed version %2$s. Keep your current version until a suitable maintained build is available.', 'afterware' ),
				$latest,
				$installed
			)
		);
	}
}
