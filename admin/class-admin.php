<?php
/**
 * The wp-admin menu, screens and assets.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Connection;
use Afterware\Notices;

/**
 * Registers the Afterware menu and renders its screens.
 *
 * This class owns the wiring only: which screens exist, what they are called,
 * which assets they load and where the links to them go. What each screen
 * says is in `admin/views/`, and the arrays those views read are assembled by
 * Admin_Data; every state change is a POST handled by Actions.
 */
final class Admin {

	use Admin_Links;

	/**
	 * The capability needed to see any Afterware screen. Switching, reverting
	 * and disconnecting need more than this (Switch_Guard::may_manage_plugins).
	 *
	 * @var string
	 */
	const CAPABILITY = 'update_plugins';

	/**
	 * The top-level page slug. The other screens hang off it with a suffix.
	 *
	 * @var string
	 */
	const PAGE = 'afterware';

	/**
	 * The hook suffixes WordPress gave our screens, so admin_enqueue_scripts
	 * can load the assets on those and nowhere else.
	 *
	 * @var array<int,string>
	 */
	private static $hooks = array();

	/**
	 * The hook suffixes of the screens this plugin registered, which are also
	 * the ids WP_Screen gives them. Empty until `admin_menu` has run.
	 *
	 * @return array<int,string>
	 */
	public static function screen_hooks() {
		return array_values( array_filter( self::$hooks ) );
	}

	/**
	 * Wires the admin: the menu, the screens' assets, the notices, the two
	 * links on the plugins screen, and the post handlers behind every button.
	 *
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'finish_activation' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( Admin_Notices::class, 'render' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AFTERWARE_FILE ), array( $this, 'action_links' ) );
		add_filter( 'plugin_row_meta', array( $this, 'row_meta' ), 10, 2 );
		add_filter( 'parent_file', array( $this, 'highlight_menu' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_menu' ) );

		Actions::register_hooks();
	}

	/**
	 * Adds the top-level menu and its screens.
	 *
	 * The per-plugin screen is registered with no parent, the way core
	 * registers a page that belongs to a menu but does not appear in it: it
	 * is reached from a table row, never from the sidebar. Removing it from
	 * the sidebar afterwards is not the same thing — `remove_submenu_page()`
	 * also takes away the parent WordPress looks up to decide whether the
	 * page may be loaded at all, and the screen 403s.
	 *
	 * @return void
	 */
	public function menu() {
		// `admin_menu` can fire more than once in a request (a test, or another
		// plugin re-running it), and these suffixes decide which screens load
		// our assets and show our notices. Rebuild the list rather than grow it.
		self::$hooks = array();

		self::$hooks[] = add_menu_page(
			__( 'Afterware', 'afterware' ),
			__( 'Afterware', 'afterware' ),
			self::CAPABILITY,
			self::PAGE,
			array( $this, 'render_overview' ),
			'dashicons-shield',
			66
		);

		$screens = array(
			array( self::PAGE, __( 'Overview', 'afterware' ), 'render_overview' ),
			array( self::PAGE . '-settings', __( 'Settings', 'afterware' ), 'render_settings' ),
			array( self::PAGE . '-tickets', __( 'Tickets', 'afterware' ), 'render_tickets' ),
			array( self::PAGE . '-activity', __( 'Activity', 'afterware' ), 'render_activity' ),
		);
		foreach ( $screens as $screen ) {
			self::$hooks[] = add_submenu_page(
				self::PAGE,
				$screen[1],
				$screen[1],
				self::CAPABILITY,
				$screen[0],
				array( $this, $screen[2] )
			);
		}

		self::$hooks[] = add_submenu_page(
			'',
			__( 'Plugin', 'afterware' ),
			__( 'Plugin', 'afterware' ),
			self::CAPABILITY,
			self::PAGE . '-plugin',
			array( $this, 'render_plugin' )
		);
	}

	/**
	 * Loads the stylesheet and the progressive-enhancement script, on our
	 * screens only. Neither has a dependency and the page works without both.
	 *
	 * @param string $hook_suffix The screen WordPress is about to render.
	 * @return void
	 */
	public function assets( $hook_suffix ) {
		if ( ! in_array( (string) $hook_suffix, self::screen_hooks(), true ) ) {
			return;
		}
		wp_enqueue_style( 'afterware-admin', AFTERWARE_URL . 'admin/assets/admin.css', array(), AFTERWARE_VERSION );
		wp_enqueue_script( 'afterware-admin', AFTERWARE_URL . 'admin/assets/admin.js', array(), AFTERWARE_VERSION, true );
	}

