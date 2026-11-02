<?php
/**
 * The Key ring card: which signing keys this site trusts, since when, and
 * whether the fetched ring is fresh.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data From Admin_Data::settings(); reads $data['key_ring'].
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$afterware_key_ring = $data['key_ring'];
?>
<div class="afterware-card">
	<h2><?php esc_html_e( 'Key ring', 'afterware' ); ?></h2>
	<p><?php esc_html_e( 'The signing keys this site trusts for Afterware manifests and packages. Afterware rotates these from time to time; this site checks for changes once a day.', 'afterware' ); ?></p>

	<?php if ( ! $afterware_key_ring['can_fetch'] ) : ?>
		<p><?php esc_html_e( 'This build trusts no signing keys yet. Updates from Afterware are refused until a release ships with keys, so there is nothing to fetch and nothing to check.', 'afterware' ); ?></p>
	<?php elseif ( $afterware_key_ring['stored_untrusted'] ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'A key ring is stored but is not trusted by this build, so it is being ignored and only the pinned keys count. Refreshing will replace it.', 'afterware' ); ?></p>
		</div>
	<?php elseif ( $afterware_key_ring['never_fetched'] ) : ?>
		<p><?php esc_html_e( 'The key ring has not been fetched yet. It will be, on the next daily check, or now.', 'afterware' ); ?></p>
	<?php elseif ( $afterware_key_ring['stale'] ) : ?>
		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'The key ring has not been refreshed in over 30 days. Afterware Connect still works, but a refresh is worth doing.', 'afterware' ); ?></p>
		</div>
	<?php endif; ?>

	<table class="wp-list-table widefat striped afterware-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Key', 'afterware' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'afterware' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Source', 'afterware' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Trusted since', 'afterware' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Trusted until', 'afterware' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php if ( array() === $afterware_key_ring['rows'] ) : ?>
				<tr>
					<td colspan="5"><?php esc_html_e( 'No signing keys are trusted yet.', 'afterware' ); ?></td>
				</tr>
			<?php endif; ?>
			<?php foreach ( $afterware_key_ring['rows'] as $afterware_key ) : ?>
				<tr>
					<td><code class="afterware-mono"><?php echo esc_html( $afterware_key['id'] ); ?></code></td>
					<td>
						<?php
						$afterware_chip = $afterware_key['chip'];
						require __DIR__ . '/chip.php';
						?>
					</td>
					<td><?php echo esc_html( $afterware_key['source'] ); ?></td>
					<td>
						<?php if ( null === $afterware_key['valid_from'] ) : ?>
							<?php esc_html_e( 'Always', 'afterware' ); ?>
						<?php else : ?>
							<?php echo esc_html( $afterware_key['valid_from'] ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( null === $afterware_key['valid_until'] ) : ?>
							<?php esc_html_e( 'No end date', 'afterware' ); ?>
						<?php else : ?>
							<?php echo esc_html( $afterware_key['valid_until'] ); ?>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="afterware-lastscan">
		<?php if ( ! $afterware_key_ring['can_fetch'] ) : ?>
			<?php esc_html_e( 'Nothing to fetch yet.', 'afterware' ); ?>
		<?php elseif ( null === $afterware_key_ring['fetched_at'] ) : ?>
			<?php esc_html_e( 'Never fetched.', 'afterware' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'Last refreshed', 'afterware' ); ?>
			<?php
			$afterware_time = $afterware_key_ring['fetched_at'];
			require __DIR__ . '/time.php';
			?>
		<?php endif; ?>
	</p>

	<?php if ( $afterware_key_ring['can_fetch'] ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'afterware_refresh_keys', '_wpnonce_afterware_refresh_keys' ); ?>
			<input type="hidden" name="action" value="afterware_refresh_keys" />
			<button type="submit" class="button"><?php esc_html_e( 'Refresh now', 'afterware' ); ?></button>
		</form>
	<?php endif; ?>
</div>
