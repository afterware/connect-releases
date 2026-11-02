<?php
/**
 * A time, said the way people say it, with the exact moment in the tooltip.
 *
 * @package Afterware
 *
 * @var int $afterware_time Unix timestamp.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<time class="afterware-time" datetime="<?php echo esc_attr( gmdate( 'c', (int) $afterware_time ) ); ?>" title="<?php echo esc_attr( wp_date( 'Y-m-d H:i', (int) $afterware_time, new DateTimeZone( 'UTC' ) ) . ' UTC' ); ?>">
	<?php
	if ( (int) $afterware_time > time() ) {
		printf(
			/* translators: %s: a length of time, e.g. "15 minutes". */
			esc_html__( 'in %s', 'afterware' ),
			esc_html( human_time_diff( (int) $afterware_time ) )
		);
	} else {
		printf(
			/* translators: %s: a length of time, e.g. "3 hours". */
			esc_html__( '%s ago', 'afterware' ),
			esc_html( human_time_diff( (int) $afterware_time ) )
		);
	}
	?>
</time>
