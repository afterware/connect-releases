<?php
/**
 * The admin_post handlers behind every button on the Afterware screens.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Connection;
use Afterware\Key_Ring_Fetch;
use Afterware\Notices;
use Afterware\Options;
use Afterware\Switch_Guard;
use WP_Error;

/**
 * Every state change on the Afterware screens is a POST to admin-post.php
 * that lands here.
 *
 * The shape is the same for all of them: check the nonce, check the
 * capability, do the one thing, then send the browser back to the screen it
 * came from with a key naming what happened. Nothing here renders anything —
 * the notice the site owner reads is composed by Admin_Notices from that key,
 * so a reload never repeats an action and the back button is harmless.
 */
final class Actions {

	use Actions_Claim;
	use Actions_Plugin;
	use Actions_Ticket;

	/**
	 * How long a failed action's own sentence is kept for the redirect that
	 * is about to display it.
	 *
	 * @var int
	 */
	const ERROR_TTL = MINUTE_IN_SECONDS;

	/**
	 * How long a bounced ticket's text is kept for the form to pick back up.
	 * Declared here rather than in Actions_Ticket: PHP 7.4 traits cannot hold
	 * constants.
	 *
	 * @var int
	 */
	const DRAFT_TTL = 10 * MINUTE_IN_SECONDS;

	/**
	 * How long a completed revert is remembered, so its notice can be sure the
	 * revert really happened rather than trusting the URL that carried it.
	 *
	 * @var int
	 */
	const REVERTED_TTL = MINUTE_IN_SECONDS;

	/**
	 * Registers one `admin_post_afterware_<action>` handler per action.
	 *
	 * @return void
	 */
	public static function register_hooks() {
		$handlers = array(
			'claim_code'     => 'claim_code',
			'scan'           => 'scan',
			'switch'         => 'switch_plugin',
			'revert'         => 'revert_plugin',
			'disconnect'     => 'disconnect',
			'retry_register' => 'retry_register',
			'ticket_new'     => 'ticket_new',
			'dismiss_notice' => 'dismiss_notice',
			'save_settings'  => 'save_settings',
			'refresh_keys'   => 'refresh_keys',
		);
		foreach ( $handlers as $action => $method ) {
			add_action( 'admin_post_afterware_' . $action, array( self::class, $method ) );
		}
	}

	/* ------------------------------------------------------------ handlers */

	/**
	 * Runs a scan and returns to the overview.
	 *
	 * @return void
	 */
	public static function scan() {
		self::guard( 'scan' );

		$result = Connection::scan();
		if ( is_wp_error( $result ) ) {
			Notices::add( 'scan', 'error', $result->get_error_message() );
			self::back( Admin::PAGE );
		}
		self::back( Admin::PAGE, 'scanned' );
	}

	/**
	 * Registers this site, from the "Try again" button on the connection
	 * notice.
	 *
	 * @return void
	 */
	public static function retry_register() {
		self::guard( 'retry_register' );

		if ( ! Connection::is_connected() ) {
			$result = Connection::register();
			if ( is_wp_error( $result ) ) {
				Notices::add( 'connection', 'error', $result->get_error_message(), true );
				self::back( Admin::PAGE );
			}
		}
		Notices::remove( 'connection' );
		self::back( Admin::PAGE, 'registered' );
	}

	/**
	 * Forgets the site key, once the word is typed out.
	 *
	 * @return void
	 */
	public static function disconnect() {
		self::guard( 'disconnect' );

		if ( is_wp_error( Switch_Guard::may_manage_plugins() ) ) {
			self::back( Admin::PAGE . '-settings', 'not-allowed' );
		}
		if ( 'disconnect' !== self::post_field( 'confirm' ) ) {
			self::back( Admin::PAGE . '-settings', 'confirm-mismatch' );
		}

		Connection::disconnect();
		self::back( Admin::PAGE . '-settings', 'disconnected' );
	}

	/**
	 * Stores the settings form. An unticked checkbox posts nothing, which has
	 * to mean off rather than "leave it alone", so the value is always
	 * written.
	 *
	 * @return void
	 */
	public static function save_settings() {
		self::guard( 'save_settings' );

		Options::set_setting( 'share_debug_log', '' !== self::post_field( 'share_debug_log' ) );
		self::back( Admin::PAGE . '-settings', 'settings-saved' );
	}

