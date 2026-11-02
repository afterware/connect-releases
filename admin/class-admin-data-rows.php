<?php
/**
 * One row of the overview table, per plugin the last scan reported.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Inventory;
use Afterware\Key_Ring;
use Afterware\Manifest_Cache;
use Afterware\Runtime_Requirements;
use Afterware\Switch_Record;
use Afterware\Switch_Version;
use Afterware\Update_Access;

/**
 * Turns the stored report into table rows, overlaid with what is true on this
 * site right now.
 *
 * The report is at most a day old; a switch takes seconds. So wherever the two
 * disagree the site wins: the installed version is read off the disk and the
 * chip comes from the switch record, which means a plugin someone moved a
 * moment ago never spends the rest of the day being offered a switch it has
 * already taken. Split out of Admin_Data because that is a screen's worth of
 * decisions on its own.
 */
trait Admin_Data_Rows {

	/**
	 * One row per plugin in the report.
	 *
	 * @param array<string,mixed> $report The stored risk report.
	 * @return array<int,array<string,mixed>>
	 */
	private static function rows( array $report ) {
		$plugins = ( isset( $report['plugins'] ) && is_array( $report['plugins'] ) ) ? $report['plugins'] : array();

		// Both are one option read and one key-ring build for the whole table.
		$records    = Switch_Record::all();
		$can_verify = array() !== Key_Ring::trusted_at( time() );

		$rows = array();
		foreach ( $plugins as $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['slug'] ) ) {
				continue;
			}
			$slug      = (string) $entry['slug'];
			$catalog   = isset( $entry['catalog'] ) ? (string) $entry['catalog'] : 'unknown';
			$afterware = ( isset( $entry['afterware'] ) && is_array( $entry['afterware'] ) ) ? $entry['afterware'] : array();
			$record    = isset( $records[ $slug ] ) ? $records[ $slug ] : null;
			$pending   = ( null !== $record ) && ( $record['pending'] || null !== $record['failed_at'] );

			$can_switch    = $can_verify && null === Update_Access::error()
				&& null === $record
				&& ! empty( $afterware['switch_available'] );
			$latest        = self::latest( $slug, $catalog, $afterware );
			$file          = Inventory::file_for( $slug );
			$older         = $can_switch && null !== $file && null !== Switch_Version::error( $file, $latest['text'] );
			$runtime_error = $can_switch && null !== Runtime_Requirements::for_latest( Manifest_Cache::peek( $slug ) );
			$can_switch    = $can_switch && ! $older && ! $runtime_error;

