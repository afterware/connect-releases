<?php
/**
 * The notices the Afterware screens show.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Backups;
use Afterware\Inventory;
use Afterware\Notices;
use Afterware\Switch_Record;
use Afterware\Tickets;
use WP_Screen;

/**
 * Renders two kinds of notice on our screens and on the plugins screen.
 *
 * The first kind is the one an action just caused: the handler redirected
 * with a key naming what happened, and the sentence for that key is composed
 * here from what is on the site now, so nothing but a fixed key ever travels
 * through the URL. The second kind is the stored notices something raised
 * where there was no screen to write to — a failed verification during cron,
 * an empty key ring during an update check.
 */
final class Admin_Notices {

	/**
	 * Renders everything waiting, once.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! self::on_a_screen_that_shows_notices() ) {
			return;
		}

		self::render_action_notice();

		foreach ( Notices::flush() as $key => $notice ) {
			self::notice( $notice['type'], $notice['message'], $notice['persistent'] ? (string) $key : '' );
		}
	}

	/**
	 * Whether this is a screen our notices belong on: any Afterware screen,
	 * and the plugins screen, where someone who has just seen a plugin behave
	 * oddly is most likely to be standing.
	 *
	 * @return bool
	 */
	private static function on_a_screen_that_shows_notices() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		$id     = ( $screen instanceof WP_Screen ) ? (string) $screen->id : '';

