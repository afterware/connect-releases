<?php
/**
 * A status chip.
 *
 * The prefix is read out before the label by a screen reader, which sees no
 * colour and no column heading: "Status: Switch available", "Kind: Breakage".
 *
 * @package Afterware
 *
 * @var array{tone:string,label:string,prefix?:string} $afterware_chip From Admin_Data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<span class="afterware-chip afterware-chip--<?php echo esc_attr( $afterware_chip['tone'] ); ?>"><span class="screen-reader-text"><?php echo esc_html( isset( $afterware_chip['prefix'] ) ? $afterware_chip['prefix'] : _x( 'Status: ', 'prefix read out before a status chip label', 'afterware' ) ); ?></span><?php echo esc_html( $afterware_chip['label'] ); ?></span>
