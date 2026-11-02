<?php
/**
 * Pinned public keys for manifest verification.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * The set of Ed25519 public keys this build trusts.
 *
 * Two layers. Production keys are pinned in the source; a dev ring may be
 * injected through the AFTERWARE_DEV_KEY_RING constant, but only on a local or
 * development site. Those are the root of trust, and nothing at runtime can
 * add to it: the `afterware_key_ring` filter can remove keys but never
 * introduce one.
 *
 * On top of that sits the ring fetched from keys.afterware.io, which may hand
 * out new signing keys, withdraw old ones and put an end date on any of them.
 * It is stored as the signed document it arrived as, and its signature is
 * re-checked against the pinned keys on every read (Key_Ring_Store::entries()),
 * so the pinned ring is not merely where the chain starts — it is the only
 * thing that ever vouches for a fetched key, on every request, for as long as
 * that key is trusted. There is exactly one hop: a published ring must always
 * be signed by a key pinned in the Connect release reading it, which is why a
 * release pinning the next key ships before that key signs a ring.
 *
 * A build that pins nothing therefore trusts nothing, however many rings it has
 * stored, and a ring may not revoke the last pinned key that can vouch for it.
 */
final class Key_Ring {

	/**
	 * Production KMS keys, key id => base64 raw public key. See the November 2 bootstrap design.
	 *
	 * @var array<string,string>
	 */
	const PRODUCTION = array(
		'b88603055a4577c5' => '6wWHpXRk3JncEWqhc9vCfXP0FJpCdYQDbSMTaK8AS30=',
		'3b476e357bd64c30' => 'mA0JQ0+r5yVdhhd1dWsQ2fpAWG+FmKlyOu4KL9uTcG0=',
	);

	/**
	 * Environment types on which AFTERWARE_DEV_KEY_RING is honoured.
	 *
	 * @var string[]
	 */
	const DEV_ENVIRONMENTS = array( 'local', 'development' );

	/**
	 * The keys that may verify a signature right now.
	 *
	 * Kept as the name every caller already used, and deliberately identical to
	 * trusted_at( time() ) rather than a laxer variant of it: a ring read that
	 * ignored validity windows would be the one call in this class it is unsafe
	 * to hand to the verifier, and there is no reason to keep such a thing
	 * within reach.
	 *
	 * @return array<string,string> Key id => raw 32-byte public key.
	 */
	public static function keys() {
		return self::trusted_at( time() );
	}

	/**
	 * The keys that may verify a signature at a given moment: the merged ring
	 * with each fetched key's validity window applied.
	 *
	 * @param int $now Unix time the question is asked about.
	 * @return array<string,string> Key id => raw 32-byte public key.
	 */
	public static function trusted_at( $now ) {
		$pinned = self::pinned();
		return self::fold( $pinned, Key_Ring_Store::entries( $pinned, (int) $now ), (int) $now );
	}

	/**
	 * What the trusted set would become if a candidate ring were stored.
	 *
	 * Key_Ring_Fetch asks this before writing anything, so a ring that would
	 * leave the site unable to verify its own updates can be refused while
	 * refusing is still possible.
	 *
	 * @param array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}> $ring Candidate ring.
	 * @param int                                                                                            $now  Unix time to judge windows at.
	 * @return array<string,string> Key id => raw 32-byte public key.
	 */
	public static function would_trust( array $ring, $now ) {
		$pinned = self::pinned();
		if ( array() === $pinned ) {
			return array();
		}
		// A stored ring is only ever read back through a pinned signature, so a
		// candidate that revokes every pinned key would be unreadable the moment
		// it was written, taking the site's whole trusted set with it. Judging
		// it as trusting nothing is what makes the caller refuse it.
		foreach ( $ring as $id => $entry ) {
			if ( 'revoked' === $entry['status'] ) {
				unset( $pinned[ (string) $id ] );
			}
		}
		if ( array() === $pinned ) {
			return array();
		}
		return self::fold( self::pinned(), $ring, (int) $now );
	}

