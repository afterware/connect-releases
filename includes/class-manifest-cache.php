<?php
/**
 * Cache of signed update manifests.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Holds the signed manifest text exactly as the API returned it (Manifest_Store)
 * and decides, on every single read, whether it may be used.
 *
 * The cache holds bytes, not trust: nothing is ever treated as verified because
 * it was verified once before. Every read checks the Ed25519 signature against
 * the trusted key ring, re-parses the text, and re-checks that the manifest is
 * about the slug it was asked for and points at packages this site is allowed
 * to download. A manifest that fails any of those checks is dropped, its offers
 * are evicted, and the failure is reported.
 *
 * Entries are refreshed after 6 hours, in one `/updates` call per request, and
 * a verified entry keeps working for 14 days when the API is unreachable so a
 * network outage never silently un-manages a plugin. Explicit access denials
 * evict these bytes and stop new updates; installed code is never changed.
 */
final class Manifest_Cache {

	use Manifest_Refresh;

	/**
	 * How long an entry is used before a refresh is attempted.
	 *
	 * @var int
	 */
	const TTL = 6 * HOUR_IN_SECONDS;

	/**
	 * How long an entry keeps being served when refreshes keep failing.
	 *
	 * @var int
	 */
	const MAX_AGE = 14 * DAY_IN_SECONDS;

	/**
	 * The verified manifest for a slug, or null when there is none to trust.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public static function get( $slug ) {
		$slug  = (string) $slug;
		$entry = Manifest_Store::get( $slug );

		if ( null !== Update_Access::error() || null === $entry || ( time() - $entry['fetched_at'] ) > self::TTL ) {
			self::refresh( $slug );
			$entry = Manifest_Store::get( $slug );
		}

		return null === Update_Access::error() ? self::from_entry( $slug, $entry, true ) : null;
	}

	/**
	 * The verified manifest already in the cache, or null. Never fetches,
	 * never writes, never reports.
	 *
	 * This is the read a screen makes. `get()` may spend fifteen seconds on a
	 * timeout, delete an entry, raise a persistent notice and open a support
	 * ticket, none of which belongs in the middle of drawing a page: a site
	 * owner opening the overview has not asked Afterware for anything. Every
	 * refusal peek() makes is therefore silent, and the next real read —
	 * an update check, a scan, a switch — does the reporting properly.
	 *
	 * The verification itself is not skipped. This returns bytes that pass the
	 * same signature, slug and package-URL checks as `get()`, or nothing.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public static function peek( $slug ) {
		$slug = (string) $slug;
		return null === Update_Access::error() ? self::from_entry( $slug, Manifest_Store::get( $slug ), false ) : null;
	}

	/**
	 * Decides what a cache entry is worth, and how loudly to say so.
	 *
	 * @param string                                                                $slug   Plugin slug.
	 * @param array{text:string,signature:string,key_id:string,fetched_at:int}|null $entry  The stored entry, or null.
	 * @param bool                                                                  $report Whether a refusal may write and report.
	 * @return array<string,mixed>|null
	 */
	private static function from_entry( $slug, $entry, $report ) {
		if ( null === $entry ) {
			return null;
		}

		if ( ( time() - $entry['fetched_at'] ) > self::MAX_AGE ) {
			if ( $report ) {
				Manifest_Store::forget( $slug );
			}
			return null;
		}

		$ring = Key_Ring::trusted_at( time() );
		if ( array() === $ring ) {
			if ( $report ) {
				Build_Warning::key_ring_empty();
			}
			return null;
		}

		return self::verified( $slug, $entry, $ring, $report );
	}

