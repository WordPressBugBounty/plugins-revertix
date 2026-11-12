<?php
/**
 * Notifications: e-mail alerts for backup and remote-sync events.
 *
 * Available on free and Pro (off by default). send() only does anything when the
 * matching channel is enabled in settings, so call sites can fire freely without
 * guarding; this class decides whether anything goes out.
 *
 * Event types:
 *   - 'error'   : something failed (backup/sync error).
 *   - 'warning' : completed with caveats.
 *   - 'status'  : routine success ("backup finished").
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fan-out for alert channels.
 */
class Revertix_Notify {

	/**
	 * Dispatch an event to whatever channels are enabled.
	 *
	 * @param string $type    One of 'error' | 'warning' | 'status'.
	 * @param string $subject Short summary line.
	 * @param string $message Body / detail.
	 */
	public static function send( $type, $subject, $message ) {
		$type = in_array( $type, array( 'error', 'warning', 'status' ), true ) ? $type : 'status';

		self::send_email( $type, $subject, $message );
	}

	/**
	 * E-mail channel, gated per event type.
	 *
	 * @param string $type    Event type.
	 * @param string $subject Subject.
	 * @param string $message Body.
	 */
	protected static function send_email( $type, $subject, $message ) {
		$enabled = array(
			'error'   => (bool) Revertix_Settings::get( 'notify_email_errors' ),
			'warning' => (bool) Revertix_Settings::get( 'notify_email_warnings' ),
			'status'  => (bool) Revertix_Settings::get( 'notify_email_status' ),
		);
		if ( empty( $enabled[ $type ] ) ) {
			return;
		}

		$to = Revertix_Settings::notify_email_address();
		if ( '' === $to ) {
			return;
		}

		$site    = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
		$subject = sprintf( '[Revertix · %1$s] %2$s', $site, $subject );

		$as_html = (bool) Revertix_Settings::get( 'notify_email_html' );
		if ( $as_html ) {
			$body    = '<p>' . nl2br( esc_html( $message ) ) . '</p>';
			$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		} else {
			$body    = $message;
			$headers = array();
		}

		wp_mail( $to, $subject, $body, $headers );
	}
}
