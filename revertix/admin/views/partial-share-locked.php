<?php
/**
 * Locked preview of the share-link controls.
 *
 * Shown when the Revertix PRO add-on is not present. The fields are drawn so the
 * option is discoverable and disabled so it is obvious they are not available -
 * and the whole block opens the plans dialog when clicked, like every other
 * previewed PRO feature.
 *
 * The wording matters here: the link itself works perfectly well without PRO, so
 * this must read as "these settings are extra", never as "you cannot share".
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="revertix-share-locked" role="button" tabindex="0" data-revertix-locked="share_options"
	title="<?php esc_attr_e( 'Choosing the expiry, a download limit or a password is part of Revertix PRO.', 'revertix' ); ?>">

	<div class="revertix-shareopt revertix-opt--locked">
		<span class="revertix-shareopt__label"><?php esc_html_e( 'Link expires', 'revertix' ); ?></span>
		<select class="revertix-input" disabled>
			<option><?php esc_html_e( 'In 7 days', 'revertix' ); ?></option>
		</select>
	</div>

	<div class="revertix-shareopt revertix-opt--locked">
		<span class="revertix-shareopt__label"><?php esc_html_e( 'Maximum downloads', 'revertix' ); ?></span>
		<input type="text" class="revertix-input" value="<?php esc_attr_e( 'No limit', 'revertix' ); ?>" disabled>
	</div>

	<div class="revertix-shareopt revertix-opt--locked">
		<span class="revertix-shareopt__label"><?php esc_html_e( 'Password', 'revertix' ); ?></span>
		<input type="text" class="revertix-input" value="<?php esc_attr_e( 'None', 'revertix' ); ?>" disabled>
	</div>

	<p class="revertix-share-locked__note">
		<span class="revertix-badge revertix-badge--pro"><?php esc_html_e( 'PRO', 'revertix' ); ?></span>
		<?php esc_html_e( 'Changing these is part of Revertix PRO. See the plans.', 'revertix' ); ?>
	</p>
</div>

<p class="revertix-hint revertix-hint--ok">
	<span class="dashicons dashicons-yes-alt"></span>
	<?php esc_html_e( 'You can create the link right now. It will last 7 days, and you can revoke it whenever you like.', 'revertix' ); ?>
</p>
