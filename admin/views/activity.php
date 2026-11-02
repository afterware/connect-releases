<?php
/**
 * Activity: the local record of everything Afterware Connect has done here.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data From Admin_Data::activity().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap afterware">
	<h1><?php esc_html_e( 'Afterware activity', 'afterware' ); ?></h1>
	<p class="afterware-lede">
		<?php esc_html_e( 'Everything Afterware Connect has done on this site, newest first. It is kept as a record and there is no button to empty it; the oldest entries fall off once there are 200.', 'afterware' ); ?>
	</p>

	<div class="afterware-card">
		<?php if ( array() === $data['entries'] ) : ?>
			<p><?php esc_html_e( 'Nothing has happened yet. Entries appear here after the first scan.', 'afterware' ); ?></p>
		<?php else : ?>
			<ul class="afterware-activity">
				<?php foreach ( $data['entries'] as $afterware_entry ) : ?>
					<li class="afterware-activity__item">
						<?php
						$afterware_chip = $afterware_entry['chip'];
						require __DIR__ . '/partials/chip.php';
						?>
						<span class="afterware-activity__text"><?php echo esc_html( $afterware_entry['text'] ); ?></span>
						<?php if ( null !== $afterware_entry['slug'] ) : ?>
							<code class="afterware-mono"><?php echo esc_html( (string) $afterware_entry['slug'] ); ?></code>
						<?php endif; ?>
						<?php
						$afterware_time = (int) $afterware_entry['at'];
						require __DIR__ . '/partials/time.php';
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
	</div>
</div>
