<?php
/**
 * Local copies of the plugins Afterware replaced.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Keeps a copy of the upstream plugin a switch is about to replace.
 *
 * A switch is only reversible because the bytes that were on disk beforehand
 * are still on disk afterwards. The backup is therefore made before anything is
 * downloaded, and it is never deleted by a revert: the last backup per plugin
 * and version stays until the site owner uninstalls Afterware Connect.
 *
 * Archives are laid out with the plugin's own directory at the top
 * (`demo-plugin/demo-plugin.php`), so restoring one is the same operation as
 * installing any other plugin ZIP. The file name carries a random token, so
 * that on a host where the directory guards do not apply — nginx reads no
 * .htaccess — an archive still cannot be fetched by guessing its URL.
 */
final class Backups {

	/**
	 * Directory name under wp-content. Also hard-coded in uninstall.php, which
	 * runs without the plugin's classes loaded.
	 *
	 * @var string
	 */
	const DIR_NAME = 'afterware-backups';

	/**
	 * Where backups go. A path, nothing more: creates nothing, checks nothing,
	 * so a caller that only wants to reason about the location does not bring
	 * the directory into existence.
	 *
	 * @return string Absolute path, with no trailing slash.
	 */
	public static function location() {
		return WP_CONTENT_DIR . '/' . self::DIR_NAME;
	}

	/**
	 * The backups directory, created if missing and closed to the web.
	 *
	 * The guard files are re-asserted on every call rather than only at
	 * creation: two `file_exists()` checks cost nothing, and a directory that
	 * lost its .htaccess to a careless deploy would otherwise stay open for the
	 * life of the site.
	 *
	 * @return string|WP_Error
	 */
	public static function dir() {
		$dir        = self::location();
		$filesystem = self::filesystem();
		if ( null === $filesystem ) {
			return new WP_Error(
				'afterware_fs',
				__( 'WordPress cannot write to wp-content without FTP credentials, so Afterware cannot store a backup on this host.', 'afterware' )
			);
		}

		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return self::unwritable( $dir );
		}

		if ( ! file_exists( $dir . '/index.php' ) ) {
			$filesystem->put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n", FS_CHMOD_FILE );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$filesystem->put_contents( $dir . '/.htaccess', self::htaccess(), FS_CHMOD_FILE );
		}

