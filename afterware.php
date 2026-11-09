<?php
/**
 * Plugin Name: Afterware Connect
 * Plugin URI: https://afterware.io/docs/install
 * Description: Inventory, risk report and signed in-place updates for abandoned WordPress plugins, maintained by Afterware.
 * Version: 0.3.2
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Afterware (Complexolution Ltd.)
 * Author URI: https://afterware.io
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: afterware
 *
 * @package Afterware
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AFTERWARE_VERSION', '0.3.2' );
define( 'AFTERWARE_FILE', __FILE__ );
define( 'AFTERWARE_DIR', plugin_dir_path( __FILE__ ) );
define( 'AFTERWARE_URL', plugin_dir_url( __FILE__ ) );
define( 'AFTERWARE_UPDATES_HOST', 'updates.afterware.io' );
// Where signed packages are served from. A verified manifest may only point at an
// Afterware host, which the platform's manifest schema also enforces on the way out
// (docs/references/update-manifest.md); Package_Guard re-checks it on the way in.
define( 'AFTERWARE_PACKAGES_HOST', 'packages.afterware.io' );
// The published Ed25519 key ring and its detached signature. Not overridable: the
// document is authenticated by a key this build already trusts, but pointing a site
// somewhere else is still a decision nothing but a release gets to make.
define( 'AFTERWARE_KEY_RING_URL', 'https://keys.afterware.io/keys.json' );
define( 'AFTERWARE_KEY_RING_SIG_URL', 'https://keys.afterware.io/keys.json.sig' );
// The site API lives on the updates host under /v1; client paths are relative to that
// version root (Api_Client). wp-config.php may override this for staging or wp-env.
if ( ! defined( 'AFTERWARE_API_BASE' ) ) {
	define( 'AFTERWARE_API_BASE', 'https://updates.afterware.io/v1' );
}

require_once AFTERWARE_DIR . 'includes/autoload.php';

register_activation_hook( __FILE__, array( 'Afterware\\Plugin', 'activate' ) );
add_action( 'plugins_loaded', array( 'Afterware\\Plugin', 'boot' ) );
