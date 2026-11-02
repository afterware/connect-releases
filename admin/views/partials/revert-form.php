<?php
/**
 * The revert form, or the reason there cannot be one.
 *
 * Its own local, deliberately: the plugin screen has already worked out why a
 * switch is blocked, and reusing that variable here would quietly overwrite it.
 *
 * @package Afterware
 *
 * @var array<string,mixed> $data From Admin_Data::plugin().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$afterware_revert_blocked = is_wp_error( $data['can_revert'] ) ? $data['can_revert']->get_error_message() : '';
if ( ! $data['may_manage'] ) {
	$afterware_revert_blocked = __( 'Only administrators who can install plugins can revert.', 'afterware' );
}
?>
<?php if ( null !== $data['backup_path'] ) : ?>
	<p class="afterware-backup">
		<?php if ( null !== $data['record']['switched_at'] ) : ?>
			<?php esc_html_e( 'Backup kept since', 'afterware' ); ?>
			<?php
			$afterware_time = $data['record']['switched_at'];
			require __DIR__ . '/time.php';
			?>
			<?php esc_html_e( 'at', 'afterware' ); ?>
		<?php else : ?>
			<?php esc_html_e( 'The backup is at', 'afterware' ); ?>
		<?php endif; ?>
		<code class="afterware-mono"><?php echo esc_html( $data['backup_path'] ); ?></code>
	</p>
<?php endif; ?>

<?php if ( '' !== $afterware_revert_blocked ) : ?>
	<p class="afterware-blocked"><?php echo esc_html( $afterware_revert_blocked ); ?></p>
<?php else : ?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="afterware-form">
		<?php wp_nonce_field( 'afterware_revert', '_wpnonce_afterware_revert' ); ?>
		<input type="hidden" name="action" value="afterware_revert" />
		<input type="hidden" name="slug" value="<?php echo esc_attr( $data['slug'] ); ?>" />
		<?php
		$afterware_confirm_id    = 'afterware-revert-confirm';
		$afterware_confirm_word  = $data['slug'];
		$afterware_confirm_label = __( 'Type the plugin slug to confirm', 'afterware' );
		require __DIR__ . '/typed-confirm.php';
		?>
		<button type="submit" class="button afterware-button--danger" data-afterware-confirm-for="<?php echo esc_attr( $afterware_confirm_id ); ?>">
			<?php
			printf(
				/* translators: %s: the version of the plugin Afterware replaced. */
				esc_html__( 'Revert to upstream %s', 'afterware' ),
				esc_html( $data['record']['upstream_version'] )
			);
			?>
		</button>
	</form>
<?php endif; ?>
