<?php
/**
 * The overview: what Afterware knows about this site's plugins.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data From Admin_Data::overview().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap afterware">
	<div class="afterware-header">
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Afterware', 'afterware' ); ?></h1>
		<?php
		$afterware_chip = $data['connected']
			? array(
				'tone'  => 'ok',
				'label' => __( 'Connected', 'afterware' ),
			)
			: array(
				'tone'  => 'danger',
				'label' => __( 'Not connected', 'afterware' ),
			);
		require __DIR__ . '/partials/chip.php';
		?>
		<?php if ( $data['connected'] ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-header__action">
				<?php wp_nonce_field( 'afterware_scan', '_wpnonce_afterware_scan' ); ?>
				<input type="hidden" name="action" value="afterware_scan" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Scan now', 'afterware' ); ?></button>
			</form>
		<?php endif; ?>
	</div>

	<p class="afterware-lede">
		<?php esc_html_e( 'Afterware looks after plugins whose authors stopped. This is what it knows about yours.', 'afterware' ); ?>
	</p>

	<?php if ( $data['connected'] ) : ?>
		<p><a href="<?php echo esc_url( $data['claim_settings_url'] ); ?>"><?php esc_html_e( 'Connect this site to your account', 'afterware' ); ?></a></p>
	<?php endif; ?>

	<?php if ( ! $data['connected'] ) : ?>
		<?php require __DIR__ . '/partials/notice-connection.php'; ?>
	<?php elseif ( ! $data['has_report'] ) : ?>
		<div class="afterware-card afterware-card--attention">
			<h2><?php esc_html_e( 'No scan yet', 'afterware' ); ?></h2>
			<p><?php esc_html_e( 'Afterware has not looked at this site yet. A scan sends the list of plugins and themes that are installed and brings back what Afterware knows about each one.', 'afterware' ); ?></p>
			<p><?php esc_html_e( 'After the first one, scans run once a day on their own.', 'afterware' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'afterware_scan', '_wpnonce_afterware_scan' ); ?>
				<input type="hidden" name="action" value="afterware_scan" />
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Scan now', 'afterware' ); ?></button>
			</form>
		</div>
	<?php else : ?>
		<div class="afterware-cards">
			<?php foreach ( $data['cards'] as $afterware_card ) : ?>
				<a class="afterware-card afterware-card--filter<?php echo $afterware_card['active'] ? ' is-active' : ''; ?>"
					href="<?php echo esc_url( $afterware_card['url'] ); ?>"
					data-afterware-filter="<?php echo esc_attr( $afterware_card['filter'] ); ?>"
					<?php echo $afterware_card['active'] ? 'aria-current="true"' : ''; ?>>
					<span class="afterware-card__count"><?php echo esc_html( number_format_i18n( $afterware_card['count'] ) ); ?></span>
					<span class="afterware-card__title"><?php echo esc_html( $afterware_card['title'] ); ?></span>
					<span class="afterware-card__blurb"><?php echo esc_html( $afterware_card['blurb'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>

		<?php if ( '' !== $data['filter'] ) : ?>
			<p class="afterware-filter-note">
				<a href="<?php echo esc_url( $data['reset_url'] ); ?>" data-afterware-filter=""><?php esc_html_e( 'Show every plugin', 'afterware' ); ?></a>
			</p>
		<?php endif; ?>

		<table class="wp-list-table widefat striped afterware-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Plugin', 'afterware' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Installed', 'afterware' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'afterware' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Afterware latest', 'afterware' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Action', 'afterware' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( array() === $data['rows'] ) : ?>
					<tr>
						<td colspan="5">
							<?php if ( '' === $data['filter'] ) : ?>
								<?php esc_html_e( 'No plugins found.', 'afterware' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'No plugins match this filter.', 'afterware' ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endif; ?>
				<?php foreach ( $data['rows'] as $afterware_row ) : ?>
					<tr data-afterware-filters="<?php echo esc_attr( implode( ' ', $afterware_row['filters'] ) ); ?>">
						<td><strong><?php echo esc_html( $afterware_row['name'] ); ?></strong><br /><code class="afterware-mono"><?php echo esc_html( $afterware_row['slug'] ); ?></code></td>
						<td><?php echo esc_html( $afterware_row['installed'] ); ?></td>
						<td>
							<?php
							$afterware_chip = $afterware_row['chip'];
							require __DIR__ . '/partials/chip.php';
							?>
						</td>
						<td<?php echo '' === $afterware_row['latest']['title'] ? '' : ' title="' . esc_attr( $afterware_row['latest']['title'] ) . '"'; ?>>
							<?php echo esc_html( $afterware_row['latest']['text'] ); ?>
							<?php if ( '' !== $afterware_row['latest']['note'] ) : ?>
								<span class="afterware-note"><?php echo esc_html( $afterware_row['latest']['note'] ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<a class="button <?php echo $afterware_row['can_switch'] ? 'button-primary' : ''; ?>" href="<?php echo esc_url( $afterware_row['url'] ); ?>">
								<?php echo $afterware_row['can_switch'] ? esc_html__( 'Switch', 'afterware' ) : esc_html__( 'View', 'afterware' ); ?>
								<span class="screen-reader-text"><?php echo esc_html( $afterware_row['name'] ); ?></span>
							</a>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( null !== $data['received_at'] ) : ?>
			<p class="afterware-lastscan">
				<?php esc_html_e( 'Last scanned', 'afterware' ); ?>
				<?php
				$afterware_time = $data['received_at'];
				require __DIR__ . '/partials/time.php';
				?>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</div>
