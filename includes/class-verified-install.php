<?php
/**
 * Links a verified package to its own successful installation.
 *
 * @package Afterware
 */

namespace Afterware;

/** Request-local evidence only; never reads an option as verification authority. */
final class Verified_Install {

	/** @var array<string,array<string,string>> Verified temporary files. */
	private static $files = array();

	/** @var array<string,array<string,string>> Bindings for individual plugin attempts. */
	private static $pending = array();

	/** Clear request-local evidence, including between isolated tests. */
	public static function reset(): void {
		self::$files   = array();
		self::$pending = array();
	}

	/**
	 * Forget a file before checking it again.
	 *
	 * @param string $file Temporary path.
	 */
	public static function forget_file( $file ): void {
		unset( self::$files[ $file ] );
	}

	/**
	 * Called only after the package bytes match the verified manifest.
	 *
	 * @param string $file    Temporary path.
	 * @param string $slug    Verified slug.
	 * @param string $version Verified version.
	 * @param string $sha256  Verified package hash.
	 */
	public static function remember( $file, $slug, $version, $sha256 ): void {
		self::$files[ $file ] = array(
			'slug'    => $slug,
			'version' => $version,
			'sha256'  => $sha256,
		);
		if ( count( self::$files ) > 16 ) {
			array_shift( self::$files );
		}
	}

	/**
	 * Consume proof for this exact downloaded file.
	 *
	 * @param string $file Temporary path.
	 * @return array<string,string>|null
	 */
	public static function take_file( $file ) {
		$proof = isset( self::$files[ $file ] ) ? self::$files[ $file ] : null;
		unset( self::$files[ $file ] );
		return $proof;
	}

	/**
	 * A new attempt invalidates an earlier incomplete binding for that plugin.
	 *
	 * @param array<string,mixed> $context Upgrader context.
	 */
	public static function begin( array $context ): void {
		if ( isset( $context['plugin'] ) && is_string( $context['plugin'] ) ) {
			unset( self::$pending[ $context['plugin'] ] );
		}
	}

	/**
	 * Bind a just-verified file to the plugin the upgrader is replacing.
	 *
	 * @param mixed               $file    Guard result.
	 * @param array<string,mixed> $context Upgrader context.
	 */
	public static function bind( $file, array $context ): void {
		if ( ! is_string( $file ) || ! isset( $context['plugin'] ) || ! is_string( $context['plugin'] ) ) {
			return;
		}
		$proof = self::take_file( $file );
		if ( null !== $proof && Inventory::slug_for( $context['plugin'] ) === $proof['slug'] ) {
			self::$pending[ $context['plugin'] ] = $proof;
			if ( count( self::$pending ) > 64 ) {
				array_shift( self::$pending );
			}
		}
	}

	/**
	 * Each member of a bulk operation gets its own post-install result.
	 *
	 * @param mixed $response Earlier filter result, returned unchanged.
	 * @param mixed $context  Upgrader context.
	 * @param mixed $result   Destination details.
	 * @return mixed
	 */
	public static function after_install( $response, $context, $result ) {
		if ( ! is_array( $context ) || ! isset( $context['plugin'] ) || ! is_string( $context['plugin'] ) ) {
			return $response;
		}
		$file  = $context['plugin'];
		$proof = isset( self::$pending[ $file ] ) ? self::$pending[ $file ] : null;
		unset( self::$pending[ $file ] );
		if ( true === $response && null !== $proof && is_array( $result ) && isset( $result['destination_name'] ) && $result['destination_name'] === $proof['slug'] ) {
			self::record_switch( $file, $proof );
		}
		return $response;
	}

	/**
	 * A switch carries its own verified proof through Switch_Install's success.
	 *
	 * @param string                    $file  Installed plugin file.
	 * @param array<string,string>|null $proof Consumed file verification.
	 */
	public static function record_switch( $file, $proof ): void {
		if ( null !== $proof && Inventory::slug_for( $file ) === $proof['slug'] && self::installed_matches( $file, $proof['version'] ) ) {
			First_Install_Receipt::queue( $file, $proof );
		}
	}

	/**
	 * A queued report is discarded if the installed header has since changed.
	 *
	 * @param string $file    Relative plugin path.
	 * @param string $version Expected version.
	 * @return bool
	 */
	public static function installed_matches( $file, $version ) {
		if ( 0 !== validate_file( $file ) || ! is_file( WP_PLUGIN_DIR . '/' . $file ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		$uri  = wp_parse_url( $data['UpdateURI'] );
		return (string) $data['Version'] === $version && is_array( $uri )
			&& isset( $uri['scheme'], $uri['host'] ) && 'https' === strtolower( $uri['scheme'] )
			&& AFTERWARE_UPDATES_HOST === strtolower( $uri['host'] );
	}
}
