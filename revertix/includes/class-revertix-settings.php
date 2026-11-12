<?php
/**
 * Revertix settings: a single option holding every user-configurable preference.
 *
 * One stored option (revertix_settings). This plugin owns and handles its own
 * settings (safety retention, staging auth, notifications, uninstall cleanup).
 * The option also carries keys belonging to the separate Revertix PRO add-on
 * (staging bar colour, staging access users + roles, snapshot retention);
 * those are sanitised/applied by the add-on through the filters below and are
 * only stored here so they clone together with the site.
 *
 * The staging-auth and bar-colour settings are read on the staging site itself
 * (its cloned copy of this option), so changing them there changes that site's
 * behaviour - matching how the login-protection mu-plugin reads the option.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings store + gating helpers.
 */
class Revertix_Settings {

	const OPTION = 'revertix_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array<string,mixed>
	 */
	public static function defaults() {
		return array(
			// Backups.
			'safety_keep'              => 3,        // Keep the last N safety backups (min 1).

			// Rollback.
			'rollback_keep'            => 0,        // Snapshots to keep per item (0 = all). Pro.

			// Staging.
			'staging_bar_color'        => '#d97706', // Admin-bar colour on staging (Pro).
			'staging_access_users'     => '',        // Comma-separated usernames (Pro).
			'staging_access_roles'     => array(),   // Role slugs with access (Pro).
			'staging_disable_auth'     => false,     // Drop the staging login wall (free+pro).

			// Notifications (free+pro, off by default).
			'notify_email_errors'      => false,
			'notify_email_warnings'    => false,
			'notify_email_status'      => false,
			'notify_email_address'     => '',        // Empty falls back to the admin email.
			'notify_email_html'        => true,

			// Uninstall.
			'remove_data_on_uninstall' => false,
		);
	}

	/**
	 * Settings owned by the separate Revertix PRO add-on. Used by the settings
	 * template to render them as previews with an upgrade hint when the add-on
	 * is not installed (the add-on itself handles saving/applying them).
	 *
	 * @return string[]
	 */
	public static function pro_keys() {
		return array(
			'rollback_keep',
			'staging_bar_color',
			'staging_access_users',
			'staging_access_roles',
		);
	}

	/**
	 * All settings, user values merged over the defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$opt = get_option( self::OPTION, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		return wp_parse_args( $opt, self::defaults() );
	}

	/**
	 * One setting value.
	 *
	 * @param string $key Setting key.
	 * @return mixed|null
	 */
	public static function get( $key ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : null;
	}

	/**
	 * Validate + persist a partial set of incoming values. Values are
	 * rejected/clamped per field; add-on-owned keys are handled by the add-on
	 * through the revertix_settings_update_extra filter.
	 *
	 * @param array<string,mixed> $input Raw incoming values.
	 * @return array<string,mixed> The full, saved settings array.
	 */
	public static function update( array $input ) {
		$out = self::all();

		if ( isset( $input['safety_keep'] ) ) {
			$out['safety_keep'] = max( 1, min( 50, (int) $input['safety_keep'] ) );
		}
		$out['staging_disable_auth']     = ! empty( $input['staging_disable_auth'] );
		$out['remove_data_on_uninstall'] = ! empty( $input['remove_data_on_uninstall'] );

		// Notifications - free + Pro, off by default.
		$out['notify_email_errors']   = ! empty( $input['notify_email_errors'] );
		$out['notify_email_warnings'] = ! empty( $input['notify_email_warnings'] );
		$out['notify_email_status']   = ! empty( $input['notify_email_status'] );
		$out['notify_email_html']     = ! empty( $input['notify_email_html'] );

		if ( isset( $input['notify_email_address'] ) ) {
			$addr                        = sanitize_email( (string) $input['notify_email_address'] );
			$out['notify_email_address'] = $addr ? $addr : '';
		}

		/**
		 * Let add-ons sanitise and store the settings keys they own (see
		 * pro_keys()). Receives the outgoing settings and the raw input.
		 *
		 * @param array $out   Settings about to be saved.
		 * @param array $input Raw incoming values.
		 */
		$filtered = apply_filters( 'revertix_settings_update_extra', $out, $input );
		if ( is_array( $filtered ) ) {
			$out = $filtered;
		}

		update_option( self::OPTION, $out, false );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Typed accessors (used by the wiring around the codebase)
	 * ------------------------------------------------------------------- */

	/**
	 * How many safety backups to keep (always at least 1).
	 *
	 * @return int
	 */
	public static function safety_keep() {
		return max( 1, (int) self::get( 'safety_keep' ) );
	}

	/**
	 * The staging admin-bar colour. The default amber keeps staging clearly
	 * marked; the Revertix PRO add-on supplies a custom colour via the filter.
	 *
	 * @return string Hex colour.
	 */
	public static function staging_bar_color() {
		$color = sanitize_hex_color( (string) apply_filters( 'revertix_staging_bar_color', '' ) );
		return $color ? $color : '#d97706';
	}

	/**
	 * Whether the staging login wall is switched off.
	 *
	 * @return bool
	 */
	public static function staging_auth_disabled() {
		return (bool) self::get( 'staging_disable_auth' );
	}

	/**
	 * Where notification e-mails go (falls back to the site admin email).
	 *
	 * @return string
	 */
	public static function notify_email_address() {
		$addr = (string) self::get( 'notify_email_address' );
		return '' !== $addr ? $addr : (string) get_option( 'admin_email' );
	}

	/**
	 * Whether destructive uninstall cleanup is enabled (default off).
	 *
	 * @return bool
	 */
	public static function remove_data_on_uninstall() {
		return (bool) self::get( 'remove_data_on_uninstall' );
	}

}
