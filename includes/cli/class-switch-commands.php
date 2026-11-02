<?php
/**
 * The `switch` and `revert` wp-cli commands.
 *
 * @package Afterware
 */

namespace Afterware\CLI;

use Afterware\Inventory;
use Afterware\Switcher;

/**
 * Switching a plugin to its Afterware build, and reverting it, from the CLI.
 *
 * Neither asks for a site key. A revert restores a backup that is already on
 * the disk, and it is the command someone runs over SSH when a switch has left
 * a site broken — quite possibly the same site whose key is gone. A switch
 * works from a manifest this site has already fetched and verified: the
 * signature is checked again before anything is installed, and being offline
 * today says nothing about whether those bytes are good. `Connection` refuses
 * the rescan afterwards on its own.
 *
 * Split out of Commands to keep that file a manageable size. Both methods
 * assume a `$this->output` (an Output) from the class that uses this trait.
 */
trait Switch_Commands {

	/**
	 * Switches a plugin to its Afterware build.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin slug.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware switch demo-plugin --yes
	 *
	 * @param string[]            $args       Positional args: the plugin slug.
	 * @param array<string,mixed> $assoc_args Associative args: `yes`.
	 * @return void
	 */
	public function switch( $args, $assoc_args = array() ) {
		$slug = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $slug ) {
			$this->output->error( __( 'Usage: wp afterware switch <slug> [--yes]', 'afterware' ) );
			return;
		}

		$allowed = Switcher::can_switch( $slug );
		if ( is_wp_error( $allowed ) ) {
			$this->output->error( $allowed->get_error_message() );
			return;
		}

		$this->output->confirm(
			sprintf(
				/* translators: %s: plugin name. */
				__( 'Switch %s to the Afterware build?', 'afterware' ),
				Inventory::name_for( $slug )
			),
			! empty( $assoc_args['yes'] )
		);

		$result = Switcher::switch_to_afterware( $slug );
		if ( is_wp_error( $result ) ) {
			$this->output->error( $result->get_error_message() );
			return;
		}

		$this->output->success(
			sprintf(
				/* translators: 1: plugin name, 2: previous version, 3: new version, 4: backup path. */
				__( '%1$s switched from %2$s to %3$s. Backup: %4$s', 'afterware' ),
				Inventory::name_for( $slug ),
				$result['from'],
				$result['to'],
				$result['backup']
			)
		);
	}

	/**
	 * Reverts a plugin from its Afterware build to the original.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Plugin slug.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware revert demo-plugin --yes
	 *
	 * @param string[]            $args       Positional args: the plugin slug.
	 * @param array<string,mixed> $assoc_args Associative args: `yes`.
	 * @return void
	 */
	public function revert( $args, $assoc_args = array() ) {
		$slug = isset( $args[0] ) ? (string) $args[0] : '';
		if ( '' === $slug ) {
			$this->output->error( __( 'Usage: wp afterware revert <slug> [--yes]', 'afterware' ) );
			return;
		}

		$allowed = Switcher::can_revert( $slug );
		if ( is_wp_error( $allowed ) ) {
			$this->output->error( $allowed->get_error_message() );
			return;
		}

		$this->output->confirm(
			sprintf(
				/* translators: %s: plugin name. */
				__( 'Revert %s to its original version?', 'afterware' ),
				Inventory::name_for( $slug )
			),
			! empty( $assoc_args['yes'] )
		);

		$result = Switcher::revert( $slug );
		if ( is_wp_error( $result ) ) {
			$this->output->error( $result->get_error_message() );
			return;
		}

		$this->output->success(
			sprintf(
				/* translators: 1: plugin name, 2: previous (Afterware) version, 3: restored version, 4: backup path. */
				__( '%1$s reverted from %2$s to %3$s. Backup kept at: %4$s', 'afterware' ),
				Inventory::name_for( $slug ),
				$result['from'],
				$result['to'],
				$result['backup']
			)
		);
	}
}
