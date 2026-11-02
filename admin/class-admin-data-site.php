<?php
/**
 * The arrays the settings, tickets and activity screens read.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Activity;
use Afterware\Claim_Code;
use Afterware\Switch_Guard;
use Afterware\Connection;
use Afterware\Debug_Log;
use Afterware\Inventory;
use Afterware\Options;
use Afterware\Tickets;

/**
 * The three screens that describe the site rather than a plugin.
 *
 * Split out of Admin_Data so that class stays about the overview, which is
 * where the report is turned into rows and cards. The one thing these three
 * share is that they say exactly what is true right now — the field list on
 * Settings is the inventory's own, and the activity log is shown as recorded.
 */
trait Admin_Data_Site {

	/**
	 * Everything the settings screen renders.
	 *
	 * @return array<string,mixed>
	 */
	public static function settings() {
		return array(
			'claim'           => Claim_Code::peek(),
			'can_claim'       => current_user_can( 'update_plugins' ) && ! is_wp_error( Switch_Guard::may_manage_plugins() ),
			'connected'       => Connection::is_connected(),
			'site_id'         => (string) Options::get( Options::SITE_ID, '' ),
			'registered_at'   => Options::get( Options::REGISTERED_AT ),
			'share_debug_log' => (bool) Options::setting( 'share_debug_log' ),
			'version'         => AFTERWARE_VERSION,
			'api_host'        => (string) wp_parse_url( AFTERWARE_API_BASE, PHP_URL_HOST ),
			'docs_url'        => 'https://afterware.io/docs/connect/',
			'telemetry'       => self::telemetry(),
			'key_ring'        => self::key_ring(),
		);
	}

	/**
	 * Everything the tickets screen renders.
	 *
	 * @return array<string,mixed>
	 */
	public static function tickets() {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$choices = array();
		foreach ( get_plugins() as $file => $plugin ) {
			$choices[ Inventory::slug_for( (string) $file ) ] = isset( $plugin['Name'] ) ? (string) $plugin['Name'] : (string) $file;
		}
		natcasesort( $choices );

		return array(
			'connected'       => Connection::is_connected(),
			'tickets'         => self::ticket_rows( Tickets::all() ),
			'kinds'           => array_intersect_key(
				Tickets::kind_labels(),
				array_flip( array( 'breakage', 'question' ) )
			),
			'draft'           => self::draft(),
			'plugins'         => $choices,
			'selected'        => Admin::query_arg( 'slug' ),
			'share_debug_log' => (bool) Options::setting( 'share_debug_log' ),
			'log_tail'        => Debug_Log::tail(),
		);
	}

	/**
	 * Adds a kind chip to every stored ticket.
	 *
	 * @param array<int,array<string,mixed>> $tickets Stored tickets, newest first.
	 * @return array<int,array<string,mixed>>
	 */
	private static function ticket_rows( array $tickets ) {
		$labels = Tickets::kind_labels();

		$rows = array();
		foreach ( $tickets as $ticket ) {
			$kind           = isset( $ticket['kind'] ) ? (string) $ticket['kind'] : '';
			$ticket['chip'] = array(
				'tone'   => 'verification_failure' === $kind ? 'danger' : 'info',
				'label'  => isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind,
				'prefix' => _x( 'Kind: ', 'prefix read out before a ticket kind', 'afterware' ),
			);
			$rows[]         = $ticket;
		}
		return $rows;
	}

	/**
	 * The ticket a send or a validation just bounced, so the form can be given
	 * back with the words still in it.
	 *
	 * Read once and then dropped: a draft is what someone was in the middle of
	 * saying, not a saved document, and it must not reappear on a later visit.
	 *
	 * @return array{plugin_slug:string,kind:string,subject:string,body:string,include_log:bool,restored:bool}
	 */
	private static function draft() {
		$key    = 'afterware_ticket_draft_' . get_current_user_id();
		$stored = get_transient( $key );
		if ( is_array( $stored ) ) {
			delete_transient( $key );
		} else {
			$stored = array();
		}

		return array(
			'plugin_slug' => isset( $stored['plugin_slug'] ) ? (string) $stored['plugin_slug'] : '',
			'kind'        => isset( $stored['kind'] ) ? (string) $stored['kind'] : '',
			'subject'     => isset( $stored['subject'] ) ? (string) $stored['subject'] : '',
			'body'        => isset( $stored['body'] ) ? (string) $stored['body'] : '',
			'include_log' => ! empty( $stored['include_log'] ),
			'restored'    => array() !== $stored,
		);
	}

	/**
	 * Everything the activity screen renders.
	 *
	 * @return array<string,mixed>
	 */
	public static function activity() {
		return array( 'entries' => self::described( Activity::all() ) );
	}

	/**
	 * Adds a chip and a sentence to every activity entry.
	 *
	 * @param array<int,array<string,mixed>> $entries Raw log entries.
	 * @return array<int,array<string,mixed>>
	 */
	public static function described( array $entries ) {
		$out = array();
		foreach ( $entries as $entry ) {
			$kind          = isset( $entry['kind'] ) ? (string) $entry['kind'] : '';
			$entry['chip'] = self::kind_chip( $kind );
			$entry['text'] = self::sentence( $entry, $kind );
			$out[]         = $entry;
		}
		return $out;
	}

	/* ------------------------------------------------------------ settings */

