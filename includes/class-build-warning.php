<?php
/**
 * What happens when this build cannot verify anything.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * The counterpart to Verification_Failure, for the case where nothing is wrong
 * with the manifest and everything is wrong with us.
 *
 * A build with no pinned keys, or one released before Afterware rotated its
 * signing key, cannot check a signature it has no key for. The manifest may be
 * perfectly good. So this is a warning about Afterware Connect — update it —
 * rather than an alarm about the plugin: no verification failure, no ticket,
 * and nothing is dropped from the cache, so an updated build verifies the very
 * same cached bytes.
 *
 * Each warning is raised once per request, however many plugins run into it.
 */
final class Build_Warning {

	/**
	 * Warnings already raised this request, keyed by notice key.
	 *
	 * @var array<string,bool>
	 */
	private static $raised = array();

	/**
	 * This build has no pinned signing keys at all.
	 *
	 * @return void
	 */
	public static function key_ring_empty() {
		self::raise(
			'key-ring-empty',
			'key_ring_empty',
			__( 'This build of Afterware Connect has no signing keys pinned, so updates cannot be verified. Update Afterware Connect.', 'afterware' )
		);
	}

	/**
	 * This build predates the key the manifest was signed with.
	 *
	 * @return void
	 */
	public static function key_ring_outdated() {
		self::raise(
			'key-ring-outdated',
			'key_ring_outdated',
			__( 'Afterware Connect is too old to verify current updates. Update Afterware Connect.', 'afterware' )
		);
	}

	/**
	 * Afterware withdrew the key a cached manifest was signed with.
	 *
	 * Not a problem with this build, and not one a site owner can act on: the
	 * ring rotated, the cached manifest signed by the old key is refused from
	 * that moment, and the next signed manifest resumes updates by itself.
	 * Cached manifests live for up to 14 days, so this is what a normal
	 * rotation looks like from a site that has not refetched yet.
	 *
	 * @return void
	 */
	public static function key_withdrawn() {
		self::raise(
			'key-withdrawn',
			'key_withdrawn',
			__( 'Afterware withdrew the signing key this update was signed with, so it was not installed. Updates resume with the next signed update from Afterware; there is nothing to do on this site.', 'afterware' )
		);
	}

	/**
	 * Clears the once-per-request guards. A seam for tests, which run many
	 * "requests" in one PHP process; deliberately not called by
	 * Manifest_Cache::reset_request_guard(), so a WP-CLI run over fifty plugins
	 * still warns once rather than fifty times.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$raised = array();
	}

	/**
	 * Logs and shows a warning, once per request.
	 *
	 * @param string $notice_key Stable notice key.
	 * @param string $kind       Activity kind.
	 * @param string $message    Plain-language message for a site owner.
	 * @return void
	 */
	private static function raise( $notice_key, $kind, $message ) {
		if ( isset( self::$raised[ $notice_key ] ) ) {
			return;
		}
		self::$raised[ $notice_key ] = true;

		Activity::log( $kind, null, array(), $message );
		Notices::add( $notice_key, 'warning', $message, true );
	}
}
