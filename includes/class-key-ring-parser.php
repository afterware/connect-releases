<?php
/**
 * The published keys.json format.
 *
 * @package Afterware
 */

namespace Afterware;

use DateTimeImmutable;
use DateTimeZone;
use Exception;
use WP_Error;

/**
 * Reads the platform's key ring document (`docs/references/update-manifest.md`,
 * "Key ring") into entries this plugin can store.
 *
 * The whole document is refused when any single entry is inconsistent, rather
 * than that entry dropped: a ring that has been tampered with cannot be partly
 * trusted, and quietly ignoring the broken key would leave the good ones
 * looking like the whole truth. `afterware-workflows`
 * (`tools/src/key-ring.ts`, `parseKeyRing`) applies the same rules to the same
 * document; a change to either is a change to both.
 *
 * Strict about every field it uses, and deliberately incurious about the rest.
 * A field this build has never heard of is ignored, exactly as the reference
 * tool's `z.looseObject` ignores it, because being stricter than the tool is
 * not caution: the day the platform adds a field, every site would refuse the
 * ring, keep the one it already has, and stop rotating without saying so. The
 * same reasoning covers a duplicate id (the first entry wins, as
 * `selectSigningKey` does), a window whose end precedes its start (never in
 * window, so it needs no special refusal) and a `schema_version` of `1.0`.
 *
 * Nothing here decides trust. It decides only whether a document is a keys.json.
 */
final class Key_Ring_Parser {

	/**
	 * The only schema version this build understands.
	 *
	 * @var int
	 */
	const SCHEMA_VERSION = 1;

	/**
	 * The fields a key entry must carry. Anything else in it is ignored.
	 *
	 * @var string[]
	 */
	const ENTRY_FIELDS = array( 'key_id', 'public_key', 'status', 'valid_from', 'valid_until' );

	/**
	 * The statuses a key may have. `standby` is usable — it is a signing key
	 * that simply is not the current one, and a manifest signed just before a
	 * rotation is signed by a perfectly good key.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'active', 'standby', 'revoked' );

	/**
	 * How a validity window is stored: ISO 8601, normalised to UTC, seconds
	 * precision, fixed width.
	 *
	 * @var string
	 */
	const TIMESTAMP_FORMAT = 'Y-m-d\TH:i:sP';

	/**
	 * Parses a key ring document.
	 *
	 * @param string $text Exact bytes as served, already signature-verified.
	 * @return array<string,array{public_key:string,status:string,valid_from:string,valid_until:string|null}>|WP_Error
	 */
	public static function parse( $text ) {
		$data = json_decode( (string) $text, true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'afterware_key_ring_not_json', __( 'The key ring is not JSON.', 'afterware' ) );
		}
		if ( ! isset( $data['schema_version'], $data['keys'] ) || ! is_numeric( $data['schema_version'] ) ) {
			return self::malformed();
		}
		if ( (float) self::SCHEMA_VERSION !== (float) $data['schema_version'] ) {
			return new WP_Error(
				'afterware_key_ring_schema_version',
				__( 'The key ring uses a schema version this build of Afterware Connect does not understand.', 'afterware' )
			);
		}
		if ( ! is_array( $data['keys'] ) || array() === $data['keys'] ) {
			return new WP_Error( 'afterware_key_ring_empty', __( 'The key ring lists no keys.', 'afterware' ) );
		}

