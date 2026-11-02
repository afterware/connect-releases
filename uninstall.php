<?php
/**
 * Fires on explicit "Delete" from the Plugins screen. Removes every option
 * and local backup created by the plugin. Does not contact the API.
 *
 * @package Afterware
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

delete_transient( 'afterware_activated' );
wp_clear_scheduled_hook( 'afterware_first_install_receipt' );

// No core API deletes options by wildcard; this plugin owns the afterware_
// prefix entirely (enforced by phpcs.xml.dist's `prefixes` config), so a
// scoped LIKE delete is the only way to guarantee uninstall never leaves an
// orphaned option behind as new afterware_* options are added over time.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'afterware_' ) . '%' ) );

// A dynamically-keyed transient (the ticket auto-dedupe window, one per slug
// and key id) cannot be removed by name like `afterware_activated` above:
// WordPress stores a transient's value and timeout under `_transient_` /
// `_transient_timeout_` prefixes, which the LIKE delete just above never
// matches. Same reasoning as that delete, scoped to those two prefixes.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		'_transient_' . $wpdb->esc_like( 'afterware_' ) . '%',
		'_transient_timeout_' . $wpdb->esc_like( 'afterware_' ) . '%'
	)
);

$afterware_backups_dir = trailingslashit( WP_CONTENT_DIR ) . 'afterware-backups';
if ( is_dir( $afterware_backups_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	WP_Filesystem();
	global $wp_filesystem;
	if ( $wp_filesystem ) {
		$wp_filesystem->delete( $afterware_backups_dir, true );
	}
}
