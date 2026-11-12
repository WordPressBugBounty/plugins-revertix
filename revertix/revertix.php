<?php
/**
 * Plugin Name:       Revertix
 * Plugin URI:        https://nitramix.com/projects/revertix
 * Description:       Staging, backup and migration for WordPress. Export/import your whole site, create a staging copy, and take manual backups - all processed in resumable chunks so large sites never hit a PHP timeout.
 * Version:           1.0.2
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Nitramix, Martin Valchev
 * Author URI:        https://nitramix.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       revertix
 * Domain Path:       /languages
 *
 * @package Revertix
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * ---------------------------------------------------------------------------
 * Constants - single source of truth for paths, version and the upgrade URL.
 * ---------------------------------------------------------------------------
 */
define( 'REVERTIX_VERSION', '1.0.2' );

// Package format version. Bump only when the package/manifest layout changes.
define( 'REVERTIX_PACKAGE_FORMAT', 1 );

define( 'REVERTIX_PLUGIN_FILE', __FILE__ );
define( 'REVERTIX_PLUGIN_DIR', plugin_dir_path( __FILE__ ) ); // .../revertix/ (trailing slash).
define( 'REVERTIX_PLUGIN_URL', plugin_dir_url( __FILE__ ) );  // URL with trailing slash.
define( 'REVERTIX_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

define( 'REVERTIX_MIN_PHP', '7.4' );

/**
 * Outbound URLs to the Nitramix store/account. Single source of truth - these
 * are the only places that need changing when the store moves.
 *
 * - REVERTIX_ACCOUNT_URL  : sign in / manage the customer account.
 * - REVERTIX_LICENSES_URL : the account's Licenses dashboard (upgrade a plan).
 * - REVERTIX_BUY_URL      : the public pricing/buy page for new purchases.
 */
if ( ! defined( 'REVERTIX_ACCOUNT_URL' ) ) {
	define( 'REVERTIX_ACCOUNT_URL', 'https://nitramix.com/login' );
}
if ( ! defined( 'REVERTIX_LICENSES_URL' ) ) {
	define( 'REVERTIX_LICENSES_URL', 'https://nitramix.com/dashboard/licenses' );
}
if ( ! defined( 'REVERTIX_BUY_URL' ) ) {
	define( 'REVERTIX_BUY_URL', 'https://nitramix.com/projects/revertix#pricing' );
}

// Back-compat alias for older references; points at the public buy page.
if ( ! defined( 'REVERTIX_UPGRADE_URL' ) ) {
	define( 'REVERTIX_UPGRADE_URL', REVERTIX_BUY_URL );
}

/*
 * ---------------------------------------------------------------------------
 * Autoload-ish bootstrap. We use explicit requires (no Composer) to stay
 * WordPress.org friendly and dependency-free.
 * ---------------------------------------------------------------------------
 */
require_once REVERTIX_PLUGIN_DIR . 'includes/revertix-functions.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-settings.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-notify.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-requirements.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-storage.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-log.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-system-info.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-filesystem.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-zip.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-database.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-manifest.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-search-replace.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-package-registry.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-backup.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-share.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-rollback.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-rollback-runner.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-staging.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-job.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/jobs/class-revertix-export-job.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/jobs/class-revertix-import-job.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/jobs/class-revertix-staging-job.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-ajax.php';
require_once REVERTIX_PLUGIN_DIR . 'admin/class-revertix-locked-feature.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-admin.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix-admin-bar.php';
require_once REVERTIX_PLUGIN_DIR . 'includes/class-revertix.php';

/*
 * ---------------------------------------------------------------------------
 * Activation / deactivation. Keep these tiny: just create + harden storage.
 * ---------------------------------------------------------------------------
 */
register_activation_hook( __FILE__, array( 'Revertix_Storage', 'on_activation' ) );
register_activation_hook( __FILE__, array( 'Revertix_Log', 'schedule' ) );
register_deactivation_hook( __FILE__, array( 'Revertix_Log', 'unschedule' ) );

/**
 * Boot the plugin once all plugins are loaded.
 */
function revertix_bootstrap() {
	Revertix::instance();
}
add_action( 'plugins_loaded', 'revertix_bootstrap' );