		$ring = array();
		foreach ( $data['keys'] as $entry ) {
			$parsed = self::entry( $entry );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			if ( isset( $ring[ $parsed['key_id'] ] ) ) {
				// The first entry for an id wins, matching selectSigningKey.
				continue;
			}
			$ring[ $parsed['key_id'] ] = array(
				'public_key'  => $parsed['public_key'],
				'status'      => $parsed['status'],
				'valid_from'  => $parsed['valid_from'],
				'valid_until' => $parsed['valid_until'],
			);
		}
		return $ring;
	}

	/**
	 * Validates one key entry.
	 *
	 * The key id is a digest of the key, so an entry whose id does not match
	 * the key it carries is either corrupt or an attempt to have something
	 * verified against a substituted key under a trusted name.
	 *
	 * @param mixed $entry One element of the document's `keys` array.
	 * @return array{key_id:string,public_key:string,status:string,valid_from:string,valid_until:string|null}|WP_Error
	 */
	private static function entry( $entry ) {
		if ( ! is_array( $entry ) ) {
			return self::malformed();
		}
		foreach ( self::ENTRY_FIELDS as $field ) {
			if ( ! array_key_exists( $field, $entry ) ) {
				return self::malformed();
			}
		}

		$id = $entry['key_id'];
		if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{16}\z/', $id ) ) {
			return self::malformed();
		}
		if ( ! is_string( $entry['public_key'] ) ) {
			return self::malformed();
		}
		$raw = base64_decode( $entry['public_key'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a published public key, not obfuscated code.
		if ( false === $raw || 32 !== strlen( $raw ) || Verifier::key_id( $raw ) !== $id ) {
			return self::malformed();
		}
		if ( ! in_array( $entry['status'], self::STATUSES, true ) ) {
			return self::malformed();
		}

		$from = self::timestamp( $entry['valid_from'] );
		if ( null === $from ) {
			return self::malformed();
		}
		$until = null;
		if ( null !== $entry['valid_until'] ) {
			$until = self::timestamp( $entry['valid_until'] );
			if ( null === $until ) {
				return self::malformed();
			}
		}

		return array(
			'key_id'      => $id,
			'public_key'  => $entry['public_key'],
			'status'      => $entry['status'],
			'valid_from'  => $from,
			'valid_until' => $until,
		);
	}

	/**
	 * An offset-bearing ISO 8601 timestamp, normalised to UTC.
	 *
	 * `YYYY-MM-DDTHH:MM:SS(.fraction)?(Z|±HH:MM)`, matching the reference
	 * tool exactly (`tools/src/key-ring.ts`'s `z.iso.datetime({ offset: true })`,
	 * `zod/v4/core/regexes.js`'s `datetime()`): seconds are mandatory —
	 * RFC 3339 requires them wherever a `Z` or an offset is present, and only
	 * the unqualified local form zod's `local: true` adds, which this build
	 * does not use, may omit them — a fractional second may have any number
	 * of digits, and the offset's hour and minute are each range-checked
	 * (00-23, 00-59) the same way zod's does. A validity window is only a
	 * limit if it can be read, so the format is checked before the value is:
	 * a timestamp nothing can parse would otherwise become a window with no
	 * ends, and a key valid forever.
	 *
	 * The result is a string, not a Unix time, and every comparison on it goes
	 * through DateTimeImmutable. `strtotime()` returns false for any date past
	 * 2038-01-19 on a 32-bit build, and because this parser refuses whole
	 * documents, a single `valid_until: "2099-01-01T00:00:00Z"` — the shape a
	 * key with no practical end date has — would have refused the entire ring
	 * on every 32-bit site, permanently, with nothing but an activity row to
	 * show for it. DateTimeImmutable has no such limit.
	 *
	 * Normalising to UTC means the stored form is fixed-width and directly
	 * comparable, and that two rings expressing the same instant with different
	 * offsets narrow against each other correctly.
	 *
	 * @param mixed $value Value from a `valid_from` or `valid_until` field.
	 * @return string|null Null when the value is not a timestamp with an offset.
	 */
	private static function timestamp( $value ) {
		if ( ! is_string( $value ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $value ) ) {
			return null;
		}
		$at = self::moment( $value );
		return null === $at ? null : $at->format( self::TIMESTAMP_FORMAT );
	}

	/**
	 * One timestamp as a comparable moment, or null when it is not one.
	 *
	 * @param mixed $value An ISO 8601 timestamp with an offset.
	 * @return DateTimeImmutable|null
	 */
	public static function moment( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return null;
		}
		try {
			$at = new DateTimeImmutable( $value );
		} catch ( Exception $e ) {
			return null;
		}
		return $at->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * A Unix time as a comparable moment. The one place a caller's `time()`
	 * crosses into this representation.
	 *
	 * @param int $unix Unix timestamp.
	 * @return DateTimeImmutable
	 */
	public static function at( $unix ) {
		return ( new DateTimeImmutable( '@' . (int) $unix ) )->setTimezone( new DateTimeZone( 'UTC' ) );
	}

	/**
	 * Whether a stored timestamp is one this build can still read.
	 *
	 * @param mixed $value Candidate timestamp.
	 * @return bool
	 */
	public static function is_timestamp( $value ) {
		return null !== self::moment( $value );
	}

	/**
	 * The date part of a normalised timestamp, for a screen or a table.
	 *
	 * @param string $value A normalised timestamp.
	 * @return string `YYYY-MM-DD`, or '' when the value cannot be read.
	 */
	public static function day( $value ) {
		$at = self::moment( $value );
		return null === $at ? '' : $at->format( 'Y-m-d' );
	}

	/**
	 * The same, to the second, in the form the CLI table prints.
	 *
	 * @param string $value A normalised timestamp.
	 * @return string
	 */
	public static function moment_text( $value ) {
		$at = self::moment( $value );
		return null === $at ? '' : $at->format( 'Y-m-d H:i:s' ) . ' UTC';
	}

	/**
	 * The one refusal for every shape problem.
	 *
	 * Which field was wrong is deliberately not in the message: a site owner
	 * cannot act on it, and the ring is Afterware's to fix.
	 *
	 * @return WP_Error
	 */
	private static function malformed() {
		return new WP_Error( 'afterware_key_ring_malformed', __( 'The key ring does not match the expected format.', 'afterware' ) );
	}
}
