<?php
/**
 * Stores and reads the last risk report received from Afterware.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Wraps the report payload from POST /v1/inventory or GET /v1/report with the
 * local time it was received.
 */
final class Report {

	/**
	 * Stores a freshly received risk report, replacing any previous one.
	 *
	 * @param array<string,mixed> $report The risk report as returned by the API.
	 * @return void
	 */
	public static function store( array $report ) {
		Options::set(
			Options::REPORT,
			array(
				'report'      => $report,
				'received_at' => time(),
			)
		);
	}

	/**
	 * The last stored report, or null if none has been received yet.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get() {
		$stored = Options::get( Options::REPORT );
		if ( ! is_array( $stored ) || ! isset( $stored['report'] ) || ! is_array( $stored['report'] ) ) {
			return null;
		}
		return $stored['report'];
	}

	/**
	 * Unix timestamp the last report was received at, or null if none yet.
	 *
	 * @return int|null
	 */
	public static function received_at() {
		$stored = Options::get( Options::REPORT );
		if ( ! is_array( $stored ) || ! isset( $stored['received_at'] ) ) {
			return null;
		}
		return (int) $stored['received_at'];
	}

	/**
	 * A single plugin's entry from the last report, or null if not found.
	 *
	 * @param string $slug Plugin slug.
	 * @return array<string,mixed>|null
	 */
	public static function plugin( $slug ) {
		$report = self::get();
		if ( null === $report || empty( $report['plugins'] ) || ! is_array( $report['plugins'] ) ) {
			return null;
		}
		foreach ( $report['plugins'] as $entry ) {
			if ( isset( $entry['slug'] ) && $entry['slug'] === $slug ) {
				return $entry;
			}
		}
		return null;
	}
}
