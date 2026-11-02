<?php
/**
 * The arrays the Afterware views read.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Connection;
use Afterware\Report;

/**
 * Turns what the plugin knows into the plain arrays the templates render.
 *
 * Every decision a screen makes — which state it is in, which chip a row
 * wears, which count a card shows — is made here, so a view is only ever a
 * loop and an escape. Nothing in this class writes anything.
 */
final class Admin_Data {

	use Admin_Data_Keys;
	use Admin_Data_Plugin;
	use Admin_Data_Rows;
	use Admin_Data_Site;

	/**
	 * The filters the overview cards and the `?filter=` argument share.
	 *
	 * @var string[]
	 */
	const FILTERS = array( 'stewarded', 'switch-available', 'unknown' );

	/**
	 * Everything the overview screen renders.
	 *
	 * @return array<string,mixed>
	 */
	public static function overview() {
		$report = Report::get();
		$rows   = ( null === $report ) ? array() : self::rows( $report );
		$filter = Admin::query_arg( 'filter' );
		if ( ! in_array( $filter, self::FILTERS, true ) ) {
			$filter = '';
		}

		$counts = self::counts( $rows );

		return array(
			'claim_settings_url' => Admin::page_url( Admin::PAGE . '-settings' ) . '#afterware-account',
			'connected'          => Connection::is_connected(),
			'has_report'         => null !== $report,
			'received_at'        => Report::received_at(),
			'counts'             => $counts,
			'cards'              => self::cards( $counts, $filter ),
			'filter'             => $filter,
			'reset_url'          => Admin::page_url(),
			'rows'               => ( '' === $filter ) ? $rows : self::only( $rows, $filter ),
		);
	}

	/**
	 * The three cards above the table: a count, a sentence, and the link that
	 * narrows the table to it without any JavaScript.
	 *
	 * @param array{stewarded:int,switch_available:int,unknown:int} $counts  Row counts.
	 * @param string                                                $current The filter in force.
	 * @return array<int,array<string,mixed>>
	 */
	private static function cards( array $counts, $current ) {
		$cards = array(
			array( 'stewarded', $counts['stewarded'], __( 'Stewarded', 'afterware' ), __( 'Afterware maintains a build of these.', 'afterware' ) ),
			array( 'switch-available', $counts['switch_available'], __( 'Switch available', 'afterware' ), __( 'You can move these to an Afterware build now.', 'afterware' ) ),
			array( 'unknown', $counts['unknown'], __( 'Unknown to Afterware', 'afterware' ), __( 'Afterware has not catalogued these yet.', 'afterware' ) ),
		);

		$out = array();
		foreach ( $cards as $card ) {
			$out[] = array(
				'filter' => $card[0],
				'count'  => $card[1],
				'title'  => $card[2],
				'blurb'  => $card[3],
				'url'    => Admin::page_url( Admin::PAGE, array( 'filter' => $card[0] ) ),
				'active' => $current === $card[0],
			);
		}
		return $out;
	}

	/**
	 * The three card counts.
	 *
	 * @param array<int,array<string,mixed>> $rows Every row, unfiltered.
	 * @return array{stewarded:int,switch_available:int,unknown:int}
	 */
	private static function counts( array $rows ) {
		return array(
			'stewarded'        => count( self::only( $rows, 'stewarded' ) ),
			'switch_available' => count( self::only( $rows, 'switch-available' ) ),
			'unknown'          => count( self::only( $rows, 'unknown' ) ),
		);
	}

	/**
	 * The rows belonging to one card.
	 *
	 * @param array<int,array<string,mixed>> $rows   Every row.
	 * @param string                         $filter One of self::FILTERS.
	 * @return array<int,array<string,mixed>>
	 */
	private static function only( array $rows, $filter ) {
		return array_values(
			array_filter(
				$rows,
				static function ( array $row ) use ( $filter ) {
					return in_array( $filter, $row['filters'], true );
				}
			)
		);
	}
}
