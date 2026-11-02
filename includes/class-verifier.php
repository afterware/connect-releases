<?php
/**
 * Ed25519 manifest verification and file hashing.
 *
 * @package Afterware
 */

namespace Afterware;

use SodiumException;
use WP_Error;

/**
 * Verifies signed update manifests against the trusted key ring.
 *
 * Signatures are always checked over the exact manifest text as received; the
 * text is never re-serialised before verification. Nothing here ever signs.
 */
final class Verifier {

	/**
	 * Derives the key id of a public key: hex(sha256(raw))[0:16].
	 *
	 * @param string $raw_public_key Raw 32-byte Ed25519 public key.
	 * @return string 16 lowercase hex characters.
	 */
	public static function key_id( $raw_public_key ) {
		return substr( hash( 'sha256', $raw_public_key ), 0, 16 );
	}

	/**
	 * Verifies a detached Ed25519 signature over the exact manifest text.
	 *
	 * The ring defaults to what the site trusts right now — pinned keys plus
	 * whatever a verified key ring has since added, minus anything revoked or
	 * out of date. A caller passes its own only to ask a different question,
	 * such as what would have verified at another moment.
	 *
	 * @param string                    $text          Exact manifest text as received.
	 * @param string                    $signature_b64 Detached signature, base64.
	 * @param string                    $key_id        16 hex chars.
	 * @param array<string,string>|null $ring          Key id => raw public key, or null for the current trusted set.
	 * @return true|WP_Error True when the signature verifies.
	 */
	public static function verify_manifest( $text, $signature_b64, $key_id, ?array $ring = null ) {
		$ring   = ( null === $ring ) ? Key_Ring::trusted_at( time() ) : $ring;
		$key_id = (string) $key_id;
		// A key of the wrong length can only come from a broken ring, and sodium would
		// throw on it. Treat it as if the key were not there at all.
		if ( ! isset( $ring[ $key_id ] ) || 32 !== strlen( $ring[ $key_id ] ) ) {
			return new WP_Error(
				'afterware_unknown_key',
				sprintf(
					/* translators: %s: key id from the manifest. */
					__( 'Manifest signed with unknown key %s. Update Afterware Connect to refresh the trusted keys.', 'afterware' ),
					$key_id
				)
			);
		}
		$sig = base64_decode( (string) $signature_b64, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a detached signature from the API, not obfuscated code.
		if ( false === $sig || 64 !== strlen( $sig ) ) {
			return new WP_Error( 'afterware_bad_signature_encoding', __( 'Manifest signature is not a valid Ed25519 signature.', 'afterware' ) );
		}
		try {
			$ok = sodium_crypto_sign_verify_detached( $sig, (string) $text, $ring[ $key_id ] );
		} catch ( SodiumException $e ) {
			$ok = false;
		}
		if ( ! $ok ) {
			return new WP_Error( 'afterware_bad_signature', __( 'Manifest signature does not verify against the trusted Afterware key. Nothing was installed.', 'afterware' ) );
		}
		return true;
	}

	/**
	 * The id of the key in $ring whose detached signature covers $bytes.
	 *
	 * Separate from verify_manifest() because the caller does not know which
	 * key to expect: a key ring names no signer, so every candidate is tried
	 * and the one that worked is reported back. Returns a plain string rather
	 * than a WP_Error because both callers phrase their own refusal.
	 *
	 * @param string               $bytes         Exact bytes the signature covers.
	 * @param string               $signature_b64 Detached signature, base64; surrounding whitespace is ignored.
	 * @param array<string,string> $ring          Key id => raw 32-byte public key.
	 * @return string The signing key's id, or '' when nothing in the ring signed these bytes.
	 */
	public static function verify_detached( $bytes, $signature_b64, array $ring ) {
		$signature = base64_decode( trim( (string) $signature_b64 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding a detached signature, not obfuscated code.
		if ( false === $signature || 64 !== strlen( $signature ) ) {
			return '';
		}

		foreach ( $ring as $id => $raw ) {
			if ( ! is_string( $raw ) || 32 !== strlen( $raw ) ) {
				continue;
			}
			try {
				$ok = sodium_crypto_sign_verify_detached( $signature, (string) $bytes, $raw );
			} catch ( SodiumException $e ) {
				$ok = false;
			}
			if ( $ok ) {
				return (string) $id;
			}
		}

		return '';
	}

	/**
	 * Decodes a verified manifest and checks its shape.
	 *
	 * @param string $text Manifest text, already signature-verified.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function parse_manifest( $text ) {
		$m = json_decode( (string) $text, true );
		if ( ! is_array( $m )
			|| 1 !== ( $m['schema_version'] ?? null )
			|| ! self::is_non_empty_string( $m['slug'] ?? null )
			|| ! array_key_exists( 'latest', $m )
			|| ! ( null === $m['latest'] || self::is_non_empty_string( $m['latest'] ) )
			|| ! isset( $m['releases'] ) || ! is_array( $m['releases'] )
		) {
			return new WP_Error( 'afterware_bad_manifest', __( 'Manifest does not match the expected format.', 'afterware' ) );
		}
		$versions = array();
		foreach ( $m['releases'] as $r ) {
			if ( ! is_array( $r )
				|| ! self::is_non_empty_string( $r['version'] ?? null )
				|| ! self::is_non_empty_string( $r['package_url'] ?? null )
				|| ! self::is_non_empty_string( $r['sha256'] ?? null )
				|| ! preg_match( '/^[0-9a-f]{64}\z/', $r['sha256'] )
			) {
				return new WP_Error( 'afterware_bad_manifest', __( 'Manifest release entry is incomplete.', 'afterware' ) );
			}
			if ( in_array( $r['version'], $versions, true ) ) {
				return new WP_Error( 'afterware_bad_manifest', __( 'Manifest lists the same version more than once.', 'afterware' ) );
			}
			$versions[] = $r['version'];
		}
		if ( null !== $m['latest'] && ! in_array( $m['latest'], $versions, true ) ) {
			return new WP_Error( 'afterware_bad_manifest', __( 'Manifest names a latest version it does not list as a release.', 'afterware' ) );
		}
		return $m;
	}

	/**
	 * Hashes a file with SHA-256.
	 *
	 * @param string $path Absolute path to the file.
	 * @return string|false Lowercase hex digest, or false when the file cannot be hashed.
	 */
	public static function sha256_file( $path ) {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return false;
		}
		return hash_file( 'sha256', $path );
	}

	/**
	 * Whether a value is a string with content.
	 *
	 * @param mixed $value Value to test.
	 * @return bool
	 */
	private static function is_non_empty_string( $value ) {
		return is_string( $value ) && '' !== $value;
	}
}