	/**
	 * Every field the inventory carries, grouped the way it is sent.
	 *
	 * @return array<int,array{title:string,rows:array<int,array{label:string,value:string}>}>
	 */
	private static function telemetry() {
		return array(
			array(
				'title' => __( 'About this site', 'afterware' ),
				'rows'  => array(
					self::field( __( 'Site URL', 'afterware' ), home_url() ),
					self::field( __( 'WordPress version', 'afterware' ), get_bloginfo( 'version' ) ),
					self::field( __( 'PHP version', 'afterware' ), PHP_VERSION ),
					self::field( __( 'Multisite', 'afterware' ), is_multisite() ? __( 'Yes', 'afterware' ) : __( 'No', 'afterware' ) ),
					self::field( __( 'Locale', 'afterware' ), get_locale() ),
					self::field( __( 'Client version', 'afterware' ), AFTERWARE_VERSION ),
				),
			),
			array(
				'title' => __( 'For each installed plugin', 'afterware' ),
				'rows'  => array(
					self::field( __( 'Slug', 'afterware' ), __( 'The plugin folder name.', 'afterware' ) ),
					self::field( __( 'Version', 'afterware' ), __( 'The version in the plugin header.', 'afterware' ) ),
					self::field( __( 'Active', 'afterware' ), __( 'Whether the plugin is switched on.', 'afterware' ) ),
					self::field( __( 'Main file hash', 'afterware' ), __( 'A SHA-256 of the plugin main file, so Afterware can tell builds apart.', 'afterware' ) ),
					self::field( __( 'Readme hash', 'afterware' ), __( 'A SHA-256 of readme.txt, when the plugin has one.', 'afterware' ) ),
					self::field( __( 'Update URI', 'afterware' ), __( 'The Update URI header, when the plugin has one.', 'afterware' ) ),
				),
			),
			array(
				'title' => __( 'For each installed theme', 'afterware' ),
				'rows'  => array(
					self::field( __( 'Slug', 'afterware' ), __( 'The theme folder name.', 'afterware' ) ),
					self::field( __( 'Version', 'afterware' ), __( 'The version in the theme header.', 'afterware' ) ),
					self::field( __( 'Active', 'afterware' ), __( 'Whether the theme is the one in use.', 'afterware' ) ),
				),
			),
		);
	}

	/**
	 * One telemetry row.
	 *
	 * @param string $label Field name.
	 * @param string $value What is sent for it.
	 * @return array{label:string,value:string}
	 */
	private static function field( $label, $value ) {
		return array(
			'label' => (string) $label,
			'value' => (string) $value,
		);
	}

	/* ------------------------------------------------------------ activity */

	/**
	 * The chip an activity kind wears. The word comes from Activity::labels(),
	 * so nothing on screen is a raw stored value.
	 *
	 * @param string $kind Activity kind, e.g. 'scan' or 'scan.failed'.
	 * @return array{tone:string,label:string,prefix:string}
	 */
	private static function kind_chip( $kind ) {
		$tones  = array(
			'connected'               => 'ok',
			'switched'                => 'ok',
			'updated'                 => 'ok',
			'reverted'                => 'info',
			'scan'                    => 'info',
			'ticket'                  => 'info',
			'inventory.skipped'       => 'info',
			'key_ring_forgotten'      => 'info',
			'disconnected'            => 'muted',
			'verification_failed'     => 'danger',
			'switch_failed'           => 'danger',
			'revert_failed'           => 'danger',
			'reactivation_failed'     => 'danger',
			'scan.failed'             => 'warn',
			'manifest_refresh_failed' => 'warn',
		);
		$labels = Activity::labels();

		return array(
			'tone'   => isset( $tones[ $kind ] ) ? $tones[ $kind ] : 'warn',
			'label'  => isset( $labels[ $kind ] ) ? $labels[ $kind ] : $kind,
			'prefix' => _x( 'Kind: ', 'prefix read out before an activity kind', 'afterware' ),
		);
	}

	/**
	 * The sentence an activity entry reads as: its own message when it has
	 * one, otherwise the version pair or count it recorded.
	 *
	 * @param array<string,mixed> $entry One log entry.
	 * @param string              $kind  Its kind.
	 * @return string
	 */
	private static function sentence( array $entry, $kind ) {
		$message = isset( $entry['message'] ) ? (string) $entry['message'] : '';
		if ( '' !== $message ) {
			return $message;
		}
		$data = ( isset( $entry['data'] ) && is_array( $entry['data'] ) ) ? $entry['data'] : array();

		if ( isset( $data['from'], $data['to'] ) ) {
			return sprintf(
				/* translators: 1: version replaced, 2: version installed. */
				__( 'From %1$s to %2$s.', 'afterware' ),
				(string) $data['from'],
				(string) $data['to']
			);
		}
		if ( 'scan' === $kind && isset( $data['plugin_count'] ) ) {
			return sprintf(
				/* translators: %d: number of plugins in the inventory. */
				_n( 'Reported %d plugin.', 'Reported %d plugins.', (int) $data['plugin_count'], 'afterware' ),
				(int) $data['plugin_count']
			);
		}
		if ( 'ticket' === $kind && isset( $data['id'] ) ) {
			return sprintf(
				/* translators: %s: the ticket reference Afterware issued. */
				__( 'Ticket %s opened.', 'afterware' ),
				(string) $data['id']
			);
		}
		if ( 'connected' === $kind ) {
			return __( 'Connected to Afterware.', 'afterware' );
		}
		if ( 'disconnected' === $kind ) {
			return __( 'Disconnected from Afterware.', 'afterware' );
		}
		return '';
	}
}
