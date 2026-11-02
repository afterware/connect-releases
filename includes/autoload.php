<?php
/**
 * Minimal class loader: Afterware\Foo_Bar -> includes/class-foo-bar.php,
 * Afterware\Admin\Foo -> admin/class-foo.php, Afterware\CLI\Foo -> includes/cli/class-foo.php.
 *
 * @package Afterware
 */

spl_autoload_register(
	function ( $class_name ) {
		if ( 0 !== strpos( $class_name, 'Afterware\\' ) ) {
			return;
		}
		$parts = explode( '\\', substr( $class_name, strlen( 'Afterware\\' ) ) );
		$name  = 'class-' . strtolower( str_replace( '_', '-', array_pop( $parts ) ) ) . '.php';
		$dir   = AFTERWARE_DIR . 'includes/';
		if ( array( 'Admin' ) === $parts ) {
			$dir = AFTERWARE_DIR . 'admin/';
		} elseif ( array( 'CLI' ) === $parts ) {
			$dir = AFTERWARE_DIR . 'includes/cli/';
		}
		$file = $dir . $name;
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);
