<?php
/**
 * The option behind the record of switched plugins.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Reads and writes `afterware_switched`: slug => the version Afterware
 * replaced, where the backup of it went and what that backup hashed to, and
 * how the switch ended.
 *
 * The record is written *before* the install, marked `pending`, and completed
 * afterwards. That order is the point: if PHP dies between clearing the plugin
 * directory and writing the new files, the site is left without the plugin, and
 * the only thing that can bring it back is a record naming the backup. A record
 * that is never completed is worth far more than no record at all.
 *
 * Storage only. Whether a plugin may be switched or reverted is Switch_Guard's
 * decision; this class just remembers what happened.
 */
final class Switch_Record {

	/**
	 * Every well-formed record, slug => entry.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function all() {
		$switched = Options::get( Options::SWITCHED, array() );
		if ( ! is_array( $switched ) ) {
			return array();
		}

		$out = array();
		foreach ( $switched as $slug => $entry ) {
			if ( ! is_array( $entry ) || ! isset( $entry['upstream_version'], $entry['backup'] ) ) {
				continue;
			}
			$out[ (string) $slug ] = array(
				'upstream_version' => (string) $entry['upstream_version'],
				'plugin_file'      => isset( $entry['plugin_file'] ) ? (string) $entry['plugin_file'] : '',
				'was_active'       => ! empty( $entry['was_active'] ),
				'backup'           => (string) $entry['backup'],
				'backup_sha256'    => isset( $entry['backup_sha256'] ) ? (string) $entry['backup_sha256'] : '',
				'started_at'       => isset( $entry['started_at'] ) ? (int) $entry['started_at'] : null,
				'switched_at'      => isset( $entry['switched_at'] ) && null !== $entry['switched_at'] ? (int) $entry['switched_at'] : null,
				'pending'          => ! empty( $entry['pending'] ),
				'failed_at'        => isset( $entry['failed_at'] ) ? (int) $entry['failed_at'] : null,
				'error'            => isset( $entry['error'] ) ? (string) $entry['error'] : '',
			);
		}
		return $out;
	}

	/**
	 * The record for a slug, or null when there is none.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public static function get( $slug ) {
		$all = self::all();
		return isset( $all[ (string) $slug ] ) ? $all[ (string) $slug ] : null;
	}

	/**
	 * Opens a record before the install starts. Nothing is claimed to have
	 * happened yet: `switched_at` is null and `pending` is true until complete()
	 * says otherwise.
	 *
	 * `plugin_file` and `was_active` are written down here rather than looked up
	 * at revert time on purpose. After a half-finished install the plugin
	 * directory is gone, so `get_plugins()` no longer knows the file, and the
	 * plugin has been deactivated: the record is the only place left that
	 * remembers what to restore and whether to switch it back on.
	 *
	 * `started_at` is written here and never rewritten: it is the only answer
	 * to "how long has this said pending?", which is the question anyone
	 * looking at a switch that never finished actually has.
	 *
	 * @param string              $slug   Plugin slug.
	 * @param array<string,mixed> $fields upstream_version, plugin_file, was_active, backup, backup_sha256.
	 * @return void
	 */
	public static function open( $slug, array $fields ) {
		$switched                   = self::stored();
		$switched[ (string) $slug ] = array(
			'upstream_version' => (string) $fields['upstream_version'],
			'plugin_file'      => (string) $fields['plugin_file'],
			'was_active'       => (bool) $fields['was_active'],
			'backup'           => (string) $fields['backup'],
			'backup_sha256'    => (string) $fields['backup_sha256'],
			'started_at'       => time(),
			'switched_at'      => null,
			'pending'          => true,
		);
		Options::set( Options::SWITCHED, $switched );
	}

	/**
	 * Marks a switch as finished.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function complete( $slug ) {
		$switched = self::stored();
		if ( ! isset( $switched[ (string) $slug ] ) ) {
			return;
		}
		$switched[ (string) $slug ]['switched_at'] = time();
		unset( $switched[ (string) $slug ]['pending'], $switched[ (string) $slug ]['failed_at'], $switched[ (string) $slug ]['error'] );
		Options::set( Options::SWITCHED, $switched );
	}

	/**
	 * Marks a switch as failed, keeping everything a revert needs.
	 *
	 * @param string $slug  Plugin slug.
	 * @param string $error Error code, or 'shutdown' when PHP died mid-install.
	 * @return void
	 */
	public static function fail( $slug, $error ) {
		$switched = self::stored();
		if ( ! isset( $switched[ (string) $slug ] ) ) {
			return;
		}
		$switched[ (string) $slug ]['failed_at'] = time();
		$switched[ (string) $slug ]['error']     = (string) $error;
		Options::set( Options::SWITCHED, $switched );
	}

	/**
	 * Drops the record for a plugin that is back on its original build.
	 *
	 * @param string $slug Plugin slug.
	 * @return void
	 */
	public static function forget( $slug ) {
		$switched = self::stored();
		if ( ! isset( $switched[ (string) $slug ] ) ) {
			return;
		}
		unset( $switched[ (string) $slug ] );
		Options::set( Options::SWITCHED, $switched );
	}

	/**
	 * The option as stored, so a partial write never drops fields all() does
	 * not know about.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function stored() {
		$switched = Options::get( Options::SWITCHED, array() );
		if ( ! is_array( $switched ) ) {
			return array();
		}

		$out = array();
		foreach ( $switched as $slug => $entry ) {
			if ( is_array( $entry ) ) {
				$out[ (string) $slug ] = $entry;
			}
		}
		return $out;
	}
}
