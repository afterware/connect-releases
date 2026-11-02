<?php
/**
 * The option behind the fetched key ring.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Reads and writes `afterware_fetched_keys`: a cache of the last key ring
 * document that verified, and nothing more.
 *
 * The option stores the ring's **exact bytes** and its detached signature, not
 * the keys parsed out of them, and every read re-checks that signature against
 * the keys pinned in this build's own code before a single entry is believed.
 * That is the whole point of this class. An option row is writable by anything
 * with database access — SQL injection in an unrelated plugin, a leaked
 * credential, a shared-host neighbour — and a trusted key set that could be
 * written there would turn one of those into arbitrary plugin code execution,
 * which is precisely what signed updates exist to survive. So the database gets
 * to hold the document; only code gets to say whether it counts.
 *
 * The one thing the record carries that is not signature-backed is `narrowed`:
 * per key id, the narrowest thing this site has ever verified — a revocation,
 * the latest start date, the earliest end date. Rings are published whole and
 * replace each other whole, and every ring the platform ever signed stays a
 * validly signed document, so whoever answers the ring's URL can serve an older
 * one back; without a memory that would undo a revocation or reopen a retired
 * key. Narrowings carry no key material and are only ever subtracted, so the
 * worst a forged one can do is refuse an update. That asymmetry is deliberate,
 * and apply_narrowings() is where it is enforced.
 */
final class Key_Ring_Store {

	/**
	 * How old a stored ring may be before it should be fetched again.
	 *
	 * @var int
	 */
	const MAX_AGE = 30 * DAY_IN_SECONDS;

	/**
	 * The fields a narrowing carries. Deliberately no `public_key`: nothing in
	 * the database-writable half of this record may ever become a key.
	 *
	 * @var string[]
	 */
	const NARROWING_FIELDS = array( 'status', 'valid_from', 'valid_until' );

	/**
	 * The last verified ring, keyed by the bytes and signers it was computed
	 * from, so a request that reads the ring fifty times verifies once.
	 *
	 * @var array{key:string,ring:array<string,array<string,mixed>>}|null
	 */
	private static $memo = null;

	/**
	 * The stored record, or null when there is nothing usable stored.
	 *
	 * Shape only. Nothing here has been checked against a key yet.
	 *
	 * @return array{fetched_at:int,body:string,sig:string,signer:string,narrowed:array<string,array{status:string,valid_from:string,valid_until:string|null}>}|null
	 */
	public static function read() {
		$stored = Options::get( Options::KEY_RING, null );
		if ( ! is_array( $stored )
			|| ! isset( $stored['fetched_at'], $stored['body'], $stored['sig'], $stored['signer'] )
			|| ! is_string( $stored['body'] )
			|| ! is_string( $stored['sig'] )
			|| ! is_string( $stored['signer'] )
		) {
			return null;
		}

		return array(
			'fetched_at' => (int) $stored['fetched_at'],
			'body'       => $stored['body'],
			'sig'        => $stored['sig'],
			'signer'     => $stored['signer'],
			'narrowed'   => self::narrowings(),
		);
	}

	/**
	 * The ring entries this site may believe: the stored document, re-verified
	 * against the pinned keys, parsed, and narrowed by what is already known.
	 *
	 * Empty whenever the document is missing, unsigned by any pinned key, or
	 * not a keys.json — a cache that cannot prove itself is simply not there.
	 *
	 * @param array<string,string> $pinned Keys pinned in this build, key id => raw public key.
	 * @param int|null             $now    Unix time to judge signer windows at, or null for now.
	 * @return array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}>
	 */
	public static function entries( array $pinned, $now = null ) {
		$stored = self::read();
		if ( null === $stored ) {
			return array();
		}

		$signers = self::signers( $pinned, $now );
		$key     = md5( $stored['body'] . '|' . $stored['sig'] . '|' . implode( ',', array_keys( $signers ) ) . '|' . wp_json_encode( $stored['narrowed'] ) );
		if ( null !== self::$memo && self::$memo['key'] === $key ) {
			return self::$memo['ring'];
		}

		$ring = array();
		if ( '' !== Verifier::verify_detached( $stored['body'], $stored['sig'], $signers ) ) {
			$parsed = Key_Ring_Parser::parse( $stored['body'] );
			if ( ! is_wp_error( $parsed ) ) {
				$ring = self::apply_narrowings( $parsed, $stored['narrowed'] );
			}
		}

		self::$memo = array(
			'key'  => $key,
			'ring' => $ring,
		);
		return $ring;
	}

