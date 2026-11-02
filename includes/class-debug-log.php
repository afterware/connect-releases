<?php
/**
 * Reads the tail of WP_CONTENT_DIR/debug.log without loading the whole file.
 *
 * @package Afterware
 */

namespace Afterware;

/**
 * A tail read of the debug log, redacted of anything that looks like key
 * material, for attaching to a support ticket.
 *
 * Never returns anything from the log Afterware itself did not put there
 * verbatim: this only reads and redacts, it never writes to the log.
 */
final class Debug_Log {

	/**
	 * Maximum number of trailing lines read.
	 *
	 * @var int
	 */
	const MAX_LINES = 200;

	/**
	 * Maximum size of the returned tail, applied after the lines are joined.
	 *
	 * @var int
	 */
	const MAX_BYTES = 16384;

	/**
	 * Maximum number of bytes read off the end of the file, whatever those
	 * bytes turn out to contain.
	 *
	 * MAX_LINES alone is not a bound: a debug log is exactly the kind of file
	 * that ends up holding one enormous line — a serialised object, a stack
	 * trace with no newlines, a truncated write — and looking for two hundred
	 * newlines in it would read the whole thing into memory to find none. Four
	 * times MAX_BYTES, so a capped read still has room to find line breaks
	 * before the part that is actually returned.
	 *
	 * @var int
	 */
	const MAX_READ_BYTES = 65536;

	/**
	 * The last MAX_LINES lines of `WP_CONTENT_DIR/debug.log`, read from the
	 * end of the file in chunks rather than loading it all, never reading
	 * more than MAX_READ_BYTES, truncated to MAX_BYTES and redacted of
	 * anything that looks like a key or a bearer token.
	 *
	 * @return string|null Null when the file does not exist or cannot be read.
	 */
	public static function tail() {
		$path = WP_CONTENT_DIR . '/debug.log';
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			return null;
		}

		$size = filesize( $path );
		if ( false === $size ) {
			return null;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- WP_Filesystem can only read a whole file; the point of this class is to read the end of one that may be enormous.
		if ( false === $handle ) {
			return null;
		}

		$chunk_size = 8192;
		$pos        = $size;
		$buffer     = '';
		$lines      = array();
		$line_count = 0;

		while ( $pos > 0 && $line_count <= self::MAX_LINES && ( $size - $pos ) < self::MAX_READ_BYTES ) {
			$read = min( $chunk_size, $pos, self::MAX_READ_BYTES - ( $size - $pos ) );
			$pos -= $read;
			fseek( $handle, $pos );
			$buffer     = fread( $handle, $read ) . $buffer; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- WP_Filesystem can only read a whole file; the point of this class is to read the end of one that may be enormous.
			$lines      = explode( "\n", rtrim( $buffer, "\n" ) );
			$line_count = count( $lines );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing the handle opened above.

		$tail = implode( "\n", array_slice( $lines, -self::MAX_LINES ) );
		if ( strlen( $tail ) > self::MAX_BYTES ) {
			$tail = substr( $tail, -self::MAX_BYTES );
			// A byte cut can land inside a multibyte UTF-8 sequence; drop
			// leading bytes until what is left is valid UTF-8 again.
			while ( '' !== $tail && 1 !== preg_match( '//u', $tail ) ) {
				$tail = substr( $tail, 1 );
			}
		}

		return self::redact( $tail );
	}

	/**
	 * Strips anything that looks like key material: `Authorization: Bearer …`
	 * fragments first, then any other run of 32 or more key-shaped characters.
	 *
	 * @param string $text Raw log tail.
	 * @return string
	 */
	private static function redact( $text ) {
		$text = (string) preg_replace( '/Authorization:\s*Bearer\s+\S+/i', 'Authorization: Bearer [redacted]', $text );
		$text = (string) preg_replace( '/[A-Za-z0-9_-]{32,}/', '[redacted]', $text );
		return $text;
	}
}
