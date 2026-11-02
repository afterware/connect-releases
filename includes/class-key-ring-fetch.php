<?php
/**
 * Fetching and authenticating the published key ring.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Learns rotated and revoked signing keys from `keys.afterware.io`.
 *
 * The ring decides which keys may sign a manifest, so an unauthenticated ring is
 * not a weaker check, it is no check at all: whoever answered the URL could name
 * their own key and have this site verify manifests they wrote. Every ring is
 * checked against the keys pinned in this build's own code, and only those — a
 * key learned from an earlier ring may verify manifests but may never vouch for
 * the next ring. One hop, always, so that the stored document can be re-checked
 * against the same pinned keys on every read for as long as it is believed
 * (Key_Ring_Store). The operational rule that follows is in docs/security.md:
 * a release pins the next key before that key ever signs a ring.
 *
 * Two more rules follow. A ring must leave the site with an active trusted key,
 * or it could never verify anything again, including the ring that would fix it.
 * And nothing this site has already learned about a key is ever unlearned,
 * however later rings describe it (Key_Ring_Store::carry_forward()). Nothing
 * here signs, and nothing here runs while a page is being drawn.
 */
final class Key_Ring_Fetch {

	/**
	 * Largest response either document may have. The published ring is a few
	 * hundred bytes; past this, something else is going on.
	 *
	 * @var int
	 */
	const MAX_BYTES = 1048576;

	/**
	 * Seconds either request may take.
	 *
	 * @var int
	 */
	const TIMEOUT = 10;

	/**
	 * Fetches the ring, checks it and stores it when it may be trusted.
	 *
	 * @param callable|null $transport Stand-in for wp_remote_get(), called as
	 *                                 `$transport( $url, $args )`, returning what it would.
	 * @param int|null      $now       Unix time to judge the ring at, or null for now.
	 * @return array{ok:bool,reason:string,added:int,revoked:int}
	 */
	public static function refresh( $transport = null, $now = null ) {
		$now     = ( null === $now ) ? time() : (int) $now;
		$trusted = Key_Ring::trusted_at( $now );
		$signers = Key_Ring_Store::signers( Key_Ring::pinned(), $now );
		if ( array() === $signers ) {
			// Nothing could authenticate a ring, and refusing here costs no request.
			return self::refused( __( 'No Afterware signing key is trusted yet, so a key ring cannot be checked.', 'afterware' ) );
		}

		$document = self::fetch( Key_Ring_Url::ring(), $transport );
		if ( is_wp_error( $document ) ) {
			return self::refused( $document->get_error_message() );
		}
		$signature = self::fetch( Key_Ring_Url::sig(), $transport );
		if ( is_wp_error( $signature ) ) {
			return self::refused( $signature->get_error_message() );
		}

		$verified_by = Verifier::verify_detached( $document, $signature, $signers );
		if ( '' === $verified_by ) {
			return self::report( __( 'Signature does not verify against any key pinned in this build.', 'afterware' ) );
		}

		$ring = Key_Ring_Parser::parse( $document );
		if ( is_wp_error( $ring ) ) {
			return self::report( $ring->get_error_message() );
		}

		$candidate = Key_Ring_Store::carry_forward( $ring );
		$after     = Key_Ring::would_trust( $candidate, $now );
		if ( ! self::leaves_an_active_key( $trusted, $candidate, $after ) ) {
			return self::report( __( 'The key ring leaves no active signing key, so it was refused.', 'afterware' ) );
		}

		// One hop means one reader. A stored ring carries a single detached
		// signature, so the only key that can ever re-verify it is the one that
		// signed it; no other pinned key can stand in. A ring that withdraws its
		// own signer would therefore be unreadable the moment it was written,
		// taking every fetched key with it one request after being announced as
		// accepted — and every later refresh of the same document would be
		// refused for an unknown signer. The runbook says the same thing to the
		// operator: never publish a ring signed by a key that ring revokes.
		if ( ! isset( $after[ $verified_by ] ) ) {
			return self::report( __( 'The key ring withdraws the key that signed it, so nothing could read it back.', 'afterware' ) );
		}

		// Narrow once more against a fresh read. A cron run and a "Refresh now"
		// click can overlap, served different documents; without this the later
		// writer would carry forward the view it read before the other one
		// landed and quietly drop a narrowing that had just been learned.
		Key_Ring_Store::write( $document, $signature, $verified_by, Key_Ring_Store::carry_forward( $ring ), $now );

		return self::accepted( count( array_diff_key( $after, $trusted ) ), self::revocations( $candidate, $trusted ), $verified_by );
	}

