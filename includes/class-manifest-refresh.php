<?php
/**
 * Fetching signed manifests from the API.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * The half of the manifest cache that talks to the network.
 *
 * Kept apart from Manifest_Cache's own job — deciding, on every read, whether
 * stored bytes may be trusted — because the two are answerable to different
 * things. Nothing in here decides what to believe; nothing in Manifest_Cache
 * outside `get()` may reach the API at all.
 *
 * A trait rather than a class: the refresh is Manifest_Cache's own behaviour
 * and shares its per-request guard, but neither file has to be read to
 * understand the other.
 */
trait Manifest_Refresh {

	/**
	 * Whether this request already reached the API. One `/updates` call per
	 * request, however many plugins ask for a manifest.
	 *
	 * @var bool
	 */
	private static $refresh_attempted = false;

	/**
	 * Clears the per-request refresh guard.
	 *
	 * A seam for tests and for long-lived processes (WP-CLI, a scan) that want
	 * a second pass at the API without a new PHP request. Build_Warning keeps
	 * its own guard, deliberately untouched here.
	 *
	 * @return void
	 */
	public static function reset_request_guard() {
		self::$refresh_attempted = false;
	}

	/**
	 * Fetches manifests for every plugin of ours, once per request.
	 *
	 * @param string $slug The slug that triggered the refresh; included even
	 *                     when it is not (yet) one of our installed plugins.
	 * @return void
	 */
	private static function refresh( $slug ) {
		if ( self::$refresh_attempted || ! Connection::is_connected() ) {
			return;
		}
		self::$refresh_attempted = true;

		$slugs = array_values( array_unique( array_merge( array_values( Inventory::our_plugins() ), array( (string) $slug ) ) ) );

		$client   = new Api_Client( AFTERWARE_API_BASE, Options::get( Options::SITE_KEY ) );
		$response = $client->updates( $slugs );
		if ( is_wp_error( $response ) ) {
			if ( Update_Access::denied( $response ) ) {
				return;
			}
			Activity::log(
				'manifest_refresh_failed',
				null,
				array( 'code' => $response->get_error_code() ),
				$response->get_error_message()
			);
			return;
		}

		if ( isset( $response['schema_version'], $response['manifests'], $response['missing'] )
			&& 1 === $response['schema_version'] && is_array( $response['manifests'] ) && is_array( $response['missing'] ) ) {
			Update_Access::recovered();
		}
		Manifest_Store::put( self::entries_from( $response, $slugs ) );
	}

	/**
	 * The cache entries an `/updates` response yields, restricted to the slugs
	 * that were actually asked for: the cache never holds a manifest this site
	 * did not request.
	 *
	 * @param array<string,mixed> $response Decoded `/updates` response.
	 * @param string[]            $slugs    Slugs that were requested.
	 * @return array<string,array{text:string,signature:string,key_id:string,fetched_at:int}>
	 */
	private static function entries_from( array $response, array $slugs ) {
		$manifests = ( isset( $response['manifests'] ) && is_array( $response['manifests'] ) ) ? $response['manifests'] : array();
		$requested = array_flip( $slugs );
		$now       = time();

		$entries = array();
		foreach ( $manifests as $slug => $signed ) {
			if ( ! isset( $requested[ $slug ] ) || ! is_array( $signed ) ) {
				continue;
			}
			if ( ! isset( $signed['manifest'], $signed['signature'], $signed['key_id'] ) ) {
				continue;
			}
			$entries[ (string) $slug ] = array(
				'text'       => (string) $signed['manifest'],
				'signature'  => (string) $signed['signature'],
				'key_id'     => (string) $signed['key_id'],
				'fetched_at' => $now,
			);
		}
		return $entries;
	}
}
