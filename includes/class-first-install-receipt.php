<?php
/**
 * Bounded background reporting of a site's first verified package installation.
 *
 * @package Afterware
 */

namespace Afterware;

/** One pending record, six attempts and a seven-day maximum age; no page-render HTTP. */
final class First_Install_Receipt {

	const HOOK = 'afterware_first_install_receipt';

	/**
	 * Store only the first pending proof for this registration.
	 *
	 * @param string               $file  Installed plugin file.
	 * @param array<string,string> $proof Verified package metadata.
	 */
	public static function queue( $file, array $proof ): void {
		$site = Options::get( Options::SITE_ID );
		if ( ! is_string( $site ) || ! wp_is_uuid( $site ) || ! Connection::is_connected() ) {
			return;
		}
		$existing = Options::get( Options::FIRST_INSTALL );
		if ( is_array( $existing ) && isset( $existing['site_id'] ) && $existing['site_id'] === $site ) {
			return;
		}
		$payload = array(
			'schema'      => 1,
			'plugin_slug' => $proof['slug'],
			'version'     => $proof['version'],
			'sha256'      => $proof['sha256'],
		);
		if ( ! self::valid_payload( $payload ) || Inventory::slug_for( $file ) !== $payload['plugin_slug'] ) {
			return;
		}
		self::clear();
		$due = time() + MINUTE_IN_SECONDS;
		if ( Options::set(
			Options::FIRST_INSTALL,
			array(
				'site_id'     => $site,
				'key_sha256'  => hash( 'sha256', (string) Options::get( Options::SITE_KEY ) ),
				'plugin_file' => $file,
				'payload'     => $payload,
				'created_at'  => time(),
				'attempts'    => 0,
				'next_at'     => $due,
			)
		) ) {
			self::schedule( $due );
		}
	}

	/** Delete local state and the dedicated event without contacting the API. */
	public static function clear(): void {
		Options::delete( Options::FIRST_INSTALL );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Deliver only from an explicitly wired background hook. */
	public static function flush(): void {
		$key   = (string) Options::get( Options::SITE_KEY, '' );
		$state = Options::get( Options::FIRST_INSTALL );
		if ( ! is_array( $state ) || ! empty( $state['recorded'] ) ) {
			return;
		}
		if ( ! isset( $state['site_id'], $state['key_sha256'], $state['plugin_file'], $state['payload'], $state['created_at'], $state['attempts'], $state['next_at'] )
			|| Options::get( Options::SITE_ID ) !== $state['site_id'] || ! Connection::is_connected()
			|| ! is_string( $state['key_sha256'] ) || ! hash_equals( $state['key_sha256'], hash( 'sha256', $key ) )
			|| ! is_string( $state['plugin_file'] ) || ! self::valid_payload( $state['payload'] )
			|| ! is_int( $state['created_at'] ) || ! is_int( $state['attempts'] ) || ! is_int( $state['next_at'] )
			|| $state['attempts'] < 0 || $state['attempts'] >= 6 || $state['created_at'] > time()
			|| time() - $state['created_at'] > 7 * DAY_IN_SECONDS ) {
			self::clear();
			return;
		}
		if ( ! Verified_Install::installed_matches( $state['plugin_file'], $state['payload']['version'] )
			|| Inventory::slug_for( $state['plugin_file'] ) !== $state['payload']['plugin_slug'] ) {
			self::clear();
			return;
		}
		if ( $state['next_at'] > time() ) {
			self::schedule( $state['next_at'] );
			return;
		}
		$delays           = array( 300, 1800, 7200, 43200, 86400, 86400 );
		$state['next_at'] = time() + $delays[ $state['attempts'] ];
		++$state['attempts'];
		if ( ! Options::set( Options::FIRST_INSTALL, $state ) ) {
			return;
		}
		// Persist the attempt and book recovery before HTTP, including a killed process.
		self::schedule( $state['next_at'] );
		$client = new Api_Client( AFTERWARE_API_BASE, $key );
		$result = $client->first_install( $state['payload'] );
		// A reconnect during HTTP must not resurrect the previous registration's state.
		if ( Options::get( Options::SITE_ID ) !== $state['site_id'] || Options::get( Options::SITE_KEY ) !== $key ) {
			return;
		}
		if ( is_array( $result ) && 2 === count( $result ) && isset( $result['schema'], $result['recorded'] ) && 1 === $result['schema'] && true === $result['recorded'] ) {
			if ( Options::set(
				Options::FIRST_INSTALL,
				array(
					'site_id'  => $state['site_id'],
					'recorded' => true,
				)
			) ) {
				wp_clear_scheduled_hook( self::HOOK );
			}
		}
	}

	/**
	 * Schedule at most one dedicated retry. The daily hook also recovers missing events.
	 *
	 * @param int $when Unix timestamp.
	 */
	private static function schedule( $when ): void {
		if ( ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( $when, self::HOOK );
		}
	}

	/**
	 * Validate stored metadata again before it can leave this site.
	 *
	 * @param mixed $payload Stored request body.
	 * @return bool
	 */
	private static function valid_payload( $payload ) {
		return is_array( $payload ) && 4 === count( $payload )
			&& isset( $payload['schema'], $payload['plugin_slug'], $payload['version'], $payload['sha256'] )
			&& 1 === $payload['schema'] && is_string( $payload['plugin_slug'] )
			&& strlen( $payload['plugin_slug'] ) <= 200 && 1 === preg_match( '/^[a-z0-9-]+$/D', $payload['plugin_slug'] )
			&& is_string( $payload['version'] ) && strlen( $payload['version'] ) <= 100
			&& 1 === preg_match( '/^\d+\.\d+\.\d+\.\d+$/D', $payload['version'] )
			&& is_string( $payload['sha256'] ) && 1 === preg_match( '/^[0-9a-f]{64}$/D', $payload['sha256'] );
	}
}
