<?php
/**
 * The "type this word to confirm" field the switch, revert and disconnect
 * forms share.
 *
 * The field is required, so the form cannot be submitted empty even with the
 * script off; the server compares it to the word again either way.
 *
 * @package Afterware
 *
 * @var string $afterware_confirm_id    Unique id for this instance of the field.
 * @var string $afterware_confirm_word  The word that has to be typed.
 * @var string $afterware_confirm_label The label above the field.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="afterware-confirm">
	<label for="<?php echo esc_attr( $afterware_confirm_id ); ?>"><?php echo esc_html( $afterware_confirm_label ); ?></label>
	<input
		type="text"
		id="<?php echo esc_attr( $afterware_confirm_id ); ?>"
		name="confirm"
		class="regular-text afterware-mono"
		required
		autocomplete="off"
		spellcheck="false"
		aria-describedby="<?php echo esc_attr( $afterware_confirm_id ); ?>-help"
		data-afterware-confirm="<?php echo esc_attr( $afterware_confirm_word ); ?>"
	/>
	<span class="description" id="<?php echo esc_attr( $afterware_confirm_id ); ?>-help">
		<?php
		echo wp_kses(
			sprintf(
				/* translators: %s: the word to type out, shown in a monospaced font. */
				__( 'Type %s to confirm.', 'afterware' ),
				'<code class="afterware-mono">' . esc_html( $afterware_confirm_word ) . '</code>'
			),
			array( 'code' => array( 'class' => array() ) )
		);
		?>
	</span>
</p>
