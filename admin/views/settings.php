<?php
/**
 * Settings: the connection, exactly what leaves this site, and the one toggle.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data From Admin_Data::settings().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap afterware">
	<h1><?php esc_html_e( 'Afterware settings', 'afterware' ); ?></h1>

	<div class="afterware-card">
		<h2><?php esc_html_e( 'Connection', 'afterware' ); ?></h2>
		<?php if ( ! $data['connected'] ) : ?>
			<?php require __DIR__ . '/partials/notice-connection.php'; ?>
		<?php else : ?>
			<dl class="afterware-facts">
				<dt><?php esc_html_e( 'Site id', 'afterware' ); ?></dt>
				<dd>
					<code
						class="afterware-mono"
						id="afterware-site-id"
						data-afterware-copy
						data-copy-label="<?php esc_attr_e( 'Copy', 'afterware' ); ?>"
						data-copy-done="<?php esc_attr_e( 'Copied', 'afterware' ); ?>"
						data-copy-failed="<?php esc_attr_e( 'Could not copy', 'afterware' ); ?>"
					><?php echo esc_html( $data['site_id'] ); ?></code>
				</dd>
				<dt><?php esc_html_e( 'Registered', 'afterware' ); ?></dt>
				<dd>
					<?php if ( null === $data['registered_at'] ) : ?>
						<?php esc_html_e( 'Not recorded', 'afterware' ); ?>
					<?php else : ?>
						<?php
						$afterware_time = (int) $data['registered_at'];
						require __DIR__ . '/partials/time.php';
						?>
					<?php endif; ?>
				</dd>
				<dt><?php esc_html_e( 'Site key', 'afterware' ); ?></dt>
				<dd><?php esc_html_e( 'Stored on this site and sent with each request. It is never shown again after registration.', 'afterware' ); ?></dd>
			</dl>

			<h3><?php esc_html_e( 'Disconnect', 'afterware' ); ?></h3>
			<p><?php esc_html_e( 'Disconnecting stops scans and Afterware updates. Plugins already on an Afterware build stay exactly as they are, and you can still revert them from their own screens.', 'afterware' ); ?></p>
			<p><?php esc_html_e( 'Deleting Afterware Connect is a different thing, and it does delete the backups: revert anything you want the original of before you uninstall it.', 'afterware' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-form">
				<?php wp_nonce_field( 'afterware_disconnect', '_wpnonce_afterware_disconnect' ); ?>
				<input type="hidden" name="action" value="afterware_disconnect" />
				<?php
				$afterware_confirm_id    = 'afterware-disconnect-confirm';
				$afterware_confirm_word  = 'disconnect';
				$afterware_confirm_label = __( 'Type the word to confirm', 'afterware' );
				require __DIR__ . '/partials/typed-confirm.php';
				?>
				<button type="submit" class="button afterware-button--danger" data-afterware-confirm-for="afterware-disconnect-confirm">
					<?php esc_html_e( 'Disconnect this site', 'afterware' ); ?>
				</button>
			</form>
		<?php endif; ?>
	</div>

	<?php if ( $data['connected'] ) : ?>
		<?php require __DIR__ . '/partials/account-claim.php'; ?>
	<?php endif; ?>

	<?php require __DIR__ . '/partials/key-ring.php'; ?>

	<div class="afterware-card">
		<h2><?php esc_html_e( 'What this site sends', 'afterware' ); ?></h2>
		<p><?php esc_html_e( 'Every scan sends this list and nothing more.', 'afterware' ); ?></p>
		<?php foreach ( $data['telemetry'] as $afterware_group ) : ?>
			<h3><?php echo esc_html( $afterware_group['title'] ); ?></h3>
			<dl class="afterware-facts">
				<?php foreach ( $afterware_group['rows'] as $afterware_field ) : ?>
					<dt><?php echo esc_html( $afterware_field['label'] ); ?></dt>
					<dd><?php echo esc_html( $afterware_field['value'] ); ?></dd>
				<?php endforeach; ?>
			</dl>
		<?php endforeach; ?>
		<p class="afterware-emphasis"><?php esc_html_e( 'Nothing else is sent. No content, users, options or emails.', 'afterware' ); ?></p>
	</div>

	<div class="afterware-card">
		<h2><?php esc_html_e( 'Debug log', 'afterware' ); ?></h2>
		<p><?php esc_html_e( 'A support ticket is easier to answer with the tail of your debug log attached. It is off unless you turn it on, and you can see the exact lines before you send them on the Tickets screen.', 'afterware' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-form">
			<?php wp_nonce_field( 'afterware_save_settings', '_wpnonce_afterware_save_settings' ); ?>
			<input type="hidden" name="action" value="afterware_save_settings" />
			<p>
				<label for="afterware-share-debug-log">
					<input type="checkbox" id="afterware-share-debug-log" name="share_debug_log" value="1" <?php checked( $data['share_debug_log'] ); ?> />
					<?php esc_html_e( 'Include the last 200 lines of debug.log when I open a ticket', 'afterware' ); ?>
				</label>
			</p>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save settings', 'afterware' ); ?></button>
		</form>
	</div>

	<div class="afterware-card">
		<h2><?php esc_html_e( 'About', 'afterware' ); ?></h2>
		<dl class="afterware-facts">
			<dt><?php esc_html_e( 'Version', 'afterware' ); ?></dt>
			<dd><?php echo esc_html( $data['version'] ); ?></dd>
			<dt><?php esc_html_e( 'API host', 'afterware' ); ?></dt>
			<dd><code class="afterware-mono"><?php echo esc_html( $data['api_host'] ); ?></code></dd>
			<dt><?php esc_html_e( 'Documentation', 'afterware' ); ?></dt>
			<dd><a href="<?php echo esc_url( $data['docs_url'] ); ?>"><?php esc_html_e( 'How Afterware Connect works', 'afterware' ); ?></a></dd>
		</dl>
	</div>
</div>
