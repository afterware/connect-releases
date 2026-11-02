<?php
/**
 * The state every other screen depends on: this site has no key yet.
 *
 * @package Afterware
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="afterware-card afterware-card--attention">
	<h2><?php esc_html_e( 'Connection needed', 'afterware' ); ?></h2>
	<p><?php esc_html_e( 'This site is not connected to Afterware. Until it is, nothing is scanned, no risk report comes back, and no Afterware updates are offered.', 'afterware' ); ?></p>
	<p><?php esc_html_e( 'Connecting registers this site and gets it a key. It usually takes a second; if it did not work the first time, it is worth another try.', 'afterware' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'afterware_retry_register', '_wpnonce_afterware_retry_register' ); ?>
		<input type="hidden" name="action" value="afterware_retry_register" />
		<button type="submit" class="button button-primary"><?php esc_html_e( 'Try again', 'afterware' ); ?></button>
	</form>
</div>
