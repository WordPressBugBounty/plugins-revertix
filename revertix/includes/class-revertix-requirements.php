<?php
/**
 * Runtime requirement checks (ZipArchive, PHP version, writable storage).
 *
 * Revertix must fail *gracefully* when something it needs is unavailable - never
 * a fatal error. This class produces a list of problems that the admin UI and
 * AJAX layer can surface with clear messages.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checks the environment and reports any blocking problems.
 */
class Revertix_Requirements {

	/**
	 * Whether the PHP ZipArchive extension is available.
	 *
	 * @return bool
	 */
	public static function has_zip_archive() {
		return class_exists( 'ZipArchive' );
	}

	/**
	 * Run all checks and return a list of problems. Empty array means OK.
	 *
	 * @return array<int,array{code:string,message:string}>
	 */
	public static function check() {
		$problems = array();

		if ( version_compare( PHP_VERSION, REVERTIX_MIN_PHP, '<' ) ) {
			$problems[] = array(
				'code'    => 'php_version',
				'message' => sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'Revertix requires PHP %1$s or higher. You are running PHP %2$s.', 'revertix' ),
					REVERTIX_MIN_PHP,
					PHP_VERSION
				),
			);
		}

		if ( ! self::has_zip_archive() ) {
			$problems[] = array(
				'code'    => 'zip_archive',
				'message' => __( 'The PHP ZipArchive extension is not available on this server. Revertix needs it to create and read packages. Please ask your host to enable the "zip" PHP extension.', 'revertix' ),
			);
		}

		if ( ! Revertix_Storage::is_writable() ) {
			$problems[] = array(
				'code'    => 'storage',
				'message' => sprintf(
					/* translators: %s: storage directory path. */
					__( 'The Revertix working directory is not writable: %s. Please check folder permissions on your uploads directory.', 'revertix' ),
					esc_html( Revertix_Storage::base_dir() )
				),
			);
		}

		return $problems;
	}

	/**
	 * Whether the environment is fully ready for heavy operations.
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return empty( self::check() );
	}

	/**
	 * Show an admin notice on Revertix screens if anything is wrong.
	 */
	public static function maybe_admin_notice() {
		$problems = self::check();

		if ( empty( $problems ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Revertix cannot run yet:', 'revertix' ) . '</strong></p><ul style="list-style:disc;margin-left:20px;">';
		foreach ( $problems as $problem ) {
			echo '<li>' . esc_html( $problem['message'] ) . '</li>';
		}
		echo '</ul></div>';
	}
}
