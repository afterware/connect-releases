<?php
/**
 * Tickets: what has been asked, and the form for asking something new.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data From Admin_Data::tickets().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$afterware_draft    = $data['draft'];
$afterware_selected = $afterware_draft['restored'] ? $afterware_draft['plugin_slug'] : $data['selected'];
?>
<div class="wrap afterware">
	<h1><?php esc_html_e( 'Afterware tickets', 'afterware' ); ?></h1>
	<p class="afterware-lede"><?php esc_html_e( 'Tickets go to the people who maintain these builds. Every ticket carries what versions this site is running, so you do not have to describe your setup.', 'afterware' ); ?></p>

	<div class="afterware-card">
		<h2><?php esc_html_e( 'Open and past tickets', 'afterware' ); ?></h2>
		<?php if ( array() === $data['tickets'] ) : ?>
			<p><?php esc_html_e( 'No tickets yet. Nothing has needed one.', 'afterware' ); ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped afterware-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Opened', 'afterware' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Kind', 'afterware' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Subject', 'afterware' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Plugin', 'afterware' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $data['tickets'] as $afterware_ticket ) : ?>
						<tr>
							<td>
								<?php
								$afterware_time = (int) $afterware_ticket['created_at'];
								require __DIR__ . '/partials/time.php';
								?>
							</td>
							<td>
								<?php
								$afterware_chip = $afterware_ticket['chip'];
								require __DIR__ . '/partials/chip.php';
								?>
							</td>
							<td><?php echo esc_html( (string) $afterware_ticket['subject'] ); ?></td>
							<td>
								<?php if ( null === $afterware_ticket['slug'] ) : ?>
									<?php esc_html_e( 'General', 'afterware' ); ?>
								<?php else : ?>
									<code class="afterware-mono"><?php echo esc_html( (string) $afterware_ticket['slug'] ); ?></code>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>

	<div class="afterware-card afterware-card--action">
		<h2><?php esc_html_e( 'New ticket', 'afterware' ); ?></h2>
		<?php if ( ! $data['connected'] ) : ?>
			<p><?php esc_html_e( 'This site is not connected to Afterware yet, so there is nowhere to send a ticket. Connect it from the overview first.', 'afterware' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-form">
				<?php wp_nonce_field( 'afterware_ticket_new', '_wpnonce_afterware_ticket_new' ); ?>
				<?php if ( $afterware_draft['restored'] ) : ?>
					<p class="afterware-emphasis"><?php esc_html_e( 'Nothing was lost. What you wrote is still here.', 'afterware' ); ?></p>
				<?php endif; ?>
				<input type="hidden" name="action" value="afterware_ticket_new" />

				<p>
					<label for="afterware-ticket-plugin"><?php esc_html_e( 'Which plugin?', 'afterware' ); ?></label>
					<select id="afterware-ticket-plugin" name="plugin">
						<option value=""><?php esc_html_e( 'General — not about one plugin', 'afterware' ); ?></option>
						<?php foreach ( $data['plugins'] as $afterware_slug => $afterware_name ) : ?>
							<option value="<?php echo esc_attr( $afterware_slug ); ?>" <?php selected( $afterware_selected, $afterware_slug ); ?>>
								<?php echo esc_html( $afterware_name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<p>
					<label for="afterware-ticket-kind"><?php esc_html_e( 'What kind of ticket?', 'afterware' ); ?></label>
					<select id="afterware-ticket-kind" name="kind">
						<?php foreach ( $data['kinds'] as $afterware_key => $afterware_label ) : ?>
							<option value="<?php echo esc_attr( $afterware_key ); ?>" <?php selected( $afterware_draft['kind'], $afterware_key ); ?>><?php echo esc_html( $afterware_label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p>
					<label for="afterware-ticket-subject"><?php esc_html_e( 'Subject', 'afterware' ); ?></label>
					<input type="text" id="afterware-ticket-subject" name="subject" class="regular-text" required maxlength="200" value="<?php echo esc_attr( $afterware_draft['subject'] ); ?>" />
				</p>

				<p>
					<label for="afterware-ticket-body"><?php esc_html_e( 'What happened?', 'afterware' ); ?></label>
					<textarea id="afterware-ticket-body" name="body" rows="6" class="large-text" required><?php echo esc_textarea( $afterware_draft['body'] ); ?></textarea>
					<span class="description"><?php esc_html_e( 'What you did, what you expected, and what happened instead.', 'afterware' ); ?></span>
				</p>

				<?php if ( null !== $data['log_tail'] ) : ?>
					<p>
						<label for="afterware-ticket-log">
							<input type="checkbox" id="afterware-ticket-log" name="share_debug_log" value="1" <?php checked( $afterware_draft['restored'] ? $afterware_draft['include_log'] : $data['share_debug_log'] ); ?> />
							<?php esc_html_e( 'Include the last 200 lines of debug.log with this ticket', 'afterware' ); ?>
						</label>
					</p>
					<details class="afterware-log">
						<summary><?php esc_html_e( 'Show the exact lines that would be sent', 'afterware' ); ?></summary>
						<pre class="afterware-mono"><?php echo esc_html( (string) $data['log_tail'] ); ?></pre>
					</details>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'There is no debug.log on this site, so there are no log lines to attach.', 'afterware' ); ?></p>
				<?php endif; ?>

				<button type="submit" class="button button-primary"><?php esc_html_e( 'Open ticket', 'afterware' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