	/**
	 * Whether the stored ring currently verifies against a pinned key. For the
	 * settings card, which has to be able to say "stored, but not trusted by
	 * this build" rather than silently showing nothing.
	 *
	 * @param array<string,string> $pinned Keys pinned in this build.
	 * @param int|null             $now    Unix time to judge signer windows at, or null for now.
	 * @return bool False when nothing is stored, too.
	 */
	public static function trusted( array $pinned, $now = null ) {
		$stored = self::read();
		if ( null === $stored ) {
			return false;
		}
		return '' !== Verifier::verify_detached( $stored['body'], $stored['sig'], self::signers( $pinned, $now ) );
	}

	/**
	 * The pinned keys still allowed to vouch for a stored ring: all of them, less
	 * any this site has verified a revocation or a closed validity window for.
	 *
	 * A window binds here exactly as it binds a manifest signature. Without that,
	 * a pinned key Afterware had timed out could no longer verify an update but
	 * could still authenticate any ring, on every site, for the life of the
	 * build — a retired key left holding the more powerful of its two jobs.
	 *
	 * @param array<string,string> $pinned Keys pinned in this build.
	 * @param int|null             $now    Unix time to judge windows at, or null for now.
	 * @return array<string,string>
	 */
	public static function signers( array $pinned, $now = null ) {
		$now = ( null === $now ) ? time() : (int) $now;
		foreach ( self::narrowings() as $id => $narrowing ) {
			if ( 'revoked' === $narrowing['status'] || ! Key_Ring::in_window( $narrowing, $now ) ) {
				unset( $pinned[ $id ] );
			}
		}
		return $pinned;
	}

	/**
	 * Everything this site has already verified that narrows trust, validated.
	 *
	 * @return array<string,array{status:string,valid_from:string,valid_until:string|null}>
	 */
	public static function narrowings() {
		$stored = Options::get( Options::KEY_RING, null );
		if ( ! is_array( $stored ) || ! isset( $stored['narrowed'] ) || ! is_array( $stored['narrowed'] ) ) {
			return array();
		}

		$out = array();
		foreach ( $stored['narrowed'] as $id => $narrowing ) {
			if ( ! is_array( $narrowing )
				|| ! isset( $narrowing['status'], $narrowing['valid_from'] )
				|| ! array_key_exists( 'valid_until', $narrowing )
				|| ! in_array( $narrowing['status'], Key_Ring_Parser::STATUSES, true )
				|| ! Key_Ring_Parser::is_timestamp( $narrowing['valid_from'] )
				|| ( null !== $narrowing['valid_until'] && ! Key_Ring_Parser::is_timestamp( $narrowing['valid_until'] ) )
			) {
				continue;
			}
			$out[ (string) $id ] = array(
				'status'      => $narrowing['status'],
				'valid_from'  => $narrowing['valid_from'],
				'valid_until' => $narrowing['valid_until'],
			);
		}
		return $out;
	}