	/**
	 * The key id a cached manifest claims. For failure reports only.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function key_id_for( $slug ) {
		return Manifest_Store::key_id( $slug );
	}

	/**
	 * Verifies a cache entry and returns the parsed manifest, or null after
	 * reporting why it was refused.
	 *
	 * @param string                                                           $slug   Slug the entry was cached under.
	 * @param array{text:string,signature:string,key_id:string,fetched_at:int} $entry  Cache entry.
	 * @param array<string,string>                                             $ring   Trusted key ring.
	 * @param bool                                                             $report Whether a refusal may write and report.
	 * @return array<string,mixed>|null
	 */
	private static function verified( $slug, array $entry, array $ring, $report ) {
		$signature_ok = Verifier::verify_manifest( $entry['text'], $entry['signature'], $entry['key_id'], $ring );
		if ( is_wp_error( $signature_ok ) ) {
			if ( 'afterware_unknown_key' === $signature_ok->get_error_code() ) {
				// Two very different situations reach this branch, and they call
				// for opposite advice. A key the ring has withdrawn or timed out
				// is a rotation in progress and nothing to act on; a key this
				// site has never heard of means the build predates it. Neither
				// is about the plugin being updated: see Build_Warning.
				if ( $report ) {
					if ( Key_Ring::withdrawn( $entry['key_id'], time() ) ) {
						Build_Warning::key_withdrawn();
					} else {
						Build_Warning::key_ring_outdated();
					}
				}
				return null;
			}
			return self::refuse( $slug, $entry, $signature_ok, $report );
		}

		$manifest = Verifier::parse_manifest( $entry['text'] );
		if ( is_wp_error( $manifest ) ) {
			return self::refuse( $slug, $entry, $manifest, $report );
		}

		if ( $manifest['slug'] !== $slug ) {
			return self::refuse(
				$slug,
				$entry,
				new WP_Error(
					'afterware_manifest_slug_mismatch',
					__( 'The manifest received for this plugin is about a different plugin.', 'afterware' )
				),
				$report
			);
		}

		foreach ( $manifest['releases'] as $release ) {
			if ( ! self::is_allowed_package_url( $release['package_url'] ) ) {
				return self::refuse(
					$slug,
					$entry,
					new WP_Error(
						'afterware_insecure_package_url',
						__( 'The manifest points at an update package Afterware does not serve. Package URLs must be served from Afterware hosts over HTTPS.', 'afterware' )
					),
					$report
				);
			}
		}

		return $manifest;
	}

	/**
	 * Whether a package URL may be downloaded from.
	 *
	 * `https://`, and an Afterware host. The platform's manifest schema already
	 * rejects anything else on the way out (`docs/references/update-manifest.md`:
	 * these URLs are "always https:// URLs served from Afterware hosts"), so
	 * this asserts the same invariant on the way in. It is defence in depth
	 * rather than the main lock — the manifest carrying the URL is signature-
	 * verified first — but it is the one check that still stands if a signing
	 * key is ever misused, and it costs a string comparison.
	 *
	 * Anything goes on a local site, where the wp-env stub serves the fixture
	 * package over http:// from a container hostname.
	 *
	 * The unqualified call resolves to the test-only shim in
	 * `tests/phpunit/includes/environment-shim.php` when it is loaded, and to
	 * the global function otherwise; see Key_Ring::environment_type().
	 *
	 * @param string $url Package URL from a manifest release.
	 * @return bool
	 */
	private static function is_allowed_package_url( $url ) {
		if ( 'local' === wp_get_environment_type() ) {
			return true;
		}
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return false;
		}
		if ( 'https' !== strtolower( $parts['scheme'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['host'] ), array( AFTERWARE_UPDATES_HOST, AFTERWARE_PACKAGES_HOST ), true );
	}

	/**
	 * Drops a manifest and everything offered from it, and reports why.
	 *
	 * With $report false the refusal is silent and nothing is written: peek()
	 * is called while a page is being drawn, and a render must neither open a
	 * support ticket nor throw away the only copy of something. The entry
	 * stays exactly where it is for the next real read to deal with.
	 *
	 * @param string                                                           $slug   Plugin slug.
	 * @param array{text:string,signature:string,key_id:string,fetched_at:int} $entry  The entry being dropped; read for its key id before it goes.
	 * @param WP_Error                                                         $error  Why the manifest was refused.
	 * @param bool                                                             $report Whether to drop the entry and report the failure.
	 * @return null Always null, so callers can `return self::refuse( … )`.
	 */
	private static function refuse( $slug, array $entry, WP_Error $error, $report ) {
		if ( ! $report ) {
			return null;
		}

		Manifest_Store::forget( $slug );
		Offers::forget_slug( $slug );
		Verification_Failure::report(
			$slug,
			$error->get_error_code(),
			$error->get_error_message(),
			array(
				'stage'  => 'manifest',
				'key_id' => $entry['key_id'],
			)
		);
		return null;
	}
}
