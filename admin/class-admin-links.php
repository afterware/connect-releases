<?php
/**
 * The links between the plugins screen, the menu and the Afterware screens.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Inventory;

/**
 * The small amount of wp-admin that Afterware reaches into from outside its
 * own screens: the plugins list, and the menu highlight for a screen that has
 * no menu entry of its own.
 *
 * Split out of Admin so that class is only about the screens it owns.
 */
trait Admin_Links {

	/**
	 * Keeps the Afterware menu open and Overview highlighted while a plugin's
	 * own screen is showing: it has no parent of its own, so without this the
	 * sidebar would look as though the visitor had left the section.
	 *
	 * @param string $file The menu file WordPress worked out.
	 * @return string
	 */
	public function highlight_menu( $file ) {
		return ( self::PAGE . '-plugin' === self::query_arg( 'page' ) ) ? self::PAGE : $file;
	}

	/**
	 * Puts "Overview" first among this plugin's own action links.
	 *
	 * @param array<int|string,string> $links The plugin's action links.
	 * @return array<int|string,string>
	 */
	public function action_links( $links ) {
		array_unshift(
			$links,
			sprintf( '<a href="%s">%s</a>', esc_url( self::page_url() ), esc_html__( 'Overview', 'afterware' ) )
		);
		return $links;
	}

	/**
	 * Marks the plugins Afterware maintains in their own row on the plugins
	 * screen, and links each to the screen that can revert it.
	 *
	 * @param array<int,string> $meta        The row's meta links.
	 * @param string            $plugin_file Plugin file relative to WP_PLUGIN_DIR.
	 * @return array<int,string>
	 */
	public function row_meta( $meta, $plugin_file ) {
		$ours = Inventory::our_plugins();
		if ( ! isset( $ours[ $plugin_file ] ) ) {
			return $meta;
		}
		$meta[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( self::page_url( self::PAGE . '-plugin', array( 'slug' => $ours[ $plugin_file ] ) ) ),
			esc_html__( 'Maintained by Afterware', 'afterware' )
		);
		return $meta;
	}
}
