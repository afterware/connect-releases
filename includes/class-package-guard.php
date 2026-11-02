<?php
/**
 * The check every update package passes before WordPress may install it.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Decides whether a ZIP WordPress is about to install may be installed.
 *
 * Two questions, in order, and both of them fail closed:
 *
 * 1. Does the manifest *as it stands now* list this exact package? The signed
 *    manifest is the authority: an offer record only links a URL back to the
 *    plugin it was offered for, and a plugin of ours whose package the manifest
 *    does not list is refused — that is exactly what an injected update looks
 *    like. A third-party plugin is none of our business.
 * 2. Do the bytes hash to what that manifest says? Including bytes another
 *    handler downloaded on our behalf.
 */
final class Package_Guard {

	/**
	 * Verifies a package WordPress is about to install.
	 *
	 * @param string|false|WP_Error $reply      A download already produced by another handler, or false.
	 * @param string                $package    Package URL WordPress is about to download.
	 * @param array<string,mixed>   $hook_extra Upgrader context; `plugin` names the plugin being updated.
	 * @return string|false|WP_Error Path to a verified file, the untouched reply, or an error.
	 */
	public static function check( $reply, $package, array $hook_extra ) {
		if ( is_wp_error( $reply ) ) {
			return $reply;
		}

		$package     = (string) $package;
		$plugin_file = ( isset( $hook_extra['plugin'] ) && is_string( $hook_extra['plugin'] ) ) ? $hook_extra['plugin'] : '';
		$slug        = ( '' === $plugin_file ) ? null : Inventory::slug_for( $plugin_file );
		$offer       = Offers::find( $package, $slug );

		if ( null === $slug ) {
			// No plugin context at all: only a record of having offered this exact
			// URL tells us the download is ours to judge.
			if ( null === $offer ) {
				return $reply;
			}
			$slug = (string) $offer['slug'];
		} elseif ( null === $offer && ! self::is_ours( $plugin_file ) ) {
			return $reply;
		}

		$release = self::authorised_release( $slug, $package, null !== $offer );
		if ( is_wp_error( $release ) ) {
			return $release;
		}

		return self::fetch_and_check( $slug, $release, $reply );
	}

	/**
	 * Downloads a named version of a plugin and verifies it against the current
	 * signed manifest, returning the local path only when it checks out.
	 *
	 * The `upgrader_pre_download` filter cannot do this job for a fresh install:
	 * `Plugin_Upgrader::install()` passes no `plugin` in its hook context, so
	 * there is nothing to tie the package to a plugin. The switcher therefore
	 * verifies the package itself and hands the upgrader a local file.
	 *
	 * Having no manifest, or no such release in it, is a plain refusal here —
	 * not a verification failure. Nobody offered this site anything: somebody
	 * asked for a version Afterware cannot currently vouch for, and the answer
	 * is no. Raising the alarm for that would train site owners to ignore it.
	 * Bytes that do not match a manifest that *does* list them still do.
	 *
	 * @param string $slug    Plugin slug.
	 * @param string $version Version to fetch, as named in the manifest.
	 * @return string|WP_Error Path to a verified file, or an error.
	 */
	public static function verified_download( $slug, $version ) {
		$slug     = (string) $slug;
		$version  = (string) $version;
		$manifest = Manifest_Cache::get( $slug );

		if ( is_array( $manifest ) ) {
			foreach ( $manifest['releases'] as $release ) {
				if ( (string) $release['version'] === $version ) {
					return self::fetch_and_check( $slug, $release, false );
				}
			}

			return new WP_Error(
				'afterware_unknown_release',
				__( 'The current signed manifest does not list the version Afterware was asked to install.', 'afterware' )
			);
		}

		$access = Update_Access::error();
		if ( null !== $access ) {
			return $access;
		}
		return new WP_Error(
			'afterware_no_manifest',
			__( 'There is no verified Afterware manifest for this plugin right now, so nothing can be installed.', 'afterware' )
		);
	}

	/**
	 * Whether a plugin file belongs to a plugin served from our updates host.
	 *
	 * @param string $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return bool
	 */
	private static function is_ours( $plugin_file ) {
		$ours = Inventory::our_plugins();
		return isset( $ours[ $plugin_file ] );
	}

