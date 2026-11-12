<?php
/**
 * Reusable progress bar markup, driven by the JS AJAX loop.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="revertix-progress" id="revertix-export-progress" hidden>
	<div class="revertix-progress__bar">
		<div class="revertix-progress__fill" id="revertix-export-fill" style="width:0%"></div>
	</div>
	<div class="revertix-progress__meta">
		<span class="revertix-progress__label" id="revertix-export-label"><?php esc_html_e( 'Starting…', 'revertix' ); ?></span>
		<span class="revertix-progress__pct" id="revertix-export-pct">0%</span>
	</div>
</div>