	/**
	 * Every key this site knows about, as rows for a screen or WP-CLI table.
	 *
	 * A pinned key with nothing said about it in a ring is active with no end
	 * date: that is what pinning it meant. A build that pins nothing describes
	 * nothing, because it trusts nothing — a table of fetched keys under a
	 * heading about what the site trusts would be a lie.
	 *
	 * @param int|null $now Unix time to judge each row against, or null for now.
	 * @return array<int,array{id:string,status:string,source:string,valid_from:string|null,valid_until:string|null,trusted:bool}>
	 */
	public static function describe( $now = null ) {
		$now    = ( null === $now ) ? time() : (int) $now;
		$pinned = self::pinned();
		if ( array() === $pinned ) {
			return array();
		}
		$stored  = Key_Ring_Store::entries( $pinned, $now );
		$trusted = self::fold( $pinned, $stored, $now );

		$rows = array();
		foreach ( array_keys( array_merge( $pinned, $stored ) ) as $id ) {
			$entry  = isset( $stored[ $id ] ) ? $stored[ $id ] : null;
			$rows[] = array(
				'id'          => (string) $id,
				'status'      => null === $entry ? 'active' : $entry['status'],
				'source'      => isset( $pinned[ $id ] ) ? 'pinned' : 'fetched',
				'valid_from'  => null === $entry ? null : $entry['valid_from'],
				'valid_until' => null === $entry ? null : $entry['valid_until'],
				'trusted'     => isset( $trusted[ (string) $id ] ),
			);
		}
		return $rows;
	}

	/**
	 * Whether a key id is one Afterware has withdrawn or timed out, as opposed
	 * to one this build has simply never heard of.
	 *
	 * The difference matters to a site owner: a key this build predates means
	 * "update Afterware Connect", and a key that has been withdrawn means
	 * "Afterware rotated, wait for the next signed manifest". Telling someone
	 * to update during a routine rotation sends them after the wrong problem.
	 *
	 * @param string $key_id 16 hex chars, as claimed by a manifest.
	 * @param int    $now    Unix time.
	 * @return bool
	 */
	public static function withdrawn( $key_id, $now ) {
		$key_id = (string) $key_id;
		$pinned = self::pinned();
		if ( array() === $pinned ) {
			return false;
		}

		$entry = null;
		$ring  = Key_Ring_Store::entries( $pinned, (int) $now );
		if ( isset( $ring[ $key_id ] ) ) {
			$entry = $ring[ $key_id ];
		} else {
			$narrowings = Key_Ring_Store::narrowings();
			if ( isset( $pinned[ $key_id ], $narrowings[ $key_id ] ) ) {
				$entry = $narrowings[ $key_id ];
			}
		}
		if ( null === $entry ) {
			return false;
		}

		return 'revoked' === $entry['status'] || ! self::in_window( $entry, (int) $now );
	}

