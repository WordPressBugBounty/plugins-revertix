<?php
/**
 * System information report.
 *
 * Gathers everything useful for diagnosing a support issue - WordPress, server,
 * PHP, database, extensions, constants, filesystem, theme and plugins (with
 * versions) plus Revertix's own state - into labelled sections. The System Info
 * screen renders these collapsibly and a user can download them (with the log)
 * to send when reporting a problem.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB -- read-only diagnostics (server/DB status). Static queries take no input; identifiers use the %i placeholder and values %s via $wpdb->prepare().

/**
 * Collects a structured system-info report.
 */
class Revertix_System_Info {

	/**
	 * All report sections, in display order. Empty sections are dropped.
	 *
	 * @return array<int,array{title:string,rows:array<int,array{0:string,1:string}>}>
	 */
	public static function sections() {
		$sections = array(
			self::section_revertix(),
			self::section_wordpress(),
			self::section_paths(),
			self::section_server(),
			self::section_php(),
			self::section_extensions(),
			self::section_network(),
			self::section_database(),
			self::section_constants(),
			self::section_filesystem(),
			self::section_theme(),
			self::section_mu_plugins(),
			self::section_active_plugins(),
			self::section_inactive_plugins(),
			self::section_staging(),
		);

		return array_values(
			array_filter(
				$sections,
				static function ( $section ) {
					return ! empty( $section['rows'] );
				}
			)
		);
	}

