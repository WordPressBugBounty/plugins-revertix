<?php
/**
 * Global helper functions.
 *
 * Everything in this plugin is fully functional on its own. The separate
 * Revertix PRO add-on (distributed outside wordpress.org) adds its own
 * features through the plain extension points used around the codebase
 * (e.g. `revertix_settings_update_extra`, `revertix_feature_unlocked`);
 * this file only holds shared helpers.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the Revertix PRO add-on is active on this site.
 *
 * Used ONLY for presentation (menu label, admin-bar label, which promo cards
 * to show, system-info report). No functionality in this plugin is gated on
 * it - every feature shipped here works the same with or without the add-on.
 *
 * @return bool
 */
function revertix_is_premium() {
	return (bool) apply_filters( 'revertix_is_premium', false );
}

/**
 * The capability required to manage Revertix. Filterable for advanced setups.
 *
 * @return string
 */
function revertix_capability() {
	return apply_filters( 'revertix_capability', 'manage_options' );
}

/**
 * Human-readable labels for backup-schedule frequency keys.
 *
 * @return array<string,string>
 */
function revertix_backup_frequency_labels() {
	return array(
		'monthly'  => __( 'Monthly', 'revertix' ),
		'biweekly' => __( 'Every 2 weeks', 'revertix' ),
		'weekly'   => __( 'Weekly', 'revertix' ),
		'daily'    => __( 'Daily', 'revertix' ),
		'daily_at' => __( 'Daily at a chosen hour', 'revertix' ),
		'custom'   => __( 'Custom interval (min. 1 hour)', 'revertix' ),
	);
}

/**
 * Turn HTML entities produced by WordPress' number formatting back into real
 * characters.
 * 
 * @param string $text Formatted text.
 * @return string
 */
function revertix_plain_entities( $text ) {
	return str_replace( '&nbsp;', "\xc2\xa0", (string) $text );
}

/**
 * number_format_i18n() that is safe to use outside HTML.
 *
 * @param float|int $number   Number to format.
 * @param int       $decimals Decimal places.
 * @return string
 */
function revertix_number( $number, $decimals = 0 ) {
	return revertix_plain_entities( number_format_i18n( $number, $decimals ) );
}

/**
 * size_format() that is safe to use outside HTML. size_format() formats through
 * number_format_i18n(), so it carries the same entity.
 *
 * @param int|string $bytes    Number of bytes.
 * @param int        $decimals Decimal places.
 * @return string Formatted size, or '' when the input is not usable.
 */
function revertix_size( $bytes, $decimals = 0 ) {
	$formatted = size_format( $bytes, $decimals );
	return ( false === $formatted ) ? '' : revertix_plain_entities( $formatted );
}