	/**
	 * Gets one document, refusing anything that is not a plain 200 of a
	 * sensible size.
	 *
	 * @param string        $url       Document URL.
	 * @param callable|null $transport Stand-in for wp_remote_get(), or null.
	 * @return string|WP_Error The exact response body.
	 */
	private static function fetch( $url, $transport ) {
		$args = array(
			// A redirect is somebody else offering to answer for a pinned URL, so
			// none is followed; limit_response_size caps what is buffered.
			'redirection'         => 0,
			// One byte above what the body is checked against: a transport
			// truncates at this figure rather than erroring, so capping it at
			// MAX_BYTES would leave an oversized ring silently shortened and
			// reported as a signature failure, which points at the wrong thing.
			'limit_response_size' => self::MAX_BYTES + 1,
			'timeout'             => self::TIMEOUT,
			'sslverify'           => true,
			'headers'             => array(
				'Accept'     => 'application/json',
				// Deliberately not Api_Client's User-Agent, which carries
				// home_url(). That endpoint is already authenticated per site;
				// this one is an anonymous download of a public file from a host
				// that has no other reason to know the site exists, and adding
				// the address would turn it into a daily beacon.
				'User-Agent' => sprintf( 'Afterware Connect/%s', AFTERWARE_VERSION ),
			),
		);

		$response = ( null === $transport ) ? wp_remote_get( $url, $args ) : call_user_func( $transport, $url, $args );
		if ( is_wp_error( $response ) ) {
			/* translators: %s: the underlying transport error, e.g. "Connection timed out". */
			$message = sprintf( __( 'The key ring could not be fetched: %s.', 'afterware' ), self::sentence( $response->get_error_message() ) );
			return new WP_Error( 'afterware_key_ring_http', $message );
		}
		if ( ! is_array( $response ) ) {
			return new WP_Error( 'afterware_key_ring_http', __( 'The key ring could not be fetched: no response.', 'afterware' ) );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			return new WP_Error( 'afterware_key_ring_redirect', __( 'Redirects are refused.', 'afterware' ) );
		}
		if ( 200 !== $code ) {
			/* translators: %d: HTTP status code. */
			$message = sprintf( __( 'The key ring server answered with HTTP %d.', 'afterware' ), $code );
			return new WP_Error( 'afterware_key_ring_status', $message );
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'afterware_key_ring_too_large', __( 'Response over 1 MiB.', 'afterware' ) );
		}
		return $body;
	}

	/**
	 * Whether a candidate ring leaves the site with an active trusted key.
	 *
	 * The question is about the trusted set the ring would leave behind, not the
	 * ring's own contents. A key the site already trusted and the ring has not
	 * revoked is still active — not restating a key is not withdrawing it — so a
	 * minimal ring that withdraws one key is accepted and its revocation lands.
	 *
	 * @param array<string,string>                                                                           $trusted   What was trusted before this ring.
	 * @param array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}> $candidate The ring as it would be stored.
	 * @param array<string,string>                                                                           $after     What would then be trusted, windows already applied.
	 * @return bool
	 */
	private static function leaves_an_active_key( array $trusted, array $candidate, array $after ) {
		foreach ( $after as $id => $raw ) {
			if ( isset( $trusted[ $id ] ) ) {
				return true;
			}
			if ( isset( $candidate[ $id ] ) && 'active' === $candidate[ $id ]['status'] ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * How many keys this ring withdraws that the site was still trusting.
	 *
	 * @param array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}> $candidate The ring as it would be stored.
	 * @param array<string,string>                                                                           $trusted   What was trusted before it.
	 * @return int
	 */
	private static function revocations( array $candidate, array $trusted ) {
		$revoked = 0;
		foreach ( $candidate as $id => $entry ) {
			if ( 'revoked' === $entry['status'] && isset( $trusted[ $id ] ) ) {
				++$revoked;
			}
		}
		return $revoked;
	}

	/**
	 * Records an accepted ring and says what changed; one that changes nothing
	 * is accepted without an activity row.
	 *
	 * @param int    $added       Keys the site now trusts and did not before.
	 * @param int    $revoked     Keys withdrawn that it was trusting.
	 * @param string $verified_by Id of the key that vouched for the ring.
	 * @return array{ok:bool,reason:string,added:int,revoked:int}
	 */
	private static function accepted( $added, $revoked, $verified_by ) {
		if ( 0 === $added && 0 === $revoked ) {
			return array(
				'ok'      => true,
				'reason'  => __( 'The key ring is unchanged.', 'afterware' ),
				'added'   => 0,
				'revoked' => 0,
			);
		}

		$parts = array();
		if ( $added > 0 ) {
			/* translators: %d: number of signing keys added. */
			$parts[] = sprintf( _n( '%d key added', '%d keys added', $added, 'afterware' ), $added );
		}
		if ( $revoked > 0 ) {
			/* translators: %d: number of signing keys withdrawn. */
			$parts[] = sprintf( _n( '%d key revoked', '%d keys revoked', $revoked, 'afterware' ), $revoked );
		}
		$reason = sprintf(
			/* translators: %s: what changed, e.g. "1 key added, 1 key revoked". */
			__( 'Ring accepted: %s.', 'afterware' ),
			implode( ', ', $parts )
		);
		$data = array(
			'added'       => $added,
			'revoked'     => $revoked,
			'verified_by' => (string) $verified_by,
		);
		Activity::log( 'key_ring_updated', null, $data, $reason );

		return array(
			'ok'      => true,
			'reason'  => $reason,
			'added'   => $added,
			'revoked' => $revoked,
		);
	}

	/**
	 * A refusal worth finding later: the ring arrived and failed a check about
	 * the ring itself, not about the network.
	 *
	 * @param string $reason Sentence explaining the refusal.
	 * @return array{ok:bool,reason:string,added:int,revoked:int}
	 */
	private static function report( $reason ) {
		Activity::log( 'key_ring_refused', null, array(), $reason );
		return self::refused( $reason );
	}

	/**
	 * A refusal, in the shape every caller reads.
	 *
	 * @param string $reason Sentence explaining the refusal.
	 * @return array{ok:bool,reason:string,added:int,revoked:int}
	 */
	private static function refused( $reason ) {
		return array(
			'ok'      => false,
			'reason'  => (string) $reason,
			'added'   => 0,
			'revoked' => 0,
		);
	}

	/**
	 * Trims a transport's own words so they can sit mid-sentence.
	 *
	 * @param string $text Message from a transport.
	 * @return string
	 */
	private static function sentence( $text ) {
		$text = rtrim( trim( (string) $text ), '.' );
		return ( '' === $text ) ? __( 'no details', 'afterware' ) : $text;
	}
}
