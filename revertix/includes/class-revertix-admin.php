<?php
/**
 * Admin controller: the single "Revertix" menu, the tabbed page, and assets.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the admin menu and renders the dashboard.
 */
class Revertix_Admin {

	const PAGE_SLUG = 'revertix';

	/**
	 * Option remembering that the storage-protection notice was dismissed.
	 */
	const NOTICE_DISMISSED = 'revertix_storage_notice_dismissed';

	/**
	 * The available tabs: slug => label. Pro add-ons may filter this.
	 *
	 * @var array<string,string>
	 */
	private $tabs;

	/**
	 * Hook suffix of our admin page (for scoping asset loads).
	 *
	 * @var string
	 */
	private $hook_suffix = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'menu_icon_style' ) );
		add_filter( 'submenu_file', array( $this, 'highlight_submenu' ) );
		add_filter( 'admin_footer_text', array( $this, 'footer_text' ) );
		add_action( 'admin_notices', array( $this, 'storage_protection_notice' ) );
		add_action( 'admin_init', array( $this, 'maybe_dismiss_notice' ) );
	}

	/**
	 * Warn, on Revertix's own screens, when the storage directory is protected
	 * only by .htaccess and the server does not read .htaccess files.
	 *
	 * Backup archives contain the whole site root, wp-config.php included, so on
	 * a pure nginx host the only thing guarding them is the unguessable path.
	 */
	public function storage_protection_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Nothing to warn about on a machine that is not serving the public.
		if ( in_array( wp_get_environment_type(), array( 'local', 'development' ), true ) ) {
			return;
		}

		if ( Revertix_Storage::server_uses_htaccess() || get_option( self::NOTICE_DISMISSED ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, self::PAGE_SLUG ) ) {
			return;
		}

		$rule    = 'location ~* ' . Revertix_Storage::public_relative_dir() . ' { deny all; return 404; }';
		$dismiss = wp_nonce_url(
			add_query_arg( 'revertix_dismiss', 'storage', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ),
			'revertix_dismiss_storage'
		);

		echo '<div class="notice notice-warning"><p>';
		echo wp_kses_post(
			sprintf(
				/* translators: %s: storage directory path. */
				__( '<strong>Revertix:</strong> your backups live in %s and are protected by an <code>.htaccess</code> file, which your web server does not appear to read. Add the rule below to your server configuration so nobody can download them directly.', 'revertix' ),
				'<code>' . esc_html( Revertix_Storage::public_relative_dir() ) . '</code>'
			)
		);
		echo '</p><p><code>' . esc_html( $rule ) . '</code></p>';
		echo '<p><a href="' . esc_url( $dismiss ) . '">' . esc_html__( 'I have handled this - stop showing it', 'revertix' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Remember that the storage-protection notice was dismissed.
	 */
	public function maybe_dismiss_notice() {
		if ( ! isset( $_GET['revertix_dismiss'] ) || 'storage' !== $_GET['revertix_dismiss'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( 'revertix_dismiss_storage' );

		update_option( self::NOTICE_DISMISSED, 1, false );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * The tab definitions (lazy, so translations are ready).
	 *
	 * @return array<string,string>
	 */
	private function tabs() {
		if ( null === $this->tabs ) {
			$this->tabs = array(
				'export'   => __( 'Export / Import', 'revertix' ),
				'staging'  => __( 'Staging', 'revertix' ),
				'backups'  => __( 'Backups', 'revertix' ),
				'rollback' => __( 'Rollback', 'revertix' ),
				'settings' => __( 'Settings', 'revertix' ),
				'logs'     => __( 'System Info & Logs', 'revertix' ),
			);
		}
		return $this->tabs;
	}

	/**
	 * Register the top-level menu.
	 */
	public function register_menu() {
		$cap = revertix_capability();

		// Append a plain "PRO" label to the menu title once premium is unlocked.
		$menu_title = revertix_is_premium()
			? 'Revertix PRO'
			: __( 'Revertix', 'revertix' );

		$this->hook_suffix = add_menu_page(
			__( 'Revertix', 'revertix' ),
			$menu_title,
			$cap,
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			REVERTIX_PLUGIN_URL . 'assets/img/revertix-logo-white.svg',
			81
		);

		// One sidebar submenu per tab - except Logs, which stays reachable only
		// from the in-page tab bar. The first item reuses the parent slug (so it
		// renames the auto-duplicated entry); the rest are direct tab links.
		$first = true;
		foreach ( $this->tabs() as $slug => $label ) {
			if ( 'logs' === $slug ) {
				continue;
			}

			add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$label,
				$cap,
				$first ? self::PAGE_SLUG : 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug,
				$first ? array( $this, 'render_page' ) : ''
			);

			$first = false;
		}
	}

	/**
	 * The menu icon is a full-colour PNG logo. WordPress dims sidebar icons to
	 * 60% opacity by default and pads them as 20px glyphs; override both so the
	 * brand mark shows at full colour and stays crisp. Registered as an inline
	 * style on a dedicated handle (the rule is two lines of dynamic CSS - not
	 * worth a stylesheet file) and enqueued on every admin page, since the
	 * sidebar menu is everywhere.
	 */
	public function menu_icon_style() {
		wp_register_style( 'revertix-menu-icon', false, array(), REVERTIX_VERSION );
		wp_enqueue_style( 'revertix-menu-icon' );
		wp_add_inline_style(
			'revertix-menu-icon',
			'#adminmenu #toplevel_page_' . self::PAGE_SLUG . ' .wp-menu-image img{opacity:1;padding:6px 0 0;width:20px;height:20px}'
		);
	}

	/**
	 * Replace the admin footer credit on Revertix's own pages with a small
	 * branded line and a five-star review prompt.
	 *
	 * @param string $text The default footer text.
	 * @return string
	 */
	public function footer_text( $text ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->id !== $this->hook_suffix ) {
			return $text;
		}

		return self::brand_footer();
	}

	/**
	 * The branded admin-footer credit (Nitramix style, with a review prompt and
	 * a translation link). Shared so Pro screens can show the same line.
	 *
	 * @return string
	 */
	public static function brand_footer() {
		$name = revertix_is_premium() ? 'Revertix PRO' : 'Revertix';

		return sprintf(
			'<i><a target="_blank" href="%1$s">%2$s</a> v%3$s by <a href="%4$s" title="%5$s" target="_blank">Nitramix</a>. Please <a target="_blank" href="%6$s" title="%7$s">%8$s</a>.</i> | <a href="%9$s" target="_blank"><span class="dashicons dashicons-translation"></span></a>',
			esc_url( 'https://nitramix.com/' ),
			esc_html( $name ),
			esc_html( REVERTIX_VERSION ),
			esc_url( 'https://nitramix.com/' ),
			esc_attr__( 'Visit our site to get more great plugins', 'revertix' ),
			esc_url( 'https://wordpress.org/support/plugin/revertix/reviews/?filter=5' ),
			esc_attr__( 'Rate the plugin', 'revertix' ),
			esc_html__( 'Rate the plugin', 'revertix' ),
			esc_url( 'https://translate.wordpress.org/projects/wp-plugins/revertix/' )
		);
	}

	/**
	 * Keep the correct sidebar submenu highlighted when viewing a tab whose link
	 * is a query-string slug (Staging, Backups), which WordPress can't match on
	 * its own.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @return string|null
	 */
	public function highlight_submenu( $submenu_file ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( self::PAGE_SLUG === $page && '' !== $tab && 'export' !== $tab && 'logs' !== $tab ) {
			return 'admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab;
		}

		return $submenu_file;
	}

	/**
	 * Enqueue CSS/JS only on the Revertix page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}

		wp_enqueue_style(
			'revertix-admin',
			REVERTIX_PLUGIN_URL . 'assets/css/revertix-admin.css',
			array(),
			self::asset_version( 'assets/css/revertix-admin.css' )
		);

		wp_enqueue_script(
			'revertix-admin',
			REVERTIX_PLUGIN_URL . 'assets/js/revertix-admin.js',
			array(),
			self::asset_version( 'assets/js/revertix-admin.js' ),
			true
		);

		wp_localize_script(
			'revertix-admin',
			'RevertixData',
			array(
				'ajaxUrl'        => admin_url( 'admin-ajax.php' ),
				'nonce'          => wp_create_nonce( Revertix_Ajax::NONCE_ACTION ),
				'downloadLogUrl' => wp_nonce_url(
					admin_url( 'admin-post.php?action=revertix_download_log' ),
					'revertix_download_log'
				),
				'i18n'    => array(
					'starting'      => __( 'Starting…', 'revertix' ),
					'working'       => __( 'Working…', 'revertix' ),
					'done'          => __( 'Done', 'revertix' ),
					'failed'        => __( 'Something went wrong.', 'revertix' ),
					'download'      => __( 'Download', 'revertix' ),
					'delete'        => __( 'Delete', 'revertix' ),
					'shareLink'     => __( 'Get share link', 'revertix' ),
					'revokeLink'    => __( 'Revoke link', 'revertix' ),
					'shareTitle'    => __( 'Share this package', 'revertix' ),
					'shareCreate'   => __( 'Create link', 'revertix' ),
					'shareReady'    => __( 'Your download link', 'revertix' ),
					'shareCaution'  => __( 'Anyone holding this link can download the package. Revoke it when it is no longer needed.', 'revertix' ),
					'shareExpires'  => __( 'Expires', 'revertix' ),
					'copy'          => __( 'Copy', 'revertix' ),
					'linkRevoked'   => __( 'Share link revoked.', 'revertix' ),
					'confirmDelete' => __( 'Delete this export package? This cannot be undone.', 'revertix' ),
					'deleting'      => __( 'Deleting…', 'revertix' ),
					'uploading'     => __( 'Uploading package…', 'revertix' ),
					'confirmImport' => __( 'Import this package? It will overwrite this site’s database and files. Make a backup first.', 'revertix' ),
					'importDone'    => __( 'Import complete. Reloading…', 'revertix' ),
					'noFile'        => __( 'Please choose a .zip package to import.', 'revertix' ),
					'confirm'       => __( 'Confirm', 'revertix' ),
					'cancel'        => __( 'Cancel', 'revertix' ),
					'cancelled'     => __( 'Cancelled.', 'revertix' ),
					'ok'            => __( 'OK', 'revertix' ),
					'sessionLost'   => __( 'The session ended - you may have been logged out by the import. If the import was running it most likely finished; reload the page and sign in.', 'revertix' ),
					'copied'        => __( 'Log copied to clipboard.', 'revertix' ),
					'copyFailed'    => __( 'Could not copy. Select the text and copy manually.', 'revertix' ),
					'confirmClearLog' => __( 'Clear the log? This permanently deletes the recorded entries.', 'revertix' ),
					'logCleared'    => __( 'Log cleared.', 'revertix' ),
					'settingsSaved' => __( 'Settings saved.', 'revertix' ),
					'confirmDeleteStaging' => __( 'Delete this staging site? Its files and cloned tables will be permanently removed.', 'revertix' ),
					'confirmResetStaging'  => __( 'Reset this staging site? It will be wiped and re-cloned from the current live site. Any changes you made on staging will be lost.', 'revertix' ),
					'stagingReady'  => __( 'Staging site is ready.', 'revertix' ),
					'reset'         => __( 'Reset', 'revertix' ),
					'creatingStaging'  => __( 'Creating staging site…', 'revertix' ),
					'resettingStaging' => __( 'Resetting staging site…', 'revertix' ),
					'openStaging'   => __( 'Open staging site', 'revertix' ),
					'allDone'       => __( 'All done!', 'revertix' ),
					'close'         => __( 'Close', 'revertix' ),
					'creatingBackup' => __( 'Creating backup…', 'revertix' ),
					'backupDone'    => __( 'Backup created.', 'revertix' ),
					'restore'       => __( 'Restore', 'revertix' ),
					'confirmRestore' => __( 'Restore this backup? It will overwrite this site’s database and files. A safety backup of the current site is taken first.', 'revertix' ),
					'confirmDeleteBackup' => __( 'Delete this backup? This cannot be undone.', 'revertix' ),
					'safetyBackup'  => __( 'Safety backup (before restore)…', 'revertix' ),
					'safetyName'    => __( 'Safety backup before restore', 'revertix' ),
					'restoring'     => __( 'Restoring backup…', 'revertix' ),
					'restoreDone'   => __( 'Restore complete. Reloading…', 'revertix' ),
					'lockedDeleteTitle' => __( 'Your most recent backup', 'revertix' ),
					'lockedDeleteMsg'   => __( 'For your safety, Revertix always keeps your most recent backup so you’re never left without one to restore. Create a newer backup and this one becomes deletable.', 'revertix' ),
					'upgradePro'    => __( 'Upgrade to PRO', 'revertix' ),
					'rbLoading'     => __( 'Loading versions…', 'revertix' ),
					'rbNoVersions'  => __( 'No earlier versions are available on wordpress.org for this item.', 'revertix' ),
					'rbCurrent'     => __( 'current', 'revertix' ),
					'rbActive'      => __( 'Active', 'revertix' ),
					'rbVersions'    => __( 'Available versions', 'revertix' ),
					'rbChangelog'   => __( 'Changelog', 'revertix' ),
					'rbPrevious'    => __( 'previous', 'revertix' ),
					'rbLatest'      => __( 'latest', 'revertix' ),
					'rbInstalled'   => __( 'installed', 'revertix' ),
					'rbApply'       => __( 'Select a version', 'revertix' ),
					'rbSwitch'      => __( 'Switch to', 'revertix' ),
					'rbRollBack'    => __( 'Roll back', 'revertix' ),
					'rbRollingBack' => __( 'Rolling back…', 'revertix' ),
					/* translators: 1: plugin or theme name, 2: version number. */
					'rbConfirm'     => __( 'Switch “%1$s” to version %2$s? A safety snapshot of the current version is taken first. The site may briefly go into maintenance mode.', 'revertix' ),
					'rbDone'        => __( 'Rolled back. Reloading…', 'revertix' ),
					'rbSnapshot'    => __( 'Snapshot current version', 'revertix' ),
					'rbSnapping'    => __( 'Creating snapshot…', 'revertix' ),
					'rbSnapDone'    => __( 'Snapshot created.', 'revertix' ),
					'rbRestore'     => __( 'Restore', 'revertix' ),
					'rbSnapshots'   => __( 'Saved snapshots', 'revertix' ),
					'rbNoSnapshots' => __( 'No snapshots yet.', 'revertix' ),
										'rbCustomTag'   => __( 'Premium / custom', 'revertix' ),
					'rbCustomPro'   => __( 'This item isn’t in the WordPress.org directory, so there’s no public version history. Snapshot the current version below to be able to roll back to it later.', 'revertix' ),
					/* translators: %s: WordPress version. */
					'coreHeld'      => __( 'Automatic WordPress updates are on hold, so this site stays on version %s.', 'revertix' ),
					'coreResume'    => __( 'Resume automatic updates', 'revertix' ),
					'coreIntro'     => __( 'Switch WordPress to another released version. Your content, plugins, themes and uploads are not touched. Take a backup first.', 'revertix' ),
					'coreInsecure'  => __( 'not secure', 'revertix' ),
					/* translators: 1: WordPress version, 2: PHP version. */
					'coreMinPhp'    => __( 'Versions older than %1$s are not listed: they do not support PHP %2$s, which this server runs.', 'revertix' ),
					/* translators: 1: WordPress version, 2: PHP version (unused). */
					'coreMinRevertix' => __( 'Versions older than %1$s are not listed: Revertix needs at least that version, so you could not switch back from here.', 'revertix' ),
					'corePin'       => __( 'Stay on this version: pause automatic WordPress updates until I resume them or update by hand.', 'revertix' ),
					/* translators: %s: WordPress version. */
					'coreConfirm'   => __( 'Switch WordPress to version %s? The site goes into maintenance mode for a minute while the files are replaced. Plugins or themes that need a newer WordPress may stop working.', 'revertix' ),
					'coreConfirmInsecure' => __( 'This version has known security issues that are fixed in later releases.', 'revertix' ),
					'coreSwitching' => __( 'Switching WordPress version… this can take a minute.', 'revertix' ),
					'rbCustomFree'  => __( 'This looks like a premium/custom plugin or theme - it isn’t in the WordPress.org directory, so there’s no public version history to roll back to. Snapshots for premium/custom items are available in the separate Revertix PRO add-on.', 'revertix' ),
				),
			)
		);
	}

	/**
	 * Asset cache-busting version: the file's modification time, so CSS/JS
	 * changes are picked up immediately without bumping the plugin version.
	 * Falls back to the plugin version if the file can't be read.
	 *
	 * @param string $rel Plugin-relative asset path.
	 * @return string
	 */
	private static function asset_version( $rel ) {
		$path  = REVERTIX_PLUGIN_DIR . $rel;
		$mtime = file_exists( $path ) ? filemtime( $path ) : 0;
		return $mtime ? (string) $mtime : REVERTIX_VERSION;
	}

	/**
	 * Render the dashboard page with the active tab's section.
	 */
	public function render_page() {
		if ( ! current_user_can( revertix_capability() ) ) {
			return;
		}

		$tabs = $this->tabs();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$active = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'export';
		if ( ! isset( $tabs[ $active ] ) ) {
			$active = 'export';
		}

		include REVERTIX_PLUGIN_DIR . 'admin/views/page-main.php';
	}

	/**
	 * Helper: URL to a given tab.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function tab_url( $tab ) {
		return add_query_arg(
			array(
				'page' => self::PAGE_SLUG,
				'tab'  => $tab,
			),
			admin_url( 'admin.php' )
		);
	}
}