	/**
	 * Render the whole report as plain text (for download).
	 *
	 * @return string
	 */
	public static function as_text() {
		$out  = '=== Revertix System Info ===' . "\n";
		$out .= 'Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";

		foreach ( self::sections() as $section ) {
			$out .= "\n## " . $section['title'] . "\n";
			foreach ( $section['rows'] as $row ) {
				$out .= sprintf( "%-24s %s\n", $row[0] . ':', $row[1] );
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Sections
	 * ------------------------------------------------------------------- */

	/**
	 * Revertix's own state.
	 *
	 * @return array
	 */
	protected static function section_revertix() {
		$settings = class_exists( 'Revertix_Log' ) ? Revertix_Log::settings() : array(
			'auto_prune'     => false,
			'retention_days' => 0,
		);

		$premium  = revertix_is_premium();
		$budget   = (float) apply_filters( 'revertix_job_time_budget', 5.0 );
		$excluded = class_exists( 'Revertix_Filesystem' ) ? implode( ', ', Revertix_Filesystem::excluded_names() ) : '';

		$rows = array();

		// Versions + licensing.
		$rows[] = array( 'Version', REVERTIX_VERSION );
		if ( defined( 'REVERTIX_PRO_VERSION' ) ) {
			$rows[] = array( 'Pro version', REVERTIX_PRO_VERSION );
		}
		$rows[] = array( 'Package format', (string) REVERTIX_PACKAGE_FORMAT );
		$rows[] = array( 'Premium', self::yes_no( $premium ) );
		// Show the plan by its proper name, and only when premium is actually on
		// (the raw tier is meaningless on the free version).
		$rows[] = array( 'Plan', $premium ? self::plan_label() : 'Free' );
		if ( $premium && class_exists( 'Revertix_Pro_License' ) ) {
			$rows[] = array( 'License', self::license_summary() );
		}

		// Engine.
		$rows[] = array( 'Job time budget', $budget . 's' );
		$rows[] = array( 'Excluded folders', $excluded );
		$rows[] = array( 'Storage path', Revertix_Storage::base_dir() );
		$rows[] = array( 'Storage writable', self::yes_no( Revertix_Storage::is_writable() ) );
		$rows[] = array(
			'Storage protection',
			Revertix_Storage::server_uses_htaccess()
				? '.htaccess (honoured by this server)'
				: '.htaccess present but this server does not read it - add a server rule',
		);
		$rows[] = array( 'Log auto-delete', $settings['auto_prune'] ? ( (int) $settings['retention_days'] . ' days' ) : 'off' );

		// State counts.
		$max      = class_exists( 'Revertix_Staging' ) ? Revertix_Staging::max_allowed() : 1;
		$max_str  = ( -1 === $max ) ? 'unlimited' : (string) $max;
		$rows[]   = array( 'Staging sites', ( class_exists( 'Revertix_Staging' ) ? Revertix_Staging::count() : 0 ) . ' / ' . $max_str );
		$rows[]   = array( 'Export packages', (string) ( class_exists( 'Revertix_Package_Registry' ) ? count( Revertix_Package_Registry::all() ) : 0 ) );
		if ( class_exists( 'Revertix_Backup' ) ) {
			$rows[] = array( 'Backups', (string) count( Revertix_Backup::all() ) );
		}
		$rows[] = array( 'Rollback snapshots', (string) self::snapshot_total() );

		// Scheduled automatic backups (Pro).
		$rows[] = array( 'Scheduled backups', self::scheduler_summary( $premium ) );

		// Key behaviour settings worth seeing at a glance.
		if ( class_exists( 'Revertix_Settings' ) ) {
			$rows[] = array( 'Safety backups kept', (string) (int) Revertix_Settings::get( 'safety_keep' ) );
			$rows[] = array( 'Staging login wall', Revertix_Settings::get( 'staging_disable_auth' ) ? 'disabled (site is public)' : 'enforced (login required)' );
			$rows[] = array( 'Email notifications', self::notifications_summary() );
		}

		return array(
			'title' => 'Revertix',
			'rows'  => $rows,
		);
	}

	/**
	 * The active plan's display name (e.g. "Agency"), falling back to the tier.
	 *
	 * @return string
	 */
	protected static function plan_label() {
		if ( function_exists( 'revertix_plan_caps' ) ) {
			$caps = revertix_plan_caps();
			if ( ! empty( $caps['label'] ) ) {
				return (string) $caps['label'];
			}
		}
		return function_exists( 'revertix_get_plan_tier' ) ? ucfirst( revertix_get_plan_tier() ) : 'Pro';
	}

	/**
	 * A one-line license status (Pro): active/inactive + expiry.
	 *
	 * @return string
	 */
	protected static function license_summary() {
		if ( ! class_exists( 'Revertix_Pro_License' ) ) {
			return 'n/a';
		}

		$active = Revertix_Pro_License::is_active();
		$out    = $active ? 'Active' : 'Not activated (dev unlock)';

		$ends = (int) Revertix_Pro_License::expires_at();
		if ( $ends > 0 ) {
			$out .= ' | ' . ( Revertix_Pro_License::is_expired() ? 'expired ' : 'renews ' ) . gmdate( 'Y-m-d', $ends );
		}

		return $out;
	}

	/**
	 * Total number of rollback snapshots across all items.
	 *
	 * @return int
	 */
	protected static function snapshot_total() {
		$registry = get_option( 'revertix_rollback_snapshots', array() );
		if ( ! is_array( $registry ) ) {
			return 0;
		}
		$total = 0;
		foreach ( $registry as $list ) {
			if ( is_array( $list ) ) {
				$total += count( $list );
			}
		}
		return $total;
	}

	/**
	 * A one-line summary of the scheduled-backup state (Pro).
	 *
	 * @param bool $premium Whether premium is on.
	 * @return string
	 */
	protected static function scheduler_summary( $premium ) {
		if ( ! $premium || ! class_exists( 'Revertix_Pro_Scheduler' ) ) {
			return 'n/a (provided by the Revertix PRO add-on)';
		}

		$settings = get_option( 'revertix_pro_schedule', array() );
		if ( ! is_array( $settings ) || empty( $settings['enabled'] ) ) {
			return 'off';
		}

		$freq   = isset( $settings['frequency'] ) ? (string) $settings['frequency'] : '';
		$labels = function_exists( 'revertix_backup_frequency_labels' ) ? revertix_backup_frequency_labels() : array();
		$label  = isset( $labels[ $freq ] ) ? $labels[ $freq ] : $freq;

		$next     = wp_next_scheduled( 'revertix_pro_run_backup' );
		$next_str = $next ? ( ' | next ' . gmdate( 'Y-m-d H:i', $next ) . ' UTC' ) : '';

		return 'on (' . $label . ')' . $next_str;
	}

	/**
	 * Which email notifications are enabled, as a compact list.
	 *
	 * @return string
	 */
	protected static function notifications_summary() {
		if ( ! class_exists( 'Revertix_Settings' ) ) {
			return 'n/a';
		}

		$on = array();
		if ( Revertix_Settings::get( 'notify_email_errors' ) ) {
			$on[] = 'errors';
		}
		if ( Revertix_Settings::get( 'notify_email_warnings' ) ) {
			$on[] = 'warnings';
		}
		if ( Revertix_Settings::get( 'notify_email_status' ) ) {
			$on[] = 'status';
		}

		return empty( $on ) ? 'off' : 'on (' . implode( ', ', $on ) . ')';
	}

	/**
	 * WordPress.
	 *
	 * @return array
	 */
	protected static function section_wordpress() {
		global $wpdb;

		$user_count = is_object( $wpdb ) ? self::db_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->users ) ) : '';
		$cron       = (array) get_option( 'cron', array() );
		$cron_count = max( 0, count( $cron ) - 1 ); // The "version" key is not an event.

		return array(
			'title' => 'WordPress',
			'rows'  => array(
				array( 'Version', get_bloginfo( 'version' ) ),
				array( 'Environment', function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'n/a' ),
				array( 'Site type', is_multisite() ? 'Multisite' : 'Single site' ),
				array( 'Installed in subdir', self::yes_no( self::is_subdir_install() ) ),
				array( 'Language', get_locale() ),
				array( 'Timezone', (string) get_option( 'timezone_string' ) ?: ( 'UTC' . get_option( 'gmt_offset' ) ) ),
				array( 'Home URL', home_url() ),
				array( 'Site URL', site_url() ),
				array( 'Permalinks', (string) get_option( 'permalink_structure' ) ?: '(plain)' ),
				array( 'Table prefix', is_object( $wpdb ) ? $wpdb->prefix : '' ),
				array( 'Users', $user_count ),
				array( 'WP-Cron disabled', self::yes_no( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ),
				array( 'Scheduled events', (string) $cron_count ),
				array( 'WP memory limit', defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : 'n/a' ),
				array( 'WP max memory', defined( 'WP_MAX_MEMORY_LIMIT' ) ? WP_MAX_MEMORY_LIMIT : 'n/a' ),
			),
		);
	}

	/**
	 * Paths, permissions and uploads.
	 *
	 * @return array
	 */
	protected static function section_paths() {
		self::ensure_file_api();
		$uploads = wp_upload_dir();

		return array(
			'title' => 'Paths & Permissions',
			'rows'  => array(
				array( 'WordPress root', class_exists( 'Revertix_Filesystem' ) ? Revertix_Filesystem::root() : '' ),
				array( 'WordPress root permissions', class_exists( 'Revertix_Filesystem' ) ? self::file_perms( Revertix_Filesystem::root() ) : '' ),
				array( 'Home path', function_exists( 'get_home_path' ) ? get_home_path() : 'n/a' ),
				array( 'Content dir', class_exists( 'Revertix_Filesystem' ) ? Revertix_Filesystem::content_dir() : 'n/a' ),
				array( 'Plugins dir', class_exists( 'Revertix_Filesystem' ) ? Revertix_Filesystem::plugins_dir() : 'n/a' ),
				array( 'wp-content symlink', self::yes_no( class_exists( 'Revertix_Filesystem' ) && is_link( untrailingslashit( Revertix_Filesystem::content_dir() ) ) ) ),
				array( 'WP temp dir', function_exists( 'get_temp_dir' ) ? get_temp_dir() : 'n/a' ),
				array( 'Uploads dir', $uploads['basedir'] ),
				array( 'Uploads URL', $uploads['baseurl'] ),
				array( 'Uploads writable', self::yes_no( wp_is_writable( $uploads['basedir'] ) ) ),
				array( 'Uploads permissions', self::file_perms( $uploads['basedir'] ) ),
				array( 'UPLOADS constant', self::const_str( 'UPLOADS' ) ),
				array( 'UPLOAD_PATH', self::const_str( 'UPLOAD_PATH' ) ),
				array( 'FS_CHMOD_DIR', defined( 'FS_CHMOD_DIR' ) ? decoct( FS_CHMOD_DIR ) : 'not set' ),
				array( 'FS_CHMOD_FILE', defined( 'FS_CHMOD_FILE' ) ? decoct( FS_CHMOD_FILE ) : 'not set' ),
			),
		);
	}

	/**
	 * Web server / OS.
	 *
	 * @return array
	 */
	protected static function section_server() {
		global $wpdb;

		return array(
			'title' => 'Server',
			'rows'  => array(
				array( 'Server software', self::server_var( 'SERVER_SOFTWARE' ) ),
				array( 'Operating system', function_exists( 'php_uname' ) ? php_uname( 's' ) . ' ' . php_uname( 'r' ) : PHP_OS ),
				array( 'OS architecture', function_exists( 'php_uname' ) ? php_uname( 'm' ) : '' ),
				array( 'PHP architecture', ( PHP_INT_SIZE * 8 ) . '-bit' ),
				array( 'Server / PHP user', self::php_user() ),
				array( 'Hostname', function_exists( 'gethostname' ) ? (string) gethostname() : '' ),
				array( 'Server IP', self::server_var( 'SERVER_ADDR' ) ),
				array( 'Document root', self::server_var( 'DOCUMENT_ROOT' ) ),
				array( 'HTTPS', self::yes_no( is_ssl() ) ),
				array( 'Server time', gmdate( 'Y-m-d H:i:s' ) . ' UTC' ),
				array( 'PHP SAPI', PHP_SAPI ),
				array( 'Database type', self::db_type() ),
				array( 'DB server info', self::mysql_server_info() ),
			),
		);
	}

	/**
	 * Network / cURL environment.
	 *
	 * @return array
	 */
	protected static function section_network() {
		$rows = array();

		if ( function_exists( 'curl_version' ) ) {
			$curl   = curl_version();
			$rows[] = array( 'cURL version', isset( $curl['version'] ) ? $curl['version'] : '' );
			$rows[] = array( 'SSL library', isset( $curl['ssl_version'] ) ? $curl['ssl_version'] : '' );
			$rows[] = array( 'libz version', isset( $curl['libz_version'] ) ? $curl['libz_version'] : '' );
			if ( ! empty( $curl['protocols'] ) ) {
				$rows[] = array( 'cURL protocols', implode( ', ', (array) $curl['protocols'] ) );
			}
		} else {
			$rows[] = array( 'cURL', 'Not available' );
		}

		$rows[] = array( 'fsockopen', self::yes_no( function_exists( 'fsockopen' ) ) );
		$rows[] = array( 'allow_url_fopen', self::yes_no( (bool) ini_get( 'allow_url_fopen' ) ) );
		$rows[] = array( 'SoapClient', self::yes_no( class_exists( 'SoapClient' ) ) );
		$rows[] = array( 'OpenSSL', self::yes_no( extension_loaded( 'openssl' ) ) );

		return array(
			'title' => 'Network',
			'rows'  => $rows,
		);
	}

	/**
	 * PHP runtime + limits.
	 *
	 * @return array
	 */
	protected static function section_php() {
		$disabled = (string) ini_get( 'disable_functions' );

		return array(
			'title' => 'PHP',
			'rows'  => array(
				array( 'Version', PHP_VERSION ),
				array( 'Memory limit', self::memory_limit_str() ),
				array( 'Max execution time', ini_get( 'max_execution_time' ) . 's' ),
				array( 'Max input time', ini_get( 'max_input_time' ) . 's' ),
				array( 'Max input vars', (string) ini_get( 'max_input_vars' ) ),
				array( 'Post max size', (string) ini_get( 'post_max_size' ) ),
				array( 'Upload max filesize', (string) ini_get( 'upload_max_filesize' ) ),
				array( 'Max file uploads', (string) ini_get( 'max_file_uploads' ) ),
				array( 'Default socket timeout', ini_get( 'default_socket_timeout' ) . 's' ),
				array( 'allow_url_fopen', self::yes_no( (bool) ini_get( 'allow_url_fopen' ) ) ),
				array( 'Display errors', self::yes_no( (bool) ini_get( 'display_errors' ) ) ),
				array( 'Error reporting', (string) ini_get( 'error_reporting' ) ),
				array( 'Date timezone', (string) ini_get( 'date.timezone' ) ?: '(not set)' ),
				array( 'OPcache', self::yes_no( (bool) ini_get( 'opcache.enable' ) ) ),
				array( 'Session gc maxlifetime', ini_get( 'session.gc_maxlifetime' ) . 's' ),
				array( 'Disabled functions', '' !== $disabled ? $disabled : '(none)' ),
			),
		);
	}

	/**
	 * Relevant PHP extensions.
	 *
	 * @return array
	 */
	protected static function section_extensions() {
		$rows = array(
			array( 'ZipArchive', self::yes_no( class_exists( 'ZipArchive' ) ) ),
			array( 'cURL', self::yes_no( function_exists( 'curl_version' ) ) ),
			array( 'mysqli', self::yes_no( extension_loaded( 'mysqli' ) ) ),
			array( 'pdo_mysql', self::yes_no( extension_loaded( 'pdo_mysql' ) ) ),
			array( 'mbstring', self::yes_no( extension_loaded( 'mbstring' ) ) ),
			array( 'openssl', self::yes_no( extension_loaded( 'openssl' ) ) ),
			array( 'gd', self::yes_no( extension_loaded( 'gd' ) ) ),
			array( 'imagick', self::yes_no( extension_loaded( 'imagick' ) ) ),
			array( 'dom', self::yes_no( extension_loaded( 'dom' ) ) ),
			array( 'simplexml', self::yes_no( extension_loaded( 'simplexml' ) ) ),
			array( 'fileinfo', self::yes_no( extension_loaded( 'fileinfo' ) ) ),
			array( 'intl', self::yes_no( extension_loaded( 'intl' ) ) ),
			array( 'zlib', self::yes_no( extension_loaded( 'zlib' ) ) ),
			array( 'json', self::yes_no( extension_loaded( 'json' ) ) ),
			array( 'iconv', self::yes_no( extension_loaded( 'iconv' ) ) ),
			array( 'All loaded', (string) count( get_loaded_extensions() ) ),
		);

		return array(
			'title' => 'PHP Extensions',
			'rows'  => $rows,
		);
	}

	/**
	 * Database details.
	 *
	 * @return array
	 */
	protected static function section_database() {
		global $wpdb;

		$db_name = defined( 'DB_NAME' ) ? DB_NAME : '';
		$size    = '';
		$tables  = '';

		if ( is_object( $wpdb ) && '' !== $db_name ) {
			$bytes  = self::db_var( $wpdb->prepare( 'SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = %s', $db_name ) );
			$size   = ( '' !== $bytes ) ? revertix_size( (int) $bytes, 2 ) : 'n/a';
			$tables = self::db_var( $wpdb->prepare( 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = %s', $db_name ) );
		}

		return array(
			'title' => 'Database',
			'rows'  => array(
				array( 'Database name', $db_name ),
				array( 'Table prefix', is_object( $wpdb ) ? $wpdb->prefix : '' ),
				array( 'Charset', is_object( $wpdb ) ? $wpdb->charset : '' ),
				array( 'Collation', is_object( $wpdb ) ? $wpdb->collate : '' ),
				array( 'Database size', $size ),
				array( 'Tables', $tables ),
				array( 'MySQL version', self::db_var( 'SELECT VERSION()' ) ),
				array( 'lower_case_table_names', self::db_var( 'SELECT @@lower_case_table_names' ) ),
				array( 'max_allowed_packet', self::format_bytes_var( self::db_var( 'SELECT @@max_allowed_packet' ) ) ),
				array( 'wait_timeout', self::db_var( 'SELECT @@wait_timeout' ) . 's' ),
				array( 'sql_mode', self::db_var( 'SELECT @@sql_mode' ) ?: '(empty)' ),
			),
		);
	}

	/**
	 * Relevant WordPress / hosting constants.
	 *
	 * @return array
	 */
	protected static function section_constants() {
		$names = array(
			'WP_DEBUG',
			'WP_DEBUG_LOG',
			'WP_DEBUG_DISPLAY',
			'SCRIPT_DEBUG',
			'WP_CACHE',
			'CONCATENATE_SCRIPTS',
			'DISALLOW_FILE_EDIT',
			'DISALLOW_FILE_MODS',
			'AUTOMATIC_UPDATER_DISABLED',
			'WP_AUTO_UPDATE_CORE',
			'FORCE_SSL_ADMIN',
			'WP_POST_REVISIONS',
			'AUTOSAVE_INTERVAL',
			'FS_METHOD',
		);

		$rows = array();
		foreach ( $names as $name ) {
			$rows[] = array( $name, self::const_str( $name ) );
		}

		return array(
			'title' => 'Constants',
			'rows'  => $rows,
		);
	}

	/**
	 * Filesystem details.
	 *
	 * @return array
	 */
	protected static function section_filesystem() {
		$uploads = wp_upload_dir();
		$free    = function_exists( 'disk_free_space' ) ? @disk_free_space( $uploads['basedir'] ) : false; // phpcs:ignore
		$total   = function_exists( 'disk_total_space' ) ? @disk_total_space( $uploads['basedir'] ) : false; // phpcs:ignore

		if ( ! function_exists( 'get_filesystem_method' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		return array(
			'title' => 'Filesystem',
			'rows'  => array(
				array( 'Free disk space', false !== $free ? revertix_size( (int) $free, 2 ) : 'n/a' ),
				array( 'Total disk space', false !== $total ? revertix_size( (int) $total, 2 ) : 'n/a' ),
				array( 'FS method', function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : 'n/a' ),
				array( 'Temp dir', function_exists( 'sys_get_temp_dir' ) ? sys_get_temp_dir() : 'n/a' ),
				array( 'WP temp dir', function_exists( 'get_temp_dir' ) ? get_temp_dir() : 'n/a' ),
			),
		);
	}

	/**
	 * Active theme.
	 *
	 * @return array
	 */
	protected static function section_theme() {
		$theme = wp_get_theme();

		$rows = array(
			array( 'Name', $theme->get( 'Name' ) ),
			array( 'Version', $theme->get( 'Version' ) ),
			array( 'Author', wp_strip_all_tags( (string) $theme->get( 'Author' ) ) ),
			array( 'Stylesheet', $theme->get_stylesheet() ),
		);

		if ( $theme->parent() ) {
			$rows[] = array( 'Parent theme', $theme->parent()->get( 'Name' ) . ' ' . $theme->parent()->get( 'Version' ) );
		}

		return array(
			'title' => 'Active Theme',
			'rows'  => $rows,
		);
	}

	/**
	 * Must-use plugins (name → version).
	 *
	 * @return array
	 */
	protected static function section_mu_plugins() {
		self::ensure_plugin_api();
		$mu   = function_exists( 'get_mu_plugins' ) ? get_mu_plugins() : array();
		$rows = array();
		foreach ( $mu as $data ) {
			$rows[] = array( $data['Name'], $data['Version'] ? $data['Version'] : '-' );
		}

		return array(
			'title' => 'Must-Use Plugins (' . count( $rows ) . ')',
			'rows'  => $rows,
		);
	}

	/**
	 * Active plugins (name → version).
	 *
	 * @return array
	 */
	protected static function section_active_plugins() {
		self::ensure_plugin_api();
		$all    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active = (array) get_option( 'active_plugins', array() );

		// Network-activated plugins on multisite.
		if ( is_multisite() ) {
			$network = (array) get_site_option( 'active_sitewide_plugins', array() );
			$active  = array_unique( array_merge( $active, array_keys( $network ) ) );
		}

		$rows = array();
		foreach ( $active as $file ) {
			if ( isset( $all[ $file ] ) ) {
				$rows[] = array( $all[ $file ]['Name'], $all[ $file ]['Version'] ? $all[ $file ]['Version'] : '-' );
			} else {
				$rows[] = array( $file, '?' );
			}
		}

		return array(
			'title' => 'Active Plugins (' . count( $rows ) . ')',
			'rows'  => $rows,
		);
	}

	/**
	 * Inactive plugins (name → version).
	 *
	 * @return array
	 */
	protected static function section_inactive_plugins() {
		self::ensure_plugin_api();
		$all    = function_exists( 'get_plugins' ) ? get_plugins() : array();
		$active = (array) get_option( 'active_plugins', array() );

		$rows = array();
		foreach ( $all as $file => $data ) {
			if ( ! in_array( $file, $active, true ) ) {
				$rows[] = array( $data['Name'], $data['Version'] ? $data['Version'] : '-' );
			}
		}

		return array(
			'title' => 'Inactive Plugins (' . count( $rows ) . ')',
			'rows'  => $rows,
		);
	}

	/**
	 * Staging sites.
	 *
	 * @return array
	 */
	protected static function section_staging() {
		$rows = array();

		if ( class_exists( 'Revertix_Staging' ) ) {
			foreach ( Revertix_Staging::all() as $site ) {
				$label  = isset( $site['name'] ) ? $site['name'] : $site['slug'];
				$rows[] = array(
					$label,
					sprintf(
						'%s | %s | %s',
						isset( $site['url'] ) ? $site['url'] : '',
						isset( $site['prefix'] ) ? $site['prefix'] : '',
						isset( $site['status'] ) ? $site['status'] : 'ready'
					),
				);
			}
		}

		return array(
			'title' => 'Staging Sites (' . count( $rows ) . ')',
			'rows'  => $rows,
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Ensure get_plugins()/get_mu_plugins() are available.
	 */
	protected static function ensure_plugin_api() {
		if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
	}

	/**
	 * Ensure get_home_path()/get_filesystem_method() are available.
	 */
	protected static function ensure_file_api() {
		if ( ! function_exists( 'get_home_path' ) && file_exists( ABSPATH . 'wp-admin/includes/file.php' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
	}

	/**
	 * Whether WordPress core lives in a subdirectory (site URL deeper than home).
	 *
	 * @return bool
	 */
	protected static function is_subdir_install() {
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		$site = untrailingslashit( (string) wp_parse_url( site_url(), PHP_URL_PATH ) );
		return $home !== $site;
	}

	/**
	 * Octal permissions of a path, e.g. "0755".
	 *
	 * @param string $path Path.
	 * @return string
	 */
	protected static function file_perms( $path ) {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		$perms = @fileperms( $path );
		return ( false === $perms ) ? 'n/a' : substr( sprintf( '%o', $perms ), -4 );
	}

	/**
	 * The OS user PHP runs as, best-effort.
	 *
	 * @return string
	 */
	protected static function php_user() {
		if ( function_exists( 'posix_getpwuid' ) && function_exists( 'posix_geteuid' ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			$info = @posix_getpwuid( posix_geteuid() );
			if ( ! empty( $info['name'] ) ) {
				return $info['name'];
			}
		}
		if ( function_exists( 'get_current_user' ) ) {
			return (string) get_current_user();
		}
		return 'unknown';
	}

	/**
	 * Detect MySQL vs MariaDB from the version string.
	 *
	 * @return string
	 */
	protected static function db_type() {
		$version = self::db_var( 'SELECT VERSION()' );
		if ( '' === $version ) {
			return 'n/a';
		}
		return ( false !== stripos( $version, 'mariadb' ) ) ? 'MariaDB' : 'MySQL';
	}

	/**
	 * memory_limit with a byte count, e.g. "256M (268435456 bytes)".
	 *
	 * @return string
	 */
	protected static function memory_limit_str() {
		$raw = (string) ini_get( 'memory_limit' );
		if ( '' === $raw || '-1' === $raw ) {
			return '-1' === $raw ? 'unlimited' : $raw;
		}
		$bytes = function_exists( 'wp_convert_hr_to_bytes' ) ? wp_convert_hr_to_bytes( $raw ) : 0;
		return $bytes ? ( $raw . ' (' . revertix_number( $bytes ) . ' bytes)' ) : $raw;
	}

	/**
	 * A sanitized $_SERVER value.
	 *
	 * @param string $key Key.
	 * @return string
	 */
	protected static function server_var( $key ) {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return isset( $_SERVER[ $key ] ) ? sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) : '';
	}

	/**
	 * A scalar DB result, error-suppressed.
	 *
	 * @param string $sql SQL.
	 * @return string
	 */
	protected static function db_var( $sql ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) ) {
			return '';
		}
		$prev = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$value = $wpdb->get_var( $sql );
		$wpdb->suppress_errors( $prev );
		return ( null === $value ) ? '' : (string) $value;
	}

	/**
	 * Format a byte count coming back as a string (e.g. max_allowed_packet).
	 *
	 * @param string $bytes Byte count string.
	 * @return string
	 */
	protected static function format_bytes_var( $bytes ) {
		return ( '' === $bytes ) ? '' : revertix_size( (int) $bytes, 2 );
	}

	/**
	 * The MySQL server info string, if reachable.
	 *
	 * @return string
	 */
	protected static function mysql_server_info() {
		global $wpdb;
		if ( is_object( $wpdb ) && method_exists( $wpdb, 'db_server_info' ) ) {
			$info = $wpdb->db_server_info();
			if ( $info ) {
				return (string) $info;
			}
		}
		return 'n/a';
	}

	/**
	 * Stringify a constant for display.
	 *
	 * @param string $name Constant name.
	 * @return string
	 */
	protected static function const_str( $name ) {
		if ( ! defined( $name ) ) {
			return 'not set';
		}
		$value = constant( $name );
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		return (string) $value;
	}

	/**
	 * Yes/No string for a boolean.
	 *
	 * @param bool $value Value.
	 * @return string
	 */
	protected static function yes_no( $value ) {
		return $value ? 'Yes' : 'No';
	}
}
