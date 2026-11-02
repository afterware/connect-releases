<?php
/**
 * Declared runtime minimums from a verified manifest release.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/** Pure requirement checks; neither signatures nor whole-site compatibility are inferred. */
final class Runtime_Requirements {

	/**
	 * Checks a release against the runtime executing this request.
	 *
	 * @param array<string,mixed> $release Verified release.
	 * @return WP_Error|null
	 */
	public static function for_release( array $release ) {
		global $wp_version;
		return self::evaluate( $release, (string) $wp_version, PHP_VERSION );
	}

	/**
	 * A render-safe check of the latest release in an already verified cache read.
	 * No manifest means no runtime evidence; the switch guard separately refuses no build.
	 *
	 * @param array<string,mixed>|null $manifest Verified manifest, or no cache.
	 * @return WP_Error|null
	 */
	public static function for_latest( $manifest ) {
		if ( ! is_array( $manifest ) || empty( $manifest['releases'] ) ) {
			return null;
		}
		return self::for_release( $manifest['releases'][0] );
	}

	/**
	 * Checks explicit minimums without guessing prerelease or vendor version semantics.
	 *
	 * @param array<string,mixed> $release Release requirements.
	 * @param string              $wp      WordPress runtime.
	 * @param string              $php     PHP runtime.
	 * @return WP_Error|null
	 */
	public static function evaluate( array $release, $wp, $php ) {
		$unknown = false;
		foreach ( array( array( 'WordPress', $wp, 'requires_wp' ), array( 'PHP', $php, 'requires_php' ) ) as $runtime ) {
			$minimum = isset( $release[ $runtime[2] ] ) ? $release[ $runtime[2] ] : null;
			$actual  = self::numeric( $runtime[1] );
			$needed  = self::numeric( $minimum );
			if ( null === $actual || null === $needed ) {
				$unknown = true;
				continue;
			}
			if ( self::compare( $actual, $needed ) < 0 ) {
				return new WP_Error(
					'afterware_runtime_too_old',
					sprintf(
						/* translators: 1: runtime name, 2: required version, 3: installed version. */
						__( 'This build requires %1$s %2$s or newer; this site runs %3$s. Review the requirements before changing your site.', 'afterware' ),
						$runtime[0],
						$minimum,
						$runtime[1]
					)
				);
			}
		}
		return $unknown ? new WP_Error(
			'afterware_runtime_unknown',
			__( 'The build requirements or runtime versions could not be compared. Ask support to review them before installing this build.', 'afterware' )
		) : null;
	}

	/**
	 * Bounded decimal components without integer overflow.
	 *
	 * @param mixed $value A declared minimum or runtime string.
	 * @return array<int,string>|null
	 */
	private static function numeric( $value ) {
		if ( ! is_string( $value ) || strlen( $value ) > 64 || ! preg_match( '/^\d+(\.\d+)*$/D', $value ) ) {
			return null;
		}
		return array_map(
			static function ( $part ) {
				$digits = ltrim( $part, '0' );
				return '' === $digits ? '0' : $digits;
			},
			explode( '.', $value )
		);
	}

	/**
	 * Compares normalized numeric strings, padding missing components with zero.
	 *
	 * @param array<int,string> $a Actual runtime.
	 * @param array<int,string> $b Required minimum.
	 * @return int
	 */
	private static function compare( array $a, array $b ) {
		$length = max( count( $a ), count( $b ) );
		for ( $i = 0; $i < $length; $i++ ) {
			$x = isset( $a[ $i ] ) ? $a[ $i ] : '0';
			$y = isset( $b[ $i ] ) ? $b[ $i ] : '0';
			if ( strlen( $x ) !== strlen( $y ) ) {
				return strlen( $x ) < strlen( $y ) ? -1 : 1;
			}
			if ( $x !== $y ) {
				return strcmp( $x, $y ) < 0 ? -1 : 1;
			}
		}
		return 0;
	}
}