	/**
	 * Registers the site on the first admin request that is this plugin's to
	 * take over, and lands whoever made it on the overview.
	 *
	 * @return void
	 */
	public function finish_activation() {
		if ( ! get_transient( 'afterware_activated' ) ) {
			return;
		}
		if ( wp_doing_ajax() || ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		if ( isset( $GLOBALS['pagenow'] ) && 'admin-post.php' === $GLOBALS['pagenow'] ) {
			// admin_init also runs for form posts. Redirecting one of those to
			// the overview would swallow whatever the site owner just clicked.
			return;
		}
		if ( self::is_bulk_activation() ) {
			return;
		}

		// Consumed here, not at the top: spending the marker on a request this
		// had no business taking over would leave that site connected by
		// nothing at all, with no second chance. Still before anything that
		// can fail, so a registration that dies does not retry all day.
		delete_transient( 'afterware_activated' );

		if ( ! Connection::is_connected() ) {
			$registered = Connection::register();
			if ( is_wp_error( $registered ) ) {
				Notices::add( 'connection', 'error', $registered->get_error_message(), true );
				Actions::redirect( self::page_url() );
			}
		}
		Actions::redirect( self::page_url( self::PAGE, array( 'afterware-notice' => 'registered' ) ) );
	}

	/**
	 * Whether this is the request WordPress lands on after several plugins
	 * were activated at once (`plugins.php?activate-multi=true`).
	 *
	 * The one case where taking the request over is wrong. Someone who ticked
	 * six plugins and pressed Activate is owed the list of what they just
	 * switched on; jumping them to one plugin's overview takes that away and
	 * leaves them wondering about the other five. The marker survives it, so
	 * the next screen they open is the one that registers. Every other first
	 * request — the single-activation redirect, or the next admin screen after
	 * `wp plugin activate afterware` — is one where the only thing that has
	 * just happened is this plugin being switched on, so registering and
	 * saying so is what someone is there for.
	 *
	 * @return bool
	 */
	private static function is_bulk_activation() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading WordPress core's own redirect marker to tell which activation this is; no nonce exists for it, and this only ever declines to act.
		return isset( $_GET['activate-multi'] );
	}

	/* ------------------------------------------------------------ rendering */

	/**
	 * Renders the overview screen.
	 *
	 * @return void
	 */
	public function render_overview() {
		self::view( 'overview', Admin_Data::overview() );
	}

	/**
	 * Renders one plugin's screen.
	 *
	 * @return void
	 */
	public function render_plugin() {
		self::view( 'plugin', Admin_Data::plugin( self::query_arg( 'slug' ) ) );
	}

	/**
	 * Renders the settings screen.
	 *
	 * @return void
	 */
	public function render_settings() {
		self::view( 'settings', Admin_Data::settings() );
	}

	/**
	 * Renders the tickets screen.
	 *
	 * @return void
	 */
	public function render_tickets() {
		self::view( 'tickets', Admin_Data::tickets() );
	}

	/**
	 * Renders the activity screen.
	 *
	 * @return void
	 */
	public function render_activity() {
		self::view( 'activity', Admin_Data::activity() );
	}

	/**
	 * Includes a view template with its data.
	 *
	 * `$name` is always a literal from this class, never anything a request
	 * supplied, so no path can be steered from outside.
	 *
	 * @param string              $name View file name without the extension.
	 * @param array<string,mixed> $data The array the template reads.
	 * @return void
	 */
	public static function view( $name, array $data ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $data is the template's own variable; the required file below is what reads it.
		$file = AFTERWARE_DIR . 'admin/views/' . $name . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}

	/* ------------------------------------------------------------ helpers */

	/**
	 * The URL of one of our screens.
	 *
	 * @param string               $page One of the page slugs registered above.
	 * @param array<string,string> $args Extra query arguments.
	 * @return string
	 */
	public static function page_url( $page = self::PAGE, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => $page ), $args ), admin_url( 'admin.php' ) );
	}

	/**
	 * A read-only query argument from one of our screens, sanitised.
	 *
	 * `slug`, `filter` and `afterware-notice` are navigation, not state: they
	 * arrive on a plain GET a site owner can bookmark and share, so there is
	 * no nonce for them to carry and nothing reached from here writes
	 * anything. Every state change is a POST to admin-post.php, and Actions
	 * checks a nonce and a capability on each one.
	 *
	 * @param string $name Query argument name.
	 * @return string
	 */
	public static function query_arg( $name ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Navigation, not state: these arrive on a bookmarkable GET that has no nonce to carry, and nothing reached from here writes anything. See the docblock above.
		return isset( $_GET[ $name ] ) ? sanitize_text_field( wp_unslash( $_GET[ $name ] ) ) : '';
	}
}