		return $dir;
	}

	/**
	 * Whether backups can actually be written, in the words a site owner needs
	 * to fix it.
	 *
	 * Deliberately creates nothing: this is asked on every render of a plugin
	 * row, and a site that never switches anything should never grow an empty
	 * backups directory. Before the directory exists, wp-content itself is what
	 * has to be writable.
	 *
	 * @return true|WP_Error
	 */
	public static function writable() {
		$dir     = self::location();
		$subject = is_dir( $dir ) ? $dir : WP_CONTENT_DIR;

		return wp_is_writable( $subject ) ? true : self::unwritable( $subject );
	}

	/**
	 * Archives the plugin currently installed under a slug.
	 *
	 * Archiving the same plugin and version again replaces the earlier archive:
	 * the bytes are the same, and one file per plugin and version keeps the
	 * directory from growing without bound. The new file gets a new token, so
	 * any URL that leaked for the old one is dead.
	 *
	 * @param string $slug    Plugin slug; also the directory name under WP_PLUGIN_DIR.
	 * @param string $version Version on disk, used in the file name.
	 * @return array{path:string,sha256:string,skipped_links:int}|WP_Error
	 */
	public static function create( $slug, $version ) {
		$slug = (string) $slug;
		$dir  = self::dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		if ( ! wp_is_writable( $dir ) ) {
			return self::unwritable( $dir );
		}

		$contents = Backup_Archive::contents( WP_PLUGIN_DIR . '/' . $slug );
		if ( array() === $contents['files'] ) {
			return new WP_Error(
				'afterware_backup_source_missing',
				sprintf(
					/* translators: %s: plugin name. */
					__( 'Afterware found no files to back up for %s, so it did not change anything.', 'afterware' ),
					Inventory::name_for( $slug )
				)
			);
		}

		$prefix = sanitize_file_name( $slug . '-' . (string) $version );
		self::drop_earlier( $dir, $prefix );
		$path = $dir . '/' . $prefix . '-' . bin2hex( random_bytes( 8 ) ) . '.zip';

		$written = Backup_Archive::write( $path, $slug, $contents['files'] );
		$hash    = $written ? Verifier::sha256_file( $path ) : false;
		if ( false === $hash ) {
			return new WP_Error(
				'afterware_backup_failed',
				sprintf(
					/* translators: %s: absolute path to the backup file. */
					__( 'Afterware could not write the backup archive %s, so it did not change anything.', 'afterware' ),
					$path
				)
			);
		}

		return array(
			'path'          => $path,
			'sha256'        => $hash,
			'skipped_links' => $contents['links'],
		);
	}

	/**
	 * The backup recorded for a plugin, or null when there is no record. The
	 * name is never derived: only the record says where a backup went.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|null
	 */
	public static function path( $slug ) {
		$record = Switch_Record::get( $slug );
		if ( null === $record || '' === $record['backup'] ) {
			return null;
		}
		return (string) $record['backup'];
	}

	/**
	 * The recorded backup, checked before anything is installed from it: it has
	 * to be inside the backups directory, and it has to still hash to what was
	 * written down. A path that walked out of the directory, or bytes that
	 * changed since, are refused rather than unpacked over a live plugin.
	 *
	 * @param string $slug Plugin slug.
	 * @return string|WP_Error The real path to the archive.
	 */
	public static function validated( $slug ) {
		$record = Switch_Record::get( $slug );
		$path   = self::path( $slug );
		if ( null === $record || null === $path ) {
			return self::invalid( __( 'Afterware has no record of a backup for this plugin.', 'afterware' ) );
		}

		$real = realpath( $path );
		$home = realpath( self::location() );
		if ( false === $real || false === $home || 0 !== strpos( $real, $home . '/' ) ) {
			/* translators: %s: recorded path to the backup file. */
			return self::invalid( sprintf( __( 'The recorded backup %s is not inside the Afterware backups directory.', 'afterware' ), $path ) );
		}

		$hash = Verifier::sha256_file( $real );
		if ( false === $hash || '' === $record['backup_sha256'] || ! hash_equals( (string) $record['backup_sha256'], $hash ) ) {
			/* translators: %s: recorded path to the backup file. */
			return self::invalid( sprintf( __( 'The backup %s has changed since Afterware made it.', 'afterware' ), $path ) );
		}

		return $real;
	}

	/**
	 * The .htaccess that keeps the archives out of reach of a browser.
	 *
	 * Both Apache forms are covered: `Require all denied` for 2.4, and
	 * `Deny from all` only where mod_authz_core is absent, since on 2.4 the old
	 * directive is a 500 rather than a denial. nginx reads none of this, which
	 * is why the file names carry a random token as well; readme.txt tells
	 * nginx users what to add.
	 *
	 * @return string
	 */
	private static function htaccess() {
		return "# Afterware keeps copies of replaced plugins here. Not for the web.\n"
			. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n";
	}

	/**
	 * Deletes any earlier archive of the same plugin and version.
	 *
	 * @param string $dir    Backups directory.
	 * @param string $prefix `{slug}-{version}`.
	 * @return void
	 */
	private static function drop_earlier( $dir, $prefix ) {
		foreach ( (array) glob( $dir . '/' . $prefix . '-*.zip' ) as $earlier ) {
			if ( is_string( $earlier ) ) {
				wp_delete_file( $earlier );
			}
		}
	}

	/**
	 * The error for a directory Afterware cannot write to.
	 *
	 * @param string $dir Directory that has to be writable.
	 * @return WP_Error
	 */
	private static function unwritable( $dir ) {
		return new WP_Error(
			'afterware_backups_unwritable',
			sprintf(
				/* translators: %s: absolute path to the directory that must be writable. */
				__( 'Afterware keeps a copy of the plugin it replaces in %s, and cannot write there. Make that directory writable by the web server user, then try again.', 'afterware' ),
				$dir
			)
		);
	}

	/**
	 * The error for a backup that may not be restored.
	 *
	 * @param string $reason What is wrong with it.
	 * @return WP_Error
	 */
	private static function invalid( $reason ) {
		return new WP_Error(
			'afterware_backup_invalid',
			$reason . ' ' . __( 'Afterware did not restore it. Reinstall the original plugin from its own source.', 'afterware' )
		);
	}

	/**
	 * The initialised global filesystem, or null when WordPress cannot write
	 * without credentials.
	 *
	 * @return \WP_Filesystem_Base|null
	 */
	private static function filesystem() {
		global $wp_filesystem;
		require_once ABSPATH . 'wp-admin/includes/file.php';

		if ( ! WP_Filesystem() ) {
			return null;
		}
		return $wp_filesystem;
	}
}