	/**
	 * The pinned ring with a set of fetched entries folded into it.
	 *
	 * @param array<string,string>                                                                           $pinned Keys pinned in this build.
	 * @param array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}> $ring   Fetched entries, already proven and narrowed.
	 * @param int                                                                                            $now    Unix time to apply validity windows at.
	 * @return array<string,string> Key id => raw 32-byte public key.
	 */
	private static function fold( array $pinned, array $ring, $now ) {
		if ( array() === $pinned ) {
			// Nothing is pinned, so no ring could ever have been checked against
			// anything. A build in this state verifies nothing at all.
			return array();
		}

		$trusted = $pinned;
		foreach ( $ring as $id => $entry ) {
			$id = (string) $id;
			if ( 'revoked' === $entry['status'] || ! self::in_window( $entry, $now ) ) {
				unset( $trusted[ $id ] );
				continue;
			}
			$raw = base64_decode( $entry['public_key'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a verified public key, not obfuscated code.
			if ( false !== $raw && 32 === strlen( $raw ) && Verifier::key_id( $raw ) === $id ) {
				$trusted[ $id ] = $raw;
			}
		}

		// A narrowing about a pinned key the signed ring no longer mentions
		// still binds: it can only ever take a key away, so believing it costs
		// at worst an update, and not believing it would undo a revocation.
		foreach ( Key_Ring_Store::narrowings() as $id => $narrowing ) {
			if ( isset( $ring[ $id ] ) || ! isset( $trusted[ $id ] ) ) {
				continue;
			}
			if ( 'revoked' === $narrowing['status'] || ! self::in_window( $narrowing, $now ) ) {
				unset( $trusted[ $id ] );
			}
		}

		return self::filtered( $trusted );
	}

	/**
	 * Whether a fetched key's validity window is open at a given moment.
	 *
	 * Public because a validity window binds a key's two jobs alike: verifying a
	 * manifest, and vouching for a stored ring (Key_Ring_Store::signers()).
	 *
	 * @param array{valid_from:string,valid_until:string|null} $entry A stored ring entry.
	 * @param int                                              $now   Unix time.
	 * @return bool
	 */
	public static function in_window( array $entry, $now ) {
		$at   = Key_Ring_Parser::at( $now );
		$from = Key_Ring_Parser::moment( $entry['valid_from'] );
		if ( null === $from || $at < $from ) {
			// A window nothing can read is not a window; treating it as closed
			// is the only reading that cannot accidentally trust a key forever.
			return false;
		}
		if ( null === $entry['valid_until'] ) {
			return true;
		}
		$until = Key_Ring_Parser::moment( $entry['valid_until'] );
		return null !== $until && $at < $until;
	}

	/**
	 * The pinned root of trust: the keys compiled into this build, plus the dev
	 * ring where it is honoured. Public because it is the set every stored ring
	 * is re-checked against, which Key_Ring_Store and Key_Ring_Fetch both need.
	 *
	 * @return array<string,string> Key id => raw 32-byte public key.
	 */
	public static function pinned() {
		$ring = self::decode( self::PRODUCTION, true );
		if ( defined( 'AFTERWARE_DEV_KEY_RING' ) && in_array( self::environment_type(), self::DEV_ENVIRONMENTS, true ) ) {
			$dev = json_decode( (string) AFTERWARE_DEV_KEY_RING, true );
			if ( is_array( $dev ) ) {
				$ring = array_merge( $ring, self::decode( $dev, false ) );
			}
		}
		return $ring;
	}

	/**
	 * Runs a ring through the removal-only filter.
	 *
	 * @param array<string,string> $ring Key id => raw public key.
	 * @return array<string,string>
	 */
	private static function filtered( array $ring ) {
		/**
		 * Filters the trusted Ed25519 public keys used to verify update manifests.
		 *
		 * Only removals take effect: the result is intersected with the ring as it
		 * already stands, so a filter can distrust a key but cannot introduce a
		 * signing key. New keys arrive one way only, through a ring signed by a key
		 * this site already trusted.
		 *
		 * @param array<string,string> $ring Key id => raw 32-byte public key.
		 */
		/** @var mixed $filtered A filter may return anything; only an array narrows the ring. */
		$filtered = apply_filters( 'afterware_key_ring', $ring );

		return is_array( $filtered ) ? array_intersect_key( $ring, $filtered ) : $ring;
	}

	/**
	 * The environment type the key ring reasons about.
	 *
	 * Wraps wp_get_environment_type() behind a filter so tests can exercise the
	 * staging path; WP_ENVIRONMENT_TYPE is a constant and cannot be redefined
	 * inside a test run. When the real environment is already production, the
	 * filter is never consulted: trust can only ever narrow at runtime, so a
	 * filter must not be able to make a production site look like a dev
	 * environment and pull in AFTERWARE_DEV_KEY_RING.
	 *
	 * Public because Key_Ring_Url applies the same guard to the key ring's own
	 * URLs: pointing them at a local stub is exactly as sensitive as honouring
	 * AFTERWARE_DEV_KEY_RING, and it is one guard, not two to keep in step.
	 *
	 * @return string
	 */
	public static function environment_type() {
		$actual = wp_get_environment_type();
		if ( 'production' === $actual ) {
			return $actual;
		}

		/**
		 * Filters the environment type used to decide whether the dev key ring applies.
		 *
		 * Never consulted when wp_get_environment_type() itself is already
		 * 'production' (see above): this filter can only be used to narrow a
		 * non-production environment, never to widen a production one.
		 *
		 * @param string $type Result of wp_get_environment_type().
		 */
		/** @var mixed $type A filter may return anything. */
		$type = apply_filters( 'afterware_environment_type', $actual );
		return is_string( $type ) ? $type : 'production';
	}

	/**
	 * Base64-decodes a ring, dropping entries that are not a well-formed key for their id.
	 *
	 * @param array<string,string> $encoded Key id => base64 raw public key.
	 * @param bool                 $pinned  True for keys pinned in this file; a malformed
	 *                                      entry there is a build mistake, so it warns.
	 * @return array<string,string> Key id => raw 32-byte public key.
	 */
	private static function decode( array $encoded, $pinned ) {
		$out = array();
		foreach ( $encoded as $id => $b64 ) {
			$id  = (string) $id;
			$raw = base64_decode( (string) $b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a pinned public key, not obfuscated code.
			if ( false !== $raw && 32 === strlen( $raw ) && Verifier::key_id( $raw ) === $id ) {
				$out[ $id ] = $raw;
				continue;
			}
			if ( $pinned ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: key id of the pinned key that was ignored. */
						esc_html__( 'Pinned key %s is not a valid 32-byte Ed25519 public key for its key id and was ignored.', 'afterware' ),
						esc_html( $id )
					),
					'0.1.0'
				);
			}
		}
		return $out;
	}
}
