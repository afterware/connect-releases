<?php
/**
 * The array behind one plugin's screen.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Activity;
use Afterware\Backups;
use Afterware\Inventory;
use Afterware\Key_Ring;
use Afterware\Report;
use Afterware\Switch_Guard;
use Afterware\Switch_Record;
use Afterware\Switch_Version;
use Afterware\Update_Access;

/**
 * Assembles the plugin screen's data.
 *
 * Split out of Admin_Data because this is the screen with the most states to
 * tell apart: not installed, never switched, switched, switched and out of
 * date, and — the one that matters most — a switch that never finished, where
 * the plugin's own files may be gone and the backup is the way back.
 */
trait Admin_Data_Plugin {

	/**
	 * Everything one plugin's screen renders.
	 *
	 * @param string $slug Plugin slug from the query.
	 * @return array<string,mixed>
	 */
	public static function plugin( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$slug   = (string) $slug;
		$file   = Inventory::file_for( $slug );
		$record = Switch_Record::get( $slug );

		$pending   = ( null !== $record ) && ( ! empty( $record['pending'] ) || null !== $record['failed_at'] );
		$installed = ( null === $file ) ? '' : Switch_Version::installed( $file );
		$may       = ! is_wp_error( Switch_Guard::may_manage_plugins() );

		// A build with no pinned signing keys cannot check a signature, so it
		// has nothing to say about a build on offer — including through the
		// report fallback below, which is why this is asked before it.
		$can_verify = array() !== Key_Ring::trusted_at( time() );

		// One look at the cache for both answers: whether to offer the switch,
		// and which version to name on the button.
		$offer         = Switch_Guard::switch_offer( $slug );
		$latest        = $offer['latest'];
		$named         = $can_verify && null === Update_Access::error() ? self::report_latest( $slug ) : null;
		$version_error = null;
		if ( null !== $file && null === $record && $can_verify && null === Update_Access::error() ) {
			$version_error = Switch_Version::error( $file, null === $latest ? $named : $latest );
		}
		if ( null !== $version_error && ( true === $offer['allowed'] || 'afterware_no_build' === $offer['allowed']->get_error_code() ) ) {
			$offer['allowed'] = $version_error;
		}

		$runtime_error = true !== $offer['allowed'] && in_array( $offer['allowed']->get_error_code(), array( 'afterware_runtime_too_old', 'afterware_runtime_unknown' ), true );
		$chip          = self::plugin_chip( $record, $pending, null === $latest ? $named : $latest, $can_verify );
		if ( null !== $version_error || $runtime_error ) {
			$chip = array(
				'tone'  => 'muted',
				'label' => null !== $version_error ? __( 'Newer version installed', 'afterware' ) : __( 'Requirements need review', 'afterware' ),
			);
		}

		return array(
			'slug'             => $slug,
			'can_verify'       => $can_verify,
			'report_latest'    => $named,
			'name'             => Inventory::name_for( $slug ),
			'installed'        => $installed,
			'is_installed'     => null !== $file,
			'known'            => ( null !== $file ) || ( null !== $record ),
			'record'           => $record,
			'on_afterware'     => ( null !== $record ) && ! $pending,
			'pending'          => $pending,
			'latest'           => $latest,
			'may_manage'       => $may,
			'can_switch'       => $offer['allowed'],
			'show_switch_form' => null === $version_error && ! $runtime_error,
			'can_revert'       => ( null === $record ) ? null : Switch_Guard::can_revert( $slug ),
			'backup_location'  => Backups::location(),
			'backup_path'      => Backups::path( $slug ),
			'update_available' => self::update_available( $record, $latest, $installed ),
			'updates_url'      => self_admin_url( 'update-core.php' ),
			'chip'             => $chip,
			'activity'         => self::described( Activity::for_slug( $slug ) ),
		);
	}

	/**
	 * The build the last scan said was on offer, when it said one was.
	 *
	 * The guard only trusts a manifest this site has fetched and verified, and
	 * a screen may not go and fetch one. Between connecting and the first
	 * update check that is nobody's fault and would leave the screen saying
	 * "no build yet" about a plugin the overview has just called switchable.
	 * This is what the report claimed, offered to the view so it can say so
	 * plainly — the switch still refreshes and re-verifies before it acts.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|null
	 */
	private static function report_latest( $slug ) {
		$entry = Report::plugin( $slug );
		if ( null === $entry || empty( $entry['afterware']['switch_available'] ) ) {
			return null;
		}
		$latest = isset( $entry['afterware']['latest'] ) ? $entry['afterware']['latest'] : null;

		return ( is_string( $latest ) && '' !== $latest ) ? $latest : null;
	}

	/**
	 * Whether a plugin already on Afterware has a newer build waiting.
	 *
	 * @param array<string,mixed>|null $record    The switch record, or null.
	 * @param string|null              $latest    The version the manifest offers.
	 * @param string                   $installed The version on disk.
	 * @return bool
	 */
	private static function update_available( $record, $latest, $installed ) {
		if ( null === $record || null === $latest || '' === $installed ) {
			return false;
		}
		return version_compare( $latest, $installed, '>' );
	}

	/**
	 * The chip in the plugin screen's header, from what is true on this site
	 * rather than from the last report: a plugin can be switched between two
	 * scans.
	 *
	 * @param array<string,mixed>|null $record     The switch record, or null.
	 * @param bool                     $pending    Whether a switch never finished.
	 * @param string|null              $latest     The version on offer, verified or merely reported.
	 * @param bool                     $can_verify Whether this build has any signing keys.
	 * @return array{tone:string,label:string}
	 */
	private static function plugin_chip( $record, $pending, $latest, $can_verify ) {
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
		if ( ! $can_verify ) {
			return array(
				'tone'  => 'muted',
				'label' => __( 'Verification unavailable', 'afterware' ),
			);
		}
		if ( null !== Update_Access::error() ) {
			return array(
				'tone'  => 'warn',
				'label' => __( 'Updates paused', 'afterware' ),
			);
		}
		if ( null !== $latest ) {
			return array(
				'tone'  => 'warn',
				'label' => __( 'Switch available', 'afterware' ),
			);
		}
		return array(
			'tone'  => 'muted',
			'label' => __( 'Upstream copy', 'afterware' ),
		);
	}
}
