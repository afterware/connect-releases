<?php
/**
 * Read-only account claiming card. No request is made until the form is posted.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data Settings view data.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="afterware-card" id="afterware-account">
	<h2><?php esc_html_e( 'Connect to your Afterware account', 'afterware' ); ?></h2>
	<p><?php esc_html_e( 'Registration enables reports. A connection code lets your dashboard workspace manage this site and its subscription coverage.', 'afterware' ); ?></p>
	<?php if ( ! $data['can_claim'] ) : ?>
		<p><?php esc_html_e( 'An administrator who can install plugins must connect this site to an account.', 'afterware' ); ?></p>
	<?php else : ?>
		<?php if ( null !== $data['claim'] ) : ?>
			<p><?php esc_html_e( 'Copy this code, open your dashboard, choose the intended workspace and enter the code there.', 'afterware' ); ?></p>
			<p><code id="afterware-claim-code" class="afterware-mono" data-afterware-copy data-copy-label="<?php esc_attr_e( 'Copy connection code', 'afterware' ); ?>" data-copy-done="<?php esc_attr_e( 'Copied', 'afterware' ); ?>" data-copy-failed="<?php esc_attr_e( 'Select and copy the code manually', 'afterware' ); ?>"><?php echo esc_html( $data['claim']['code'] ); ?></code></p>
			<p>
				<?php esc_html_e( 'Code expires:', 'afterware' ); ?>
				<?php
				$afterware_time = $data['claim']['expires'];
				require __DIR__ . '/time.php';
				?>
			</p>
			<p><a class="button button-primary" href="<?php echo esc_url( $data['claim']['url'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open account dashboard', 'afterware' ); ?></a></p>
			<p><?php esc_html_e( 'Share the code only with the person managing this site’s workspace. Creating a code does not confirm account association or payment.', 'afterware' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'Get a private code that expires after 15 minutes. Your site key stays on this site.', 'afterware' ); ?></p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-form">
			<?php wp_nonce_field( 'afterware_claim_code', '_wpnonce_afterware_claim_code' ); ?>
			<input type="hidden" name="action" value="afterware_claim_code" />
			<button type="submit" class="button"><?php echo esc_html( null === $data['claim'] ? __( 'Get connection code', 'afterware' ) : __( 'Get a replacement code', 'afterware' ) ); ?></button>
		</form>
		<p><?php esc_html_e( 'A replacement invalidates the previous unused code. If this site already belongs to a workspace, ask its owner to move it from the dashboard.', 'afterware' ); ?></p>
	<?php endif; ?>
</div>
