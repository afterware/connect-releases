<?php
/**
 * `wp afterware ...`
 *
 * @package Afterware
 */

namespace Afterware\CLI;

use Afterware\Connection;
use Afterware\Inventory;
use Afterware\Report;
use Afterware\Switch_Record;

/**
 * The `wp afterware` command family: status, scan, switch, revert, connect,
 * and opening or listing support tickets.
 *
 * Every method talks only to `$this->output` (never `\WP_CLI` directly), so
 * this whole class runs under PHPUnit, where the real WP_CLI class is not
 * loaded, given a test Output. Switching/reverting and the support commands
 * (connect, tickets, ticket) live in Switch_Commands and Support_Commands,
 * kept separate so no one file grows unwieldy.
 */
final class Commands {

	use Switch_Commands;
	use Support_Commands;
	use Keys_Command;

	/**
	 * @var Output
	 */
	private $output;

	/**
	 * @param Output|null $output Where to send messages; defaults to the real WP-CLI.
	 */
	public function __construct( Output $output = null ) {
		$this->output = ( $output instanceof Output ) ? $output : new WP_CLI_Output();
	}

	/**
	 * Shows whether this site is connected, when it last scanned, and the
	 * plugins Afterware manages.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware status
	 *
	 * @param string[]            $args       Positional args; unused.
	 * @param array<string,mixed> $assoc_args Associative args; unused.
	 * @return void
	 */
	public function status( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		if ( ! Connection::is_connected() ) {
			$this->output->warning( __( 'Not connected. Run `wp afterware connect --key=<site key>`.', 'afterware' ) );
			return;
		}
		$this->output->success( __( 'Connected to Afterware.', 'afterware' ) );

		$received_at = Report::received_at();
		$this->output->line(
			null === $received_at
				? __( 'No scan has run yet.', 'afterware' )
				: sprintf(
					/* translators: %s: date and time of the last scan, UTC. */
					__( 'Last scan: %s UTC', 'afterware' ),
					gmdate( 'Y-m-d H:i:s', $received_at )
				)
		);

		$records = Switch_Record::all();
		$this->output->table( $this->status_rows( $records ), array( 'slug', 'version', 'on_afterware' ) );
		$this->report_unfinished( $records );
	}

	/**
	 * One row per plugin Afterware has anything to do with here.
	 *
	 * Built from what is installed *and* from the switch records, because the
	 * two can disagree in exactly the case worth seeing: a switch that died
	 * between clearing the plugin directory and writing the new files leaves a
	 * record and no plugin, and a table built from get_plugins() alone would
	 * quietly leave that site's worst problem out of `wp afterware status`.
	 *
	 * @param array<string,array<string,mixed>> $records Every switch record.
	 * @return array<int,array<string,string>>
	 */
	private function status_rows( array $records ) {
		$rows = array();
		foreach ( Inventory::our_plugins() as $file => $slug ) {
			$data          = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
			$rows[ $slug ] = array(
				'slug'         => $slug,
				'version'      => (string) $data['Version'],
				'on_afterware' => isset( $records[ $slug ] ) ? 'yes' : 'no',
			);
		}

		foreach ( array_keys( $records ) as $slug ) {
			if ( isset( $rows[ $slug ] ) ) {
				continue;
			}
			$rows[ $slug ] = array(
				'slug'         => $slug,
				'version'      => __( 'missing on disk', 'afterware' ),
				'on_afterware' => 'yes',
			);
		}

		return array_values( $rows );
	}

	/**
	 * Says so, under the table, for every switch that never finished.
	 *
	 * A row that reads "on_afterware: yes" is not the whole truth when the
	 * install stopped half way; these are the lines that tell someone there is
	 * a revert waiting to be run.
	 *
	 * @param array<string,array<string,mixed>> $records Every switch record.
	 * @return void
	 */
	private function report_unfinished( array $records ) {
		foreach ( $records as $slug => $record ) {
			if ( null !== $record['failed_at'] ) {
				$this->output->line(
					sprintf(
						/* translators: 1: plugin slug, 2: error code the install failed with. */
						__( '%1$s — switch: failed (%2$s)', 'afterware' ),
						$slug,
						'' === $record['error'] ? __( 'reason unrecorded', 'afterware' ) : $record['error']
					)
				);
				continue;
			}
			if ( ! $record['pending'] ) {
				continue;
			}
			$this->output->line(
				null === $record['started_at']
					? sprintf(
						/* translators: %s: plugin slug. */
						__( '%s — switch: pending', 'afterware' ),
						$slug
					)
					: sprintf(
						/* translators: 1: plugin slug, 2: date and time the switch started, UTC. */
						__( '%1$s — switch: pending since %2$s UTC', 'afterware' ),
						$slug,
						gmdate( 'Y-m-d H:i:s', $record['started_at'] )
					)
			);
		}
	}

	/**
	 * Refuses a command that has nothing to talk to, in the words that fix it.
	 *
	 * Only for the commands whose whole job is a request to Afterware: a scan,
	 * and the two ticket commands. `switch` and `revert` work on a
	 * disconnected site and must never be sent through here — a revert in
	 * particular is the recovery path for a site whose key may be exactly what
	 * is missing.
	 *
	 * @return bool Whether this site has a site key; false after reporting it.
	 */
	private function require_connection() {
		if ( Connection::is_connected() ) {
			return true;
		}
		$this->output->error( __( 'Connect this site first (wp afterware connect --key=…)', 'afterware' ) );

		return false;
	}

	/**
	 * Runs a scan and prints the summary and the scanned plugins.
	 *
	 * ## EXAMPLES
	 *
	 *     wp afterware scan
	 *
	 * @param string[]            $args       Positional args; unused.
	 * @param array<string,mixed> $assoc_args Associative args; unused.
	 * @return void
	 */
	public function scan( $args = array(), $assoc_args = array() ) {
		unset( $args, $assoc_args );

		if ( ! $this->require_connection() ) {
			return;
		}

		$result = Connection::scan();
		if ( is_wp_error( $result ) ) {
			$this->output->error( $result->get_error_message() );
			return;
		}

		$summary = ( isset( $result['summary'] ) && is_array( $result['summary'] ) ) ? $result['summary'] : array();
		$this->output->success(
			sprintf(
				/* translators: 1: number of stewarded plugins, 2: number with a switch available. */
				__( 'Scan complete: %1$d stewarded plugin(s), %2$d with a switch available.', 'afterware' ),
				isset( $summary['stewarded'] ) ? (int) $summary['stewarded'] : 0,
				isset( $summary['switch_available'] ) ? (int) $summary['switch_available'] : 0
			)
		);

		$plugins = ( isset( $result['plugins'] ) && is_array( $result['plugins'] ) ) ? $result['plugins'] : array();
		$rows    = array();
		foreach ( $plugins as $entry ) {
			$afterware = ( isset( $entry['afterware'] ) && is_array( $entry['afterware'] ) ) ? $entry['afterware'] : array();
			$rows[]    = array(
				'slug'              => isset( $entry['slug'] ) ? (string) $entry['slug'] : '',
				'installed_version' => isset( $entry['installed_version'] ) ? (string) $entry['installed_version'] : '',
				'upstream_status'   => isset( $entry['upstream_status'] ) ? (string) $entry['upstream_status'] : '',
				'switch_available'  => empty( $afterware['switch_available'] ) ? 'no' : 'yes',
				'on_afterware'      => empty( $afterware['on_afterware'] ) ? 'no' : 'yes',
			);
		}
		$this->output->table( $rows, array( 'slug', 'installed_version', 'upstream_status', 'switch_available', 'on_afterware' ) );
	}
}