	/**
	 * Caches a verified ring document and the narrowings that go with it.
	 *
	 * Stores exactly what it is given. The caller judges a candidate ring
	 * before storing it, and what it judged must be what takes effect.
	 *
	 * @param string                                                                                         $body     Exact bytes of keys.json as served.
	 * @param string                                                                                         $sig      Exact contents of keys.json.sig.
	 * @param string                                                                                         $signer   Id of the pinned key whose signature vouched for it.
	 * @param array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}> $narrowed The carried-forward view; reduced to its narrowing fields here.
	 * @param int                                                                                            $now      Time the ring was fetched.
	 * @return void
	 */
	public static function write( $body, $sig, $signer, array $narrowed, $now ) {
		// Merge, never replace: see the class docblock. An id already known and
		// missing from $narrowed keeps exactly what it had.
		$record = self::narrowings();
		foreach ( $narrowed as $id => $entry ) {
			$id    = (string) $id;
			$fresh = array(
				'status'      => $entry['status'],
				'valid_from'  => $entry['valid_from'],
				'valid_until' => $entry['valid_until'],
			);

			$record[ $id ] = isset( $record[ $id ] ) ? self::narrowest( $record[ $id ], $fresh ) : $fresh;
		}

		self::$memo = null;
		Options::set(
			Options::KEY_RING,
			array(
				'fetched_at' => (int) $now,
				'body'       => (string) $body,
				'sig'        => (string) $sig,
				'signer'     => (string) $signer,
				'narrowed'   => $record,
			)
		);
	}

	/**
	 * A candidate ring with everything this site has already learned about each
	 * key folded back in, so accepting it can only ever narrow trust.
	 *
	 * @param array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}> $ring Candidate ring, freshly parsed.
	 * @return array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}>
	 */
	public static function carry_forward( array $ring ) {
		return self::apply_narrowings( $ring, self::narrowings() );
	}

	/**
	 * Folds narrowings into parsed ring entries, subtracting only.
	 *
	 * A narrowing for a key the signed document does not carry is dropped on
	 * the floor: it has no public key of its own and there is nothing for it to
	 * narrow. That is the rule that keeps the database out of the trusted set.
	 *
	 * @param array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}> $ring      Parsed ring entries.
	 * @param array<string,array{status:string,valid_from:string,valid_until:string|null}>                   $narrowed  What is already known.
	 * @return array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}>
	 */
	private static function apply_narrowings( array $ring, array $narrowed ) {
		foreach ( $ring as $id => $entry ) {
			if ( isset( $narrowed[ $id ] ) ) {
				$ring[ $id ] = self::narrowest( $narrowed[ $id ], $entry );
			}
		}
		return $ring;
	}

	/**
	 * The narrower of two views of one key: revoked if either says so, the later
	 * start date, the earlier end date.
	 *
	 * Every other field comes from $entry, so the caller passes the
	 * signature-backed side there and what is merely remembered as $known. That
	 * is what keeps a narrowing incapable of introducing anything.
	 *
	 * @param array{status:string,valid_from:string,valid_until:string|null} $known What this site has already verified.
	 * @param array<string,mixed>                                            $entry The view being narrowed.
	 * @return array<string,mixed>
	 */
	private static function narrowest( array $known, array $entry ) {
		if ( 'revoked' === $known['status'] ) {
			$entry['status'] = 'revoked';
		}
		if ( Key_Ring_Parser::moment( $entry['valid_from'] ) < Key_Ring_Parser::moment( $known['valid_from'] ) ) {
			$entry['valid_from'] = $known['valid_from'];
		}
		if ( null !== $known['valid_until']
			&& ( null === $entry['valid_until']
				|| Key_Ring_Parser::moment( $known['valid_until'] ) < Key_Ring_Parser::moment( $entry['valid_until'] ) )
		) {
			$entry['valid_until'] = $known['valid_until'];
		}
		return $entry;
	}

	/**
	 * Whether the stored ring is old enough to fetch again. Nothing stored is
	 * stale by definition.
	 *
	 * @param int $now     Current Unix time.
	 * @param int $max_age How long a ring stays fresh. Defaults to self::MAX_AGE
	 *                     rather than repeating that value, so the two can
	 *                     never quietly drift apart.
	 * @return bool
	 */
	public static function stale( $now, $max_age = self::MAX_AGE ) {
		$stored = self::read();
		if ( null === $stored ) {
			return true;
		}
		return ( (int) $now - $stored['fetched_at'] ) > (int) $max_age;
	}

	/**
	 * Forgets the stored ring and every narrowing, leaving only the pinned keys.
	 *
	 * @return void
	 */
	public static function forget() {
		self::$memo = null;
		Options::delete( Options::KEY_RING );
	}
}
