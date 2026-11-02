<?php
/**
 * Record of the updates this plugin has offered WordPress.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Remembers, per plugin and package URL, which version an update offer was for
 * and the SHA-256 the signed manifest gave for it.
 *
 * This exists because the update check and the download happen in different
 * requests: WordPress asks for updates on one page load and downloads the
 * package on another, passing nothing along but the package URL and the plugin
 * file. The hash a download is checked against therefore has to be written down
 * at offer time, while it is still coming out of a verified manifest.
 *
 * A record is not an authorisation on its own — Package_Guard re-reads the
 * current manifest before any download — it is the link from a URL back to the
 * plugin it was offered for. Entries are keyed by slug *and* URL so two plugins
 * that somehow share a package URL cannot overwrite each other.
 */
final class Offers {

	/**
	 * How long a recorded offer stays usable.
	 *
	 * @var int
	 */
	const MAX_AGE = 7 * DAY_IN_SECONDS;

	/**
	 * Maximum number of offers kept; the oldest are evicted first.
	 *
	 * @var int
	 */
	const MAX_OFFERS = 50;

	/**
	 * Records an offer, replacing any previous offer for the same plugin and
	 * URL, and drops entries that are too old or beyond the cap.
	 *
	 * @param string              $slug      Plugin slug.
	 * @param array<string,mixed> $release   The manifest release being offered.
	 * @param string              $installed Version installed at the time of the offer.
	 * @return void
	 */
	public static function remember( $slug, array $release, $installed ) {
		$offers = self::all();
		$url    = (string) $release['package_url'];

		$offers[ self::key( (string) $slug, $url ) ] = array(
			'slug'        => (string) $slug,
			'package_url' => $url,
			'version'     => (string) $release['version'],
			'sha256'      => (string) $release['sha256'],
			'from'        => (string) $installed,
			'at'          => time(),
		);

		Options::set( Options::OFFERED, self::pruned( $offers ) );
	}

	/**
	 * The offer for a package URL, or null when we never offered it.
	 *
	 * When WordPress says which plugin it is updating, only that plugin's own
	 * record counts: there is no fall back to a URL-only match, because another
	 * plugin's record of the same URL must never authorise this download.
	 *
	 * @param string      $package_url Package URL WordPress is about to download.
	 * @param string|null $slug        Slug WordPress says it is updating, when known.
	 * @return array<string,mixed>|null
	 */
	public static function find( $package_url, $slug = null ) {
		$offers = self::all();
		$url    = (string) $package_url;

		if ( null !== $slug ) {
			$key = self::key( (string) $slug, $url );
			return isset( $offers[ $key ] ) ? $offers[ $key ] : null;
		}

		return self::newest(
			$offers,
			static function ( array $offer ) use ( $url ) {
				return $offer['package_url'] === $url;
			}
		);
	}

	/**
	 * The most recent offer for a slug, or null when there is none.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public static function for_slug( $slug ) {
		$slug = (string) $slug;
		return self::newest(
			self::all(),
			static function ( array $offer ) use ( $slug ) {
				return $offer['slug'] === $slug;
			}
		);
	}

	/**
	 * Drops every offer for a slug. Called when an update lands and when a
	 * manifest for that slug stops being trustworthy.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function forget_slug( $slug ) {
		$offers = self::all();
		$slug   = (string) $slug;

		$keep = array();
		foreach ( $offers as $key => $offer ) {
			if ( $offer['slug'] !== $slug ) {
				$keep[ $key ] = $offer;
			}
		}
		if ( count( $keep ) !== count( $offers ) ) {
			Options::set( Options::OFFERED, $keep );
		}
	}

	/**
	 * Every well-formed offer, keyed by `slug|package_url`.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$offers = Options::get( Options::OFFERED, array() );
		if ( ! is_array( $offers ) ) {
			return array();
		}

		$out = array();
		foreach ( $offers as $key => $offer ) {
			if ( ! is_array( $offer ) || ! isset( $offer['slug'], $offer['package_url'], $offer['version'], $offer['sha256'], $offer['from'], $offer['at'] ) ) {
				continue;
			}
			$out[ (string) $key ] = array(
				'slug'        => (string) $offer['slug'],
				'package_url' => (string) $offer['package_url'],
				'version'     => (string) $offer['version'],
				'sha256'      => (string) $offer['sha256'],
				'from'        => (string) $offer['from'],
				'at'          => (int) $offer['at'],
			);
		}
		return $out;
	}

	/**
	 * The newest offer matching a test, or null.
	 *
	 * @param array<string,array<string,mixed>> $offers Offers to search.
	 * @param callable                          $is_wanted Test applied to each offer.
	 * @return array<string,mixed>|null
	 */
	private static function newest( array $offers, callable $is_wanted ) {
		$found = null;
		foreach ( $offers as $offer ) {
			if ( ! $is_wanted( $offer ) ) {
				continue;
			}
			if ( null === $found || $offer['at'] >= $found['at'] ) {
				$found = $offer;
			}
		}
		return $found;
	}

	/**
	 * Drops expired offers and, past the cap, the oldest ones.
	 *
	 * @param array<string,array<string,mixed>> $offers Offers to prune.
	 * @return array<string,array<string,mixed>>
	 */
	private static function pruned( array $offers ) {
		$cutoff = time() - self::MAX_AGE;
		foreach ( array_keys( $offers ) as $key ) {
			if ( $offers[ $key ]['at'] < $cutoff ) {
				unset( $offers[ $key ] );
			}
		}

		if ( count( $offers ) > self::MAX_OFFERS ) {
			uasort(
				$offers,
				static function ( array $a, array $b ) {
					return $a['at'] - $b['at'];
				}
			);
			$offers = array_slice( $offers, -self::MAX_OFFERS, null, true );
		}

		return $offers;
	}

	/**
	 * The option key for a plugin's offer of a package URL.
	 *
	 * @param string $slug Plugin slug.
	 * @param string $url  Package URL.
	 * @return string
	 */
	private static function key( $slug, $url ) {
		return $slug . '|' . $url;
	}
}