	/**
	 * The release the current verified manifest offers at this URL.
	 *
	 * A manifest we cannot read at all is not evidence that the package is
	 * forged — the API may simply be unreachable — so the download is refused
	 * but any offer record is kept for the next attempt. A manifest we *can*
	 * read that does not list the package is evidence, and spends the record.
	 *
	 * @param string $slug      Plugin slug.
	 * @param string $package   Package URL.
	 * @param bool   $was_offer Whether a record of offering this URL exists.
	 * @return array<string,mixed>|WP_Error
	 */
	private static function authorised_release( $slug, $package, $was_offer ) {
		$manifest = Manifest_Cache::get( $slug );

		if ( ! is_array( $manifest ) ) {
			$access = Update_Access::error();
			if ( null !== $access ) {
				return $access;
			}
			return Verification_Failure::to_wp_error(
				$slug,
				$was_offer ? 'afterware_no_manifest' : 'afterware_unknown_package',
				$was_offer
					? __( 'There is no verified Afterware manifest for this plugin right now, so this update package cannot be authorised.', 'afterware' )
					: __( 'WordPress tried to install an update package that Afterware never offered for this plugin.', 'afterware' ),
				self::context( $slug, array( 'package_url' => $package ) )
			);
		}

		foreach ( $manifest['releases'] as $release ) {
			if ( (string) $release['package_url'] === $package ) {
				return $release;
			}
		}

		if ( $was_offer ) {
			Offers::forget_slug( $slug );
		}
		return Verification_Failure::to_wp_error(
			$slug,
			$was_offer ? 'afterware_package_withdrawn' : 'afterware_unknown_package',
			$was_offer
				? __( 'The current signed manifest no longer offers this update package.', 'afterware' )
				: __( 'WordPress tried to install an update package that Afterware never offered for this plugin.', 'afterware' ),
			self::context( $slug, array( 'package_url' => $package ) )
		);
	}

	/**
	 * Produces the file for a release — downloading it, or taking the one
	 * another handler already produced — and hash-checks it either way.
	 *
	 * @param string                $slug    Plugin slug.
	 * @param array<string,mixed>   $release The authorised manifest release.
	 * @param string|false|WP_Error $reply   A file another handler produced, or false.
	 * @return string|WP_Error
	 */
	private static function fetch_and_check( $slug, array $release, $reply ) {
		$requirements = Runtime_Requirements::for_release( $release );
		if ( null !== $requirements ) {
			return $requirements;
		}

		$file = ( is_string( $reply ) && '' !== $reply ) ? $reply : self::download( (string) $release['package_url'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}

		return self::hash_check( $slug, (string) $file, (string) $release['sha256'], (string) $release['version'] );
	}

	/**
	 * Compares a file against the hash the manifest names.
	 *
	 * A file that cannot be hashed is a failure, never a silent pass.
	 *
	 * @param string $slug     Plugin slug.
	 * @param string $file     Path to the downloaded file.
	 * @param string $expected SHA-256 from the verified manifest.
	 * @param string $version  Version being installed, for the log.
	 * @return string|WP_Error
	 */
	private static function hash_check( $slug, $file, $expected, $version ) {
		Verified_Install::forget_file( $file );
		$hash = Verifier::sha256_file( $file );
		if ( false === $hash ) {
			wp_delete_file( $file );
			return Verification_Failure::to_wp_error(
				$slug,
				'afterware_hash_unreadable',
				__( 'The downloaded update file could not be read to check its hash.', 'afterware' ),
				self::context( $slug, array( 'version' => $version ) )
			);
		}

		if ( ! hash_equals( $expected, $hash ) ) {
			wp_delete_file( $file );
			return Verification_Failure::to_wp_error(
				$slug,
				'afterware_hash_mismatch',
				__( 'The downloaded update file does not match the hash in the signed manifest.', 'afterware' ),
				self::context(
					$slug,
					array(
						'version'  => $version,
						'expected' => $expected,
						'actual'   => $hash,
					)
				)
			);
		}

		Verified_Install::remember( $file, $slug, $version, $hash );
		return $file;
	}

	/**
	 * Downloads a package.
	 *
	 * @param string $package Package URL.
	 * @return string|WP_Error
	 */
	private static function download( $package ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		return download_url( $package, 300 );
	}

	/**
	 * The failure context, always naming the stage and the key that signed the
	 * manifest this package came from.
	 *
	 * @param string              $slug  Plugin slug.
	 * @param array<string,mixed> $extra Failure-specific data.
	 * @return array<string,mixed>
	 */
	private static function context( $slug, array $extra ) {
		return array_merge(
			array(
				'stage'  => 'package',
				'key_id' => Manifest_Cache::key_id_for( $slug ),
			),
			$extra
		);
	}
}
