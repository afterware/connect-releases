<?php
/**
 * Reading a plugin directory and writing it to a ZIP.
 *
 * @package Afterware
 */

namespace Afterware;

use FilesystemIterator;
use PclZip;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * The mechanics behind a backup: what goes in the archive, and how it is
 * written.
 *
 * Split out of Backups so that class stays about policy — where archives live,
 * whether they may be trusted — and this one stays about bytes. Both writers
 * produce exactly the same layout, with the plugin's own directory at the top,
 * so an archive is restored by installing it like any other plugin ZIP.
 */
final class Backup_Archive {

	/**
	 * Every real file under a plugin directory, as archive path => absolute
	 * path, sorted so an archive of unchanged files is byte-stable.
	 *
	 * Symbolic links are counted and skipped. Following one would copy bytes
	 * from outside the plugin into an archive that is later unpacked back into
	 * the plugins directory; storing one would restore a link to a path that
	 * may no longer mean what it did. Either way the count is reported, so a
	 * revert that comes back short is explained rather than mysterious.
	 *
	 * @param string $source Absolute path to the plugin directory.
	 * @return array{files:array<string,string>,links:int}
	 */
	public static function contents( $source ) {
		$source = (string) $source;
		$files  = array();
		$links  = 0;

		if ( ! is_dir( $source ) ) {
			return array(
				'files' => $files,
				'links' => $links,
			);
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $source, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);

		foreach ( $iterator as $item ) {
			/** @var SplFileInfo $item */
			if ( $item->isLink() ) {
				++$links;
				continue;
			}
			if ( ! $item->isFile() ) {
				continue;
			}
			$absolute           = $item->getPathname();
			$relative           = str_replace( '\\', '/', substr( $absolute, strlen( $source ) + 1 ) );
			$files[ $relative ] = $absolute;
		}
		ksort( $files );

		return array(
			'files' => $files,
			'links' => $links,
		);
	}

	/**
	 * Writes an archive with the plugin's directory at the top.
	 *
	 * @param string               $path  Archive to write.
	 * @param string               $slug  Top-level directory name inside the archive.
	 * @param array<string,string> $files Archive path => absolute path.
	 * @return bool
	 */
	public static function write( $path, $slug, array $files ) {
		/**
		 * Filters whether the zip extension is used to write a backup archive.
		 *
		 * WordPress offers the same choice when unzipping
		 * (`unzip_file_use_ziparchive`). Returning false forces the PclZip
		 * fallback WordPress bundles, for a host whose zip extension is present
		 * but broken.
		 *
		 * @param bool $use_ziparchive Whether ZipArchive is available.
		 */
		$use_ziparchive = (bool) apply_filters( 'afterware_use_ziparchive', class_exists( 'ZipArchive' ) );

		return $use_ziparchive
			? self::write_with_ziparchive( (string) $path, (string) $slug, $files )
			: self::write_with_pclzip( (string) $path, (string) $slug, $files );
	}

	/**
	 * Writes the archive with the zip extension.
	 *
	 * @param string               $path  Archive to write.
	 * @param string               $slug  Top-level directory name inside the archive.
	 * @param array<string,string> $files Archive path => absolute path.
	 * @return bool
	 */
	private static function write_with_ziparchive( $path, $slug, array $files ) {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			return false;
		}
		foreach ( $files as $relative => $absolute ) {
			if ( ! $zip->addFile( $absolute, $slug . '/' . $relative ) ) {
				$zip->close();
				return false;
			}
		}
		return $zip->close();
	}

	/**
	 * Writes the archive with the copy of PclZip WordPress ships, for a host
	 * built without the zip extension.
	 *
	 * Each file is passed as an attribute array naming where it goes inside the
	 * archive, so both writers produce exactly the same layout.
	 *
	 * @param string               $path  Archive to write.
	 * @param string               $slug  Top-level directory name inside the archive.
	 * @param array<string,string> $files Archive path => absolute path.
	 * @return bool
	 */
	private static function write_with_pclzip( $path, $slug, array $files ) {
		require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';

		$entries = array();
		foreach ( $files as $relative => $absolute ) {
			$entries[] = array(
				PCLZIP_ATT_FILE_NAME          => $absolute,
				PCLZIP_ATT_FILE_NEW_FULL_NAME => $slug . '/' . $relative,
			);
		}

		$archive = new PclZip( $path );
		return 0 !== $archive->create( $entries );
	}
}