	/**
	 * Fetches and verifies the published key ring, and carries the reason
	 * back for the settings screen's flash message.
	 *
	 * The reason is not one of a fixed set of sentences the way most notice
	 * keys are, so it travels the same way a failed switch's reason does: in
	 * a short-lived transient, not the URL.
	 *
	 * @return void
	 */
	public static function refresh_keys() {
		self::guard( 'refresh_keys' );

		$result = Key_Ring_Fetch::refresh();
		set_transient(
			'afterware_keys_refresh_' . get_current_user_id(),
			array(
				'ok'     => $result['ok'],
				'reason' => $result['reason'],
			),
			self::ERROR_TTL
		);

		self::back( Admin::PAGE . '-settings', 'keys-refreshed' );
	}

	/**
	 * Removes one persistent notice and returns to the screen it was on.
	 *
	 * @return void
	 */
	public static function dismiss_notice() {
		self::guard( 'dismiss_notice' );

		$key = self::post_field( 'key' );
		if ( '' !== $key ) {
			Notices::remove( $key );
		}

		$referer = wp_get_referer();
		self::redirect(
			is_string( $referer ) && '' !== $referer
				? remove_query_arg( 'afterware-notice', $referer )
				: Admin::page_url()
		);
	}

	/* ------------------------------------------------------------ plumbing */

	/**
	 * Ends the request on the screen named, with a key saying what happened.
	 *
	 * @param string               $page One of Admin's page slugs.
	 * @param string               $key  Notice key, or '' for none.
	 * @param array<string,string> $args Extra query arguments.
	 * @return void
	 */
	private static function back( $page, $key = '', array $args = array() ) {
		if ( '' !== $key ) {
			$args['afterware-notice'] = $key;
		}
		self::redirect( Admin::page_url( $page, $args ) );
	}

	/**
	 * Sends the browser on and ends the request.
	 *
	 * The `afterware_admin_redirect` filter is the seam the tests use: there
	 * is no request to end under PHPUnit, so they filter it to throw and
	 * inspect the URL a handler chose.
	 *
	 * @param string $url Where to go.
	 * @return void
	 */
	public static function redirect( $url ) {
		/**
		 * Filters the URL an Afterware admin action redirects to.
		 *
		 * @param string $url The URL the handler chose.
		 */
		$url = (string) apply_filters( 'afterware_admin_redirect', (string) $url );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Refuses the request unless it carries this action's nonce and comes
	 * from someone who can see these screens at all.
	 *
	 * @param string $action Action name without the `afterware_` prefix.
	 * @return void
	 */
	private static function guard( $action ) {
		check_admin_referer( 'afterware_' . $action, '_wpnonce_afterware_' . $action );

		if ( ! current_user_can( Admin::CAPABILITY ) ) {
			wp_die(
				esc_html__( 'You do not have permission to manage Afterware on this site.', 'afterware' ),
				esc_html__( 'Afterware', 'afterware' ),
				array( 'response' => 403 )
			);
		}
	}

	/**
	 * Keeps a failed action's own sentence where the next request's notice
	 * can read it: the redirect carries a code, not a message.
	 *
	 * @param WP_Error $error The error the action failed with.
	 * @return void
	 */
	private static function remember_error( WP_Error $error ) {
		set_transient( 'afterware_action_error_' . get_current_user_id(), $error->get_error_message(), self::ERROR_TTL );
	}

	/**
	 * A single-line posted field. Callers run after guard(), which has
	 * already checked this request's nonce.
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function post_field( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer() before any caller reads a field.
		return isset( $_POST[ $name ] ) ? sanitize_text_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * A posted textarea, newlines kept. Same nonce reasoning as post_field().
	 *
	 * @param string $name Field name.
	 * @return string
	 */
	private static function post_text( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- guard() ran check_admin_referer() before any caller reads a field.
		return isset( $_POST[ $name ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $name ] ) ) : '';
	}
}