			$rows[] = array(
				'slug'       => $slug,
				'name'       => Inventory::name_for( $slug ),
				'installed'  => self::installed_version( $slug, $entry ),
				'chip'       => ( $older || $runtime_error ) ? array(
					'tone'  => 'muted',
					'label' => $older ? __( 'Newer version installed', 'afterware' ) : __( 'Requirements need review', 'afterware' ),
				) : self::row_chip( $catalog, $afterware, $record, $pending, $can_verify ),
				'latest'     => $latest,
				'can_switch' => $can_switch,
				'url'        => Admin::page_url( Admin::PAGE . '-plugin', array( 'slug' => $slug ) ),
				'filters'    => self::filters_for( $catalog, $can_switch ),
			);
		}
		return $rows;
	}

	/**
	 * The version on disk, falling back to the one the report carried for a
	 * plugin this site no longer has.
	 *
	 * @param string              $slug  Plugin slug.
	 * @param array<string,mixed> $entry The report's entry for it.
	 * @return string
	 */
	private static function installed_version( $slug, array $entry ) {
		$file      = Inventory::file_for( $slug );
		$installed = null === $file ? '' : Switch_Version::installed( $file );
		if ( '' !== $installed ) {
			return $installed;
		}
		return isset( $entry['installed_version'] ) ? (string) $entry['installed_version'] : '';
	}

	/**
	 * The chip a row wears: this site's own answer first, the report's second.
	 *
	 * @param string                   $catalog    The report's catalog value.
	 * @param array<string,mixed>      $afterware  The report's afterware block.
	 * @param array<string,mixed>|null $record     The switch record, or null.
	 * @param bool                     $pending    Whether a switch never finished.
	 * @param bool                     $can_verify Whether this build has any signing keys.
	 * @return array{tone:string,label:string}
	 */
	private static function row_chip( $catalog, array $afterware, $record, $pending, $can_verify ) {
		if ( $pending ) {
			return array(
				'tone'  => 'danger',
				'label' => __( 'Switch unfinished', 'afterware' ),
			);
		}
		if ( null !== $record ) {
			return array(
				'tone'  => 'ok',
				'label' => __( 'On Afterware', 'afterware' ),
			);
		}
		if ( ! $can_verify && 'stewarded' === $catalog ) {
			// Nothing this build fetches can be checked, so nothing it could
			// say about a build on offer would mean anything.
			return array(
				'tone'  => 'muted',
				'label' => __( 'Verification unavailable', 'afterware' ),
			);
		}
		if ( 'stewarded' === $catalog && null !== Update_Access::error() ) {
			return array(
				'tone'  => 'warn',
				'label' => __( 'Updates paused', 'afterware' ),
			);
		}
		return self::catalog_chip( $catalog, $afterware );
	}

	/**
	 * The chip the report alone would give a row.
	 *
	 * @param string              $catalog   The report's catalog value.
	 * @param array<string,mixed> $afterware The report's afterware block.
	 * @return array{tone:string,label:string}
	 */
	private static function catalog_chip( $catalog, array $afterware ) {
		if ( ! empty( $afterware['on_afterware'] ) ) {
			return array(
				'tone'  => 'ok',
				'label' => __( 'On Afterware', 'afterware' ),
			);
		}
		if ( ! empty( $afterware['switch_available'] ) ) {
			return array(
				'tone'  => 'warn',
				'label' => __( 'Switch available', 'afterware' ),
			);
		}
		if ( 'stewarded' === $catalog ) {
			return array(
				'tone'  => 'info',
				'label' => __( 'Stewarded', 'afterware' ),
			);
		}
		if ( 'known' === $catalog ) {
			return array(
				'tone'  => 'muted',
				'label' => __( 'Known', 'afterware' ),
			);
		}
		return array(
			'tone'  => 'muted',
			'label' => __( 'Unknown', 'afterware' ),
		);
	}

	/**
	 * The "Afterware latest" cell.
	 *
	 * The report is asked first, because it is what the scan already brought
	 * back and reading it costs nothing; a verified manifest that happens to
	 * be cached is the fallback. Neither goes near the network — this runs
	 * once per row while the page is being drawn, and a site owner opening
	 * the overview has not asked Afterware for anything.
	 *
	 * @param string              $slug      Plugin slug.
	 * @param string              $catalog   The report's catalog value.
	 * @param array<string,mixed> $afterware The report's afterware block.
	 * @return array{text:string,note:string,title:string}
	 */
	private static function latest( $slug, $catalog, array $afterware ) {
		if ( 'stewarded' !== $catalog ) {
			return array(
				'text'  => '—',
				'note'  => '',
				'title' => __( 'Afterware does not maintain a build of this plugin.', 'afterware' ),
			);
		}

		$latest = ( isset( $afterware['latest'] ) && is_string( $afterware['latest'] ) && '' !== $afterware['latest'] )
			? $afterware['latest']
			: null;

		if ( null === $latest ) {
			$manifest = Manifest_Cache::peek( $slug );
			if ( is_array( $manifest ) && ! empty( $manifest['latest'] ) ) {
				$latest = (string) $manifest['latest'];
			}
		}

		if ( null === $latest ) {
			return array(
				'text'  => __( 'Unavailable', 'afterware' ),
				'note'  => __( 'the last scan did not say, and no verified list of builds is stored', 'afterware' ),
				'title' => '',
			);
		}

		return array(
			'text'  => $latest,
			'note'  => '',
			'title' => '',
		);
	}

	/**
	 * Which cards a row belongs to. "Switch available" is the row's own
	 * answer, not the report's, so a card never counts a plugin the table
	 * beside it offers no switch for.
	 *
	 * @param string $catalog    The report's catalog value.
	 * @param bool   $can_switch Whether this row offers a switch.
	 * @return array<int,string>
	 */
	private static function filters_for( $catalog, $can_switch ) {
		$filters = array();
		if ( 'stewarded' === $catalog ) {
			$filters[] = 'stewarded';
		}
		if ( $can_switch ) {
			$filters[] = 'switch-available';
		}
		if ( 'stewarded' !== $catalog && 'known' !== $catalog ) {
			$filters[] = 'unknown';
		}
		return $filters;
	}
}
