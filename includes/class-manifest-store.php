<?php
/**
 * The option behind the manifest cache.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Reads and writes `afterware_manifests`: slug => the signed envelope exactly
 * as the API returned it, plus when it arrived.
 *
 * Storage only. Nothing here decides whether an entry may be used; that is
 * Manifest_Cache's job, and it re-verifies on every read.
 */
final class Manifest_Store {

	/**
	 * Maximum number of cached manifests; the oldest fetch is evicted first.
	 * Comfortably above the platform's 200-slug cap on one `/updates` call.
	 *
	 * @var int
	 */
	const MAX_ENTRIES = 200;

	/**
	 * A single well-formed entry, or null.
	 *
	 * @param string $slug Plugin slug.
	 * @return array{text:string,signature:string,key_id:string,fetched_at:int}|null
	 */
	public static function get( $slug ) {
		$entries = self::all();
		if ( ! isset( $entries[ (string) $slug ] ) ) {
			return null;
		}
		return $entries[ (string) $slug ];
	}

	/**
	 * The key id a cached manifest claims, without verifying anything. Used to
	 * name the key in a failure report; never to decide trust.
	 *
	 * @param string $slug Plugin slug.
	 * @return string Empty string when nothing is cached for the slug.
	 */
	public static function key_id( $slug ) {
		$entry = self::get( $slug );
		return null === $entry ? '' : $entry['key_id'];
	}

	/**
	 * Merges freshly fetched entries in, evicting the oldest past the cap.
	 * Writes nothing when there is nothing to add.
	 *
	 * @param array<string,array{text:string,signature:string,key_id:string,fetched_at:int}> $fetched New entries.
	 * @return void
	 */
	public static function put( array $fetched ) {
		if ( array() === $fetched ) {
			return;
		}

		$entries = array_merge( self::all(), $fetched );
		if ( count( $entries ) > self::MAX_ENTRIES ) {
			uasort(
				$entries,
				static function ( array $a, array $b ) {
					return $a['fetched_at'] - $b['fetched_at'];
				}
			);
			$entries = array_slice( $entries, -self::MAX_ENTRIES, null, true );
		}

		Options::set( Options::MANIFESTS, $entries );
	}

	/**
	 * Removes a slug's entry.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function forget( $slug ) {
		$entries = self::all();
		if ( ! isset( $entries[ (string) $slug ] ) ) {
			return;
		}
		unset( $entries[ (string) $slug ] );
		Options::set( Options::MANIFESTS, $entries );
	}

	/**
	 * Every well-formed entry.
	 *
	 * @return array<string,array{text:string,signature:string,key_id:string,fetched_at:int}>
	 */
	public static function all() {
		$entries = Options::get( Options::MANIFESTS, array() );
		if ( ! is_array( $entries ) ) {
			return array();
		}

		$out = array();
		foreach ( $entries as $slug => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['text'], $entry['signature'], $entry['key_id'], $entry['fetched_at'] ) ) {
				continue;
			}
			$out[ (string) $slug ] = array(
				'text'       => (string) $entry['text'],
				'signature'  => (string) $entry['signature'],
				'key_id'     => (string) $entry['key_id'],
				'fetched_at' => (int) $entry['fetched_at'],
			);
		}
		return $out;
	}
}
