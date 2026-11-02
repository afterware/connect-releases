<?php
/**
 * One plugin: what a switch would do, how to do it, and how to undo it.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data From Admin_Data::plugin().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$afterware_blocked = is_wp_error( $data['can_switch'] ) ? $data['can_switch']->get_error_message() : '';
$afterware_version = $data['latest'];

/*
 * Nothing verified is cached yet, but the last scan named a build. Drawing a
 * screen may not fetch one, so the offer is made on the report's word and
 * labelled as such; pressing the button refreshes the manifest and verifies it
 * before anything is installed, and stops there if it does not check out.
 */
$afterware_unverified = null === $data['latest']
	&& null !== $data['report_latest']
	&& is_wp_error( $data['can_switch'] )
	&& 'afterware_no_build' === $data['can_switch']->get_error_code();

if ( $afterware_unverified ) {
	$afterware_version = $data['report_latest'];
	$afterware_blocked = '';
}
if ( ! $data['may_manage'] ) {
	$afterware_blocked = __( 'Only administrators who can install plugins can switch.', 'afterware' );
}

if ( ! $data['known'] ) {
	// No slug, or one nothing on this site answers to. A header naming nothing
	// and an empty activity list would only make someone wonder what they broke.
	?>
	<div class="wrap afterware">
		<h1><?php esc_html_e( 'Afterware', 'afterware' ); ?></h1>
		<div class="afterware-card afterware-card--attention">
			<h2><?php esc_html_e( 'Which plugin?', 'afterware' ); ?></h2>
			<p><?php esc_html_e( 'Afterware does not know which plugin you meant. Nothing is installed under that name on this site, and Afterware has no record of switching one.', 'afterware' ); ?></p>
			<p><a href="<?php echo esc_url( \Afterware\Admin\Admin::page_url() ); ?>"><?php esc_html_e( 'Back to the overview', 'afterware' ); ?></a></p>
		</div>
	</div>
	<?php
	return;
}
?>
<div class="wrap afterware">
	<div class="afterware-header">
		<h1 class="wp-heading-inline"><?php echo esc_html( $data['name'] ); ?></h1>
		<?php
		$afterware_chip = $data['chip'];
		require __DIR__ . '/partials/chip.php';
		?>
	</div>
	<p class="afterware-subhead">
		<code class="afterware-mono"><?php echo esc_html( $data['slug'] ); ?></code>
		<?php if ( '' !== $data['installed'] ) : ?>
			<span class="afterware-subhead__version">
				<?php
				printf(
					/* translators: %s: version number. */
					esc_html__( 'Version %s installed', 'afterware' ),
					esc_html( $data['installed'] )
				);
				?>
			</span>
		<?php endif; ?>
	</p>

	<?php if ( $data['pending'] ) : ?>

		<div class="afterware-card afterware-card--danger">
			<h2><?php esc_html_e( 'Unfinished switch', 'afterware' ); ?></h2>
			<p><?php esc_html_e( 'A previous switch did not finish. Revert to restore the upstream copy.', 'afterware' ); ?></p>
			<p><?php esc_html_e( 'Afterware made a copy of this plugin before it started, and that copy is still on disk. Reverting puts it back exactly as it was, including whether the plugin was switched on.', 'afterware' ); ?></p>
			<?php require __DIR__ . '/partials/revert-form.php'; ?>
		</div>

	<?php else : ?>

		<?php if ( ! $data['on_afterware'] && ! $data['can_verify'] ) : ?>
			<?php /* No pinned signing keys: nothing this build downloads could be checked, so it offers nothing and says why once. */ ?>
			<div class="afterware-card afterware-card--attention">
				<h2><?php esc_html_e( 'Updates cannot be verified', 'afterware' ); ?></h2>
				<p><?php esc_html_e( 'This build of Afterware Connect cannot verify updates yet. Update Afterware Connect when a new version is available.', 'afterware' ); ?></p>
			</div>
		<?php elseif ( $data['on_afterware'] ) : ?>
			<div class="afterware-card">
				<h2><?php esc_html_e( 'On the Afterware build', 'afterware' ); ?></h2>
				<?php if ( ! $data['can_verify'] ) : ?>
					<?php /* No pinned keys: "up to date" would be a claim this build has no way to make. */ ?>
					<p><?php esc_html_e( 'Updates cannot be checked on this build of Afterware Connect.', 'afterware' ); ?></p>
				<?php elseif ( $data['update_available'] ) : ?>
					<p>
						<?php
						printf(
							/* translators: %s: version number. */
							esc_html__( 'Update available: %s.', 'afterware' ),
							esc_html( (string) $data['latest'] )
						);
						?>
						<a href="<?php echo esc_url( $data['updates_url'] ); ?>"><?php esc_html_e( 'Install it from the WordPress updates screen', 'afterware' ); ?></a>
					</p>
				<?php else : ?>
					<p><?php esc_html_e( 'Up to date. This is the newest build Afterware has signed.', 'afterware' ); ?></p>
				<?php endif; ?>
			</div>
		<?php else : ?>
			<div class="afterware-card">
				<h2><?php esc_html_e( 'What changes when you switch', 'afterware' ); ?></h2>
				<dl class="afterware-changes">
					<dt><?php esc_html_e( 'Display name', 'afterware' ); ?></dt>
					<dd><?php esc_html_e( 'Gets “(Afterware maintained)” added, so you can tell the two apart at a glance.', 'afterware' ); ?></dd>

					<dt><?php esc_html_e( 'Updates', 'afterware' ); ?></dt>
					<dd><?php esc_html_e( 'Come from updates.afterware.io and are signed. Anything that does not verify is refused, never installed.', 'afterware' ); ?></dd>

					<dt><?php esc_html_e( 'Your settings and data', 'afterware' ); ?></dt>
					<dd><?php esc_html_e( 'Afterware replaces plugin files. The new build may run database upgrades; keep a full site backup.', 'afterware' ); ?></dd>

					<dt><?php esc_html_e( 'A backup', 'afterware' ); ?></dt>
					<dd>
						<?php esc_html_e( 'A backup zip of the copy you have now is kept at', 'afterware' ); ?>
						<code class="afterware-mono"><?php echo esc_html( $data['backup_location'] ); ?></code>.
					</dd>

					<dt><?php esc_html_e( 'Going back', 'afterware' ); ?></dt>
					<dd><?php esc_html_e( 'Revert restores the saved plugin files. It does not undo database changes.', 'afterware' ); ?></dd>
				</dl>
			</div>

			<div class="afterware-card afterware-card--action">
				<h2><?php esc_html_e( 'Switch to Afterware', 'afterware' ); ?></h2>
				<?php if ( null === $afterware_version ) : ?>
					<p class="afterware-blocked"><?php echo esc_html( '' === $afterware_blocked ? __( 'Afterware has no verified build of this plugin to switch to yet.', 'afterware' ) : $afterware_blocked ); ?></p>
				<?php else : ?>
					<?php if ( '' !== $afterware_blocked ) : ?>
						<p class="afterware-blocked"><?php echo esc_html( $afterware_blocked ); ?></p>
					<?php endif; ?>
					<?php if ( $afterware_unverified ) : ?>
						<p><?php esc_html_e( 'This site has not fetched and checked the signature on this build yet. Switching fetches it first and stops if it does not verify.', 'afterware' ); ?></p>
					<?php endif; ?>
					<?php if ( $data['show_switch_form'] ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-form">
						<?php wp_nonce_field( 'afterware_switch', '_wpnonce_afterware_switch' ); ?>
						<input type="hidden" name="action" value="afterware_switch" />
						<input type="hidden" name="slug" value="<?php echo esc_attr( $data['slug'] ); ?>" />
						<?php
						$afterware_confirm_id    = 'afterware-switch-confirm';
						$afterware_confirm_word  = $data['slug'];
						$afterware_confirm_label = __( 'Type the plugin slug to confirm', 'afterware' );
						require __DIR__ . '/partials/typed-confirm.php';
						?>
						<button
							type="submit"
							class="button button-primary"
							data-afterware-confirm-for="<?php echo esc_attr( $afterware_confirm_id ); ?>"
							<?php echo '' === $afterware_blocked ? '' : 'disabled'; ?>
						>
							<?php
							printf(
								/* translators: %s: version number. */
								esc_html__( 'Switch to Afterware build %s', 'afterware' ),
								esc_html( (string) $afterware_version )
							);
							?>
						</button>
					</form>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( null !== $data['record'] ) : ?>
			<div class="afterware-card afterware-card--danger">
				<h2><?php esc_html_e( 'Danger zone', 'afterware' ); ?></h2>
				<p><?php esc_html_e( 'Reverting restores the saved upstream files and stops Afterware updating this plugin. It does not undo database changes made by the maintained build.', 'afterware' ); ?></p>
				<?php require __DIR__ . '/partials/revert-form.php'; ?>
			</div>
		<?php endif; ?>

	<?php endif; ?>

	<div class="afterware-card">
		<h2><?php esc_html_e( 'Activity for this plugin', 'afterware' ); ?></h2>
		<?php if ( array() === $data['activity'] ) : ?>
			<p><?php esc_html_e( 'Nothing has happened to this plugin yet.', 'afterware' ); ?></p>
		<?php else : ?>
			<ul class="afterware-activity">
				<?php foreach ( $data['activity'] as $afterware_entry ) : ?>
					<li class="afterware-activity__item">
						<?php
						$afterware_chip = $afterware_entry['chip'];
						require __DIR__ . '/partials/chip.php';
						?>
						<span class="afterware-activity__text"><?php echo esc_html( $afterware_entry['text'] ); ?></span>
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
