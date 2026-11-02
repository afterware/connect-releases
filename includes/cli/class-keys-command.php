<?php
/**
 * The `keys` wp-cli command.
 *
 * @package Afterware
 */

namespace Afterware\CLI;

use Afterware\Activity;
use Afterware\Key_Ring;
use Afterware\Key_Ring_Fetch;
use Afterware\Key_Ring_Parser;
use Afterware\Key_Ring_Store;

/**
 * Lists this site's trusted signing keys, refreshes the ring on demand, or
 * forgets everything fetched.
 *
 * Reads only Key_Ring::describe(), never Key_Ring::keys() or trusted_at(): a
 * listing is not a signature decision. Refreshing goes through
 * Key_Ring_Fetch::refresh() — the same seam its own tests use — so this
 * command can never authenticate a ring any differently than a real fetch
 * would. Split out of Commands to keep that file a manageable size, the same
 * reasoning as Switch_Commands and Support_Commands.
 */
trait Keys_Command {

	/**
	 * Lists, refreshes, or forgets this site's signing keys.
	 *
	 * ## OPTIONS
	 *
	 * [--refresh]
	 * : Fetch and verify the published key ring now, instead of listing what
	 * is already known. Prints the reason the fetch was accepted or refused.
	 *
	 * [--forget]
	 * : Forget every fetched key and rely on only the pinned keys again, until
	 * the next refresh. Recovery for a ring that was accepted by mistake.
	 * Requires --yes.
	 *
	 * [--format=<format>]
	 * : One of table (default) or json. Ignored with --refresh or --forget.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt for --forget.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware keys
	 *     wp afterware keys --refresh
	 *     wp afterware keys --forget --yes
	 *
	 * @param string[]            $args       Positional args; unused.
	 * @param array<string,mixed> $assoc_args Associative args: `refresh`, `forget`, `format`, `yes`.
	 * @return void
	 */
	public function keys( $args = array(), $assoc_args = array() ) {
		unset( $args );

		if ( ! empty( $assoc_args['forget'] ) ) {
			$this->forget_keys( $assoc_args );
			return;
		}
		if ( ! empty( $assoc_args['refresh'] ) ) {
			$this->refresh_keys();
			return;
		}
		$this->list_keys( $assoc_args );
	}

	/**
	 * Fetches and verifies the published ring, and reports the reason.
	 *
	 * @return void
	 */
	private function refresh_keys() {
		$result = Key_Ring_Fetch::refresh();
		if ( ! $result['ok'] ) {
			$this->output->error( $result['reason'] );
			return;
		}
		$this->output->success( $result['reason'] );
	}

	/**
	 * Forgets every fetched key, once confirmed.
	 *
	 * @param array<string,mixed> $assoc_args Associative args: `yes`.
	 * @return void
	 */
	private function forget_keys( array $assoc_args ) {
		if ( null === Key_Ring_Store::read() ) {
			$this->output->line( __( 'Nothing has been fetched; there is nothing to forget.', 'afterware' ) );
			return;
		}
		// A stored ring that no longer verifies against a pinned key names no
		// keys to list, but it is still a row worth clearing.
		$ring = Key_Ring_Store::entries( Key_Ring::pinned() );

		$this->output->confirm(
			__( 'Forget every fetched signing key and rely on only the pinned keys until the next refresh?', 'afterware' ),
			! empty( $assoc_args['yes'] )
		);

		$ids = array_keys( $ring );
		Key_Ring_Store::forget();

		$message = ( array() === $ids )
			? __( 'Forgot the stored key ring.', 'afterware' )
			: sprintf(
				/* translators: %s: comma-separated key ids that were forgotten. */
				__( 'Forgot %s.', 'afterware' ),
				implode( ', ', $ids )
			);
		// The one deliberate "undo a trust decision" this plugin offers, so it
		// gets the same audit trail every other consequential change leaves,
		// same as a ring being accepted or refused (Key_Ring_Fetch::refresh()).
		Activity::log( 'key_ring_forgotten', null, array( 'ids' => $ids ), $message );
		$this->output->success( $message );
	}

	/**
	 * Lists every key this site knows about.
	 *
	 * @param array<string,mixed> $assoc_args Associative args: `format`.
	 * @return void
	 */
	private function list_keys( array $assoc_args ) {
		$rows = Key_Ring::describe();

		if ( isset( $assoc_args['format'] ) && 'json' === $assoc_args['format'] ) {
			$this->output->line( (string) wp_json_encode( $rows ) );
			return;
		}

		// Keys that cannot sign anything today sort last, so the live ones read
		// as the answer to "what does this site trust" and the rest as history.
		usort(
			$rows,
			static function ( array $a, array $b ) {
				return ( $a['trusted'] === $b['trusted'] ) ? 0 : ( $a['trusted'] ? -1 : 1 );
			}
		);

		$this->output->table(
			array_map( array( $this, 'key_row' ), $rows ),
			array( 'id', 'status', 'trusted', 'source', 'valid_from', 'valid_until' )
		);
	}

	/**
	 * One Key_Ring::describe() row, dates read out for a table rather than
	 * left as Unix time.
	 *
	 * @param array{id:string,status:string,source:string,valid_from:string|null,valid_until:string|null,trusted:bool} $row One described key.
	 * @return array<string,string>
	 */
	private function key_row( array $row ) {
		return array(
			'id'          => $row['id'],
			'status'      => $row['status'],
			// The ring's own word for a key and whether this site may use it
			// today are different questions: an `active` key whose window has
			// closed answers yes to the first and no to the second.
			'trusted'     => $row['trusted'] ? __( 'yes', 'afterware' ) : __( 'no', 'afterware' ),
			'source'      => $row['source'],
			'valid_from'  => ( null === $row['valid_from'] )
				? __( 'always', 'afterware' )
				: Key_Ring_Parser::moment_text( $row['valid_from'] ),
			'valid_until' => null === $row['valid_until']
				? __( 'no end date', 'afterware' )
				: Key_Ring_Parser::moment_text( $row['valid_until'] ),
		);
	}
}
