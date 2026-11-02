<?php
/**
 * The arrays the settings screen's Key ring card renders.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Key_Ring;
use Afterware\Key_Ring_Parser;
use Afterware\Key_Ring_Store;

/**
 * Turns Key_Ring::describe() and the stored ring's own bookkeeping into rows
 * a template can print without deciding anything itself.
 *
 * Split out of Admin_Data_Site to keep that file readable, the same
 * reasoning that split the plugin and row helpers into their own traits.
 * Reads Key_Ring::describe() only — never keys() or trusted_at(), which are
 * for a signature decision, not a screen.
 */
trait Admin_Data_Keys {

	/**
	 * Everything the Key ring card renders.
	 *
	 * @return array<string,mixed>
	 */
	public static function key_ring() {
		$stored = Key_Ring_Store::read();
		$pinned = Key_Ring::pinned();

		return array(
			'rows'             => self::key_ring_rows( Key_Ring::describe() ),
			'fetched_at'       => null === $stored ? null : $stored['fetched_at'],
			'never_fetched'    => null === $stored,
			'stale'            => Key_Ring_Store::stale( time() ),
			// Not "is anything pinned" but "can anything still vouch for a ring":
			// a verified revocation, or a closed window, can leave a build with
			// pinned keys and no signer, and a refresh then cannot succeed.
			'can_fetch'        => array() !== Key_Ring_Store::signers( $pinned ),
			// Stored, but no longer provable against this build's pinned keys.
			'stored_untrusted' => null !== $stored && ! Key_Ring_Store::trusted( $pinned ),
		);
	}

	/**
	 * One row per key this site knows about.
	 *
	 * @param array<int,array{id:string,status:string,source:string,valid_from:string|null,valid_until:string|null,trusted:bool}> $rows Key_Ring::describe()'s rows.
	 * @return array<int,array<string,mixed>>
	 */
	private static function key_ring_rows( array $rows ) {
		$out = array();
		foreach ( $rows as $row ) {
			$out[] = array(
				'id'          => $row['id'],
				'source'      => 'pinned' === $row['source'] ? __( 'Pinned', 'afterware' ) : __( 'Fetched', 'afterware' ),
				'chip'        => self::key_status_chip( $row['status'], $row['trusted'] ),
				'valid_from'  => null === $row['valid_from'] ? null : Key_Ring_Parser::day( $row['valid_from'] ),
				'valid_until' => null === $row['valid_until'] ? null : Key_Ring_Parser::day( $row['valid_until'] ),
				'trusted'     => $row['trusted'],
			);
		}
		// Keys that cannot sign anything today sort last, so the live ones read
		// as the answer to "what does this site trust" and the rest as history.
		usort(
			$out,
			static function ( array $a, array $b ) {
				return ( $a['trusted'] === $b['trusted'] ) ? 0 : ( $a['trusted'] ? -1 : 1 );
			}
		);
		return $out;
	}

	/**
	 * The chip a key's status wears.
	 *
	 * A key whose window has closed is `active` in the ring and useless on this
	 * site today, so it gets a status of its own rather than a green chip and a
	 * date the reader has to compare against today's by eye.
	 *
	 * @param string $status  One of active, standby, revoked.
	 * @param bool   $trusted Whether the key may verify something right now.
	 * @return array{tone:string,label:string,prefix:string}
	 */
	private static function key_status_chip( $status, $trusted = true ) {
		if ( ! $trusted && 'revoked' !== $status ) {
			$status = 'expired';
		}
		$labels = array(
			'active'  => __( 'Active', 'afterware' ),
			'standby' => __( 'Standby', 'afterware' ),
			'revoked' => __( 'Revoked', 'afterware' ),
			'expired' => __( 'Out of date range', 'afterware' ),
		);
		$tones  = array(
			'active'  => 'ok',
			'standby' => 'info',
			'revoked' => 'danger',
			'expired' => 'muted',
		);
		return array(
			'tone'   => isset( $tones[ $status ] ) ? $tones[ $status ] : 'muted',
			'label'  => isset( $labels[ $status ] ) ? $labels[ $status ] : $status,
			'prefix' => _x( 'Status: ', 'prefix read out before a key status chip label', 'afterware' ),
		);
	}
}