		// The hook suffixes WordPress handed back when the menu was registered
		// are the screen ids, so this is an exact list rather than a guess at
		// what an Afterware screen id looks like.
		return 'plugins' === $id || in_array( $id, Admin::screen_hooks(), true );
	}

	/**
	 * Renders the notice for the `afterware-notice` key a redirect carried.
	 *
	 * @return void
	 */
	private static function render_action_notice() {
		$key = Admin::query_arg( 'afterware-notice' );
		if ( '' === $key ) {
			return;
		}

		$notice = self::action_notice( $key );
		if ( null !== $notice ) {
			self::notice( $notice[0], $notice[1] );
		}
	}

	/**
	 * The type and sentence for an action key, or null when the key is not
	 * one of ours.
	 *
	 * @param string $key The `afterware-notice` value.
	 * @return array{0:string,1:string}|null
	 */
	private static function action_notice( $key ) {
		if ( 1 === preg_match( '/^(switch|revert)-failed:[a-z0-9_]+$/', $key ) ) {
			return array( 'error', self::failure_message( 0 === strpos( $key, 'switch' ) ) );
		}

		switch ( $key ) {
			case 'scanned':
				return array( 'success', __( 'Scan complete.', 'afterware' ) );
			case 'switched':
				return self::switched_message();
			case 'reverted':
				return self::reverted_message();
			case 'registered':
				return array( 'success', __( 'This site is connected to Afterware.', 'afterware' ) );
			case 'disconnected':
				return array( 'success', __( 'This site is no longer connected to Afterware. Plugins already on an Afterware build stay exactly as they are, and you can still revert them.', 'afterware' ) );
			case 'settings-saved':
				return array( 'success', __( 'Settings saved.', 'afterware' ) );
			case 'keys-refreshed':
				return self::keys_refreshed_message();
			case 'ticket-opened':
				return array( 'success', self::ticket_message() );
			case 'confirm-mismatch':
				return array( 'warning', __( 'The confirmation text did not match. Nothing was changed.', 'afterware' ) );
			case 'not-allowed':
				return array( 'error', __( 'Only administrators who can install plugins can do that. Nothing was changed.', 'afterware' ) );
			default:
				return null;
		}
	}

	/**
	 * "{name} is now on the Afterware build {to}", with where the copy of the
	 * old one went — but only once the site itself confirms it happened.
	 *
	 * A URL is not evidence. Someone can bookmark this one, share it, or land
	 * on it again after reverting, and congratulating them on a switch that is
	 * not there would be worse than saying nothing.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function switched_message() {
		$slug = Admin::query_arg( 'slug' );
		if ( null === Switch_Record::get( $slug ) ) {
			return null;
		}
		$backup = Backups::path( $slug );

		return array(
			'success',
			sprintf(
				/* translators: 1: plugin name, 2: version number, 3: absolute path to the backup file. */
				__( '%1$s is now on the Afterware build %2$s. A backup of the upstream copy is kept at %3$s.', 'afterware' ),
				Inventory::name_for( $slug ),
				self::installed_version( $slug ),
				null === $backup ? Backups::location() : $backup
			),
		);
	}

	/**
	 * "{name} is back on the upstream copy {to}."
	 *
	 * The absence of a switch record is not proof a revert just happened — a
	 * plugin that was never switched has no record either — so the action
	 * leaves a short-lived note behind and this looks for both.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function reverted_message() {
		$slug = Admin::query_arg( 'slug' );
		$key  = 'afterware_reverted_' . get_current_user_id();
		if ( null !== Switch_Record::get( $slug ) || get_transient( $key ) !== $slug ) {
			return null;
		}
		delete_transient( $key );

		return array(
			'success',
			sprintf(
				/* translators: 1: plugin name, 2: version number. */
				__( '%1$s is back on the upstream copy %2$s.', 'afterware' ),
				Inventory::name_for( $slug ),
				self::installed_version( $slug )
			),
		);
	}

	/**
	 * The reason a key ring refresh was accepted or refused, kept in a
	 * transient because — unlike every other key here — it is not one of a
	 * fixed set of sentences.
	 *
	 * A URL is not evidence any more than switched_message()'s is: nothing is
	 * shown once the transient the handler left is gone, whether because it
	 * was already read or because the minute it was kept for passed.
	 *
	 * @return array{0:string,1:string}|null
	 */
	private static function keys_refreshed_message() {
		$key    = 'afterware_keys_refresh_' . get_current_user_id();
		$result = get_transient( $key );
		if ( ! is_array( $result ) || ! isset( $result['ok'], $result['reason'] ) ) {
			return null;
		}
		delete_transient( $key );

		return array( $result['ok'] ? 'success' : 'error', (string) $result['reason'] );
	}

	/**
	 * "Ticket opened. Reference: {id}."
	 *
	 * @return string
	 */
	private static function ticket_message() {
		$tickets = Tickets::all();
		if ( array() === $tickets || ! isset( $tickets[0]['id'] ) ) {
			return __( 'Ticket opened.', 'afterware' );
		}
		return sprintf(
			/* translators: %s: the ticket reference Afterware issued. */
			__( 'Ticket opened. Reference: %s.', 'afterware' ),
			(string) $tickets[0]['id']
		);
	}

	/**
	 * The sentence a failed switch or revert left behind, or a plain fallback
	 * when the minute it was kept for has passed.
	 *
	 * @param bool $switching Whether the action was a switch rather than a revert.
	 * @return string
	 */
	private static function failure_message( $switching ) {
		$headline = $switching
			? __( 'The switch did not go through, so nothing was changed.', 'afterware' )
			: __( 'The revert did not go through, so the plugin is still on the Afterware build.', 'afterware' );

		$stored = get_transient( 'afterware_action_error_' . get_current_user_id() );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return $headline;
		}
		delete_transient( 'afterware_action_error_' . get_current_user_id() );

		// Some of the reasons come from WordPress's own downloader, which
		// answers in fragments like "Not Found". Saying what happened first
		// and the reason after means the notice always reads as a sentence,
		// whatever the layer underneath had to offer.
		return $headline . ' ' . rtrim( trim( $stored ), '.' ) . '.';
	}

	/**
	 * The version installed under a slug right now.
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	private static function installed_version( $slug ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		$file = Inventory::file_for( $slug );
		if ( null === $file ) {
			$record = Switch_Record::get( $slug );
			return ( null === $record ) ? '' : (string) $record['upstream_version'];
		}
		return (string) get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false )['Version'];
	}

	/**
	 * One notice, in the classic markup every supported core renders.
	 *
	 * @param string $type        One of error, warning, success, info.
	 * @param string $message     The sentence to show.
	 * @param string $dismiss_key Notice key to offer a Dismiss button for, or ''.
	 * @return void
	 */
	private static function notice( $type, $message, $dismiss_key = '' ) {
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> afterware-notice">
			<p><?php echo esc_html( $message ); ?></p>
			<?php if ( '' !== $dismiss_key ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-notice__dismiss">
					<?php wp_nonce_field( 'afterware_dismiss_notice', '_wpnonce_afterware_dismiss_notice' ); ?>
					<input type="hidden" name="action" value="afterware_dismiss_notice" />
					<input type="hidden" name="key" value="<?php echo esc_attr( $dismiss_key ); ?>" />
					<button type="submit" class="button-link"><?php esc_html_e( 'Dismiss', 'afterware' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
