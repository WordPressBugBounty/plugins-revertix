<?php
/**
 * Main plugin bootstrap (singleton). Wires modules to WordPress.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Revertix core.
 */
final class Revertix {

	/**
	 * Singleton instance.
	 *
	 * @var Revertix|null
	 */
	private static $instance = null;

	/**
	 * Admin controller.
	 *
	 * @var Revertix_Admin
	 */
	public $admin;

	/**
	 * AJAX controller.
	 *
	 * @var Revertix_Ajax
	 */
	public $ajax;

	/**
	 * Get the singleton.
	 *
	 * @return Revertix
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor: load text domain and instantiate controllers.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		// Daily log pruning (honours the retention setting). Ensure it is
		// scheduled even on sites activated before this feature existed.
		add_action( Revertix_Log::CRON_HOOK, array( 'Revertix_Log', 'prune' ) );
		add_action( Revertix_Log::CRON_HOOK, array( 'Revertix_Storage', 'cleanup_tmp' ) );
		add_action( Revertix_Log::CRON_HOOK, array( 'Revertix_Storage', 'clear_stale_maintenance' ) );
		Revertix_Log::schedule();

		$this->ajax = new Revertix_Ajax();

		// Public download links for export packages (front end + admin).
		new Revertix_Share();

		// Admin-bar env badge/switcher runs on the front end too (and on staging).
		new Revertix_Admin_Bar();

		if ( is_admin() ) {
			$this->admin = new Revertix_Admin();
		}
	}

	/**
	 * Load translations.
	 *
	 * Since WordPress 4.6, translations for plugins hosted on WordPress.org load
	 * automatically, so no manual load_plugin_textdomain() call is required.
	 */
	public function load_textdomain() {}

	/**
	 * Prevent cloning.
	 */
	private function __clone() {}

	/**
	 * Prevent unserialization.
	 */
	public function __wakeup() {}
}
