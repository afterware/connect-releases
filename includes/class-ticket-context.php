<?php
/**
 * The parts of a ticket's environment context that need the plugin APIs.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * Split out of Tickets to keep that file a manageable size.
 */
final class Ticket_Context {

	/**
	 * The `"{slug}@{version}"` strings for every active plugin.
	 *
	 * @return array<int,string>
	 */
	public static function active_plugins() {
		$active_files = (array) get_option( 'active_plugins', array() );
		if ( is_multisite() ) {
			$active_files = array_values(
				array_unique(
					array_merge( $active_files, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) )
				)
			);
		}

		$plugins = get_plugins();
		$out     = array();
		foreach ( $active_files as $file ) {
			if ( ! isset( $plugins[ $file ] ) ) {
				continue;
			}
			$out[] = Inventory::slug_for( (string) $file ) . '@' . (string) $plugins[ $file ]['Version'];
		}
		return $out;
	}

	/**
	 * The `plugin` block of the context for a slug, or null when nothing is installed under it.
	 *
	 * @param string|null $slug Plugin slug, or null.
	 * @return array{slug:string,installed_version:string,on_afterware:bool}|null
	 */
	public static function plugin( $slug ) {
		if ( null === $slug ) {
			return null;
		}
		$file = Inventory::file_for( $slug );
		if ( null === $file ) {
			return null;
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $file ] ) ) {
			return null;
		}
		return array(
			'slug'              => $slug,
			'installed_version' => (string) $plugins[ $file ]['Version'],
			'on_afterware'      => null !== Switch_Record::get( $slug ),
		);
	}
}
