<?php
/**
 * AJAX + admin-post endpoints.
 *
 * Every handler enforces a capability check and a nonce before doing anything.
 * The "run job" endpoint is the heartbeat of the resumable engine: the browser
 * calls it repeatedly until the job reports complete or error.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Registers and handles all Revertix AJAX actions.
 */
class Revertix_Ajax {

	const NONCE_ACTION = 'revertix_ajax';

	/**
	 * Wire up the endpoints.
	 */
	public function __construct() {
		add_action( 'wp_ajax_revertix_start_export', array( $this, 'start_export' ) );
		add_action( 'wp_ajax_revertix_run_job', array( $this, 'run_job' ) );
		// Also handle logged-out run ticks: an import can replace the users table
		// and drop the current session mid-job. The job secret re-authorizes it.
		add_action( 'wp_ajax_nopriv_revertix_run_job', array( $this, 'run_job' ) );
		add_action( 'wp_ajax_revertix_cancel_job', array( $this, 'cancel_job' ) );
		add_action( 'wp_ajax_revertix_delete_package', array( $this, 'delete_package' ) );
		add_action( 'wp_ajax_revertix_share_package', array( $this, 'share_package' ) );
		add_action( 'wp_ajax_revertix_unshare_package', array( $this, 'unshare_package' ) );
		add_action( 'wp_ajax_revertix_show_share', array( $this, 'show_share' ) );
		add_action( 'wp_ajax_revertix_upload_chunk', array( $this, 'upload_chunk' ) );
		add_action( 'wp_ajax_revertix_start_import', array( $this, 'start_import' ) );
		add_action( 'wp_ajax_revertix_start_staging', array( $this, 'start_staging' ) );
		add_action( 'wp_ajax_revertix_reset_staging', array( $this, 'reset_staging' ) );
		add_action( 'wp_ajax_revertix_delete_staging', array( $this, 'delete_staging' ) );
		add_action( 'wp_ajax_revertix_start_backup', array( $this, 'start_backup' ) );
		add_action( 'wp_ajax_revertix_start_restore', array( $this, 'start_restore' ) );
		add_action( 'wp_ajax_revertix_delete_backup', array( $this, 'delete_backup' ) );
		add_action( 'wp_ajax_revertix_clear_log', array( $this, 'clear_log' ) );
		add_action( 'wp_ajax_revertix_save_log_settings', array( $this, 'save_log_settings' ) );
		add_action( 'wp_ajax_revertix_save_settings', array( $this, 'save_settings' ) );
		add_action( 'wp_ajax_revertix_rollback_versions', array( $this, 'rollback_versions' ) );
		add_action( 'wp_ajax_revertix_rollback_run', array( $this, 'rollback_run' ) );

		// Binary downloads are served through admin-post (they stream a file).
		add_action( 'admin_post_revertix_download', array( $this, 'download' ) );
		add_action( 'admin_post_revertix_download_backup', array( $this, 'download_backup' ) );
		add_action( 'admin_post_revertix_download_log', array( $this, 'download_log' ) );
	}

	/* ---------------------------------------------------------------------
	 * Guards
	 * ------------------------------------------------------------------- */

	/**
	 * Verify capability + nonce for AJAX, or send a JSON error and die.
	 */
	protected function guard_ajax() {
		if ( ! current_user_can( revertix_capability() ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'revertix' ) ), 403 );
		}

		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed. Please reload the page.', 'revertix' ) ), 400 );
		}
	}

	/**
	 * Build a start-response: the public status plus the one-time continuation
	 * secret the browser must echo back on every run_job tick.
	 *
	 * @param Revertix_Job $job Newly created job.
	 * @return array
	 */
	protected function start_response( Revertix_Job $job ) {
		$status           = $job->public_status();
		$status['secret'] = $job->secret();
		$status['steps']  = $job->steps();
		return $status;
	}

	/**
	 * Ensure the environment can run heavy operations, or send a JSON error.
	 * Call this only from handlers that actually create/read packages.
	 */
	protected function require_environment() {
		$problems = Revertix_Requirements::check();
		if ( ! empty( $problems ) ) {
			$first = reset( $problems );
			wp_send_json_error( array( 'message' => $first['message'] ), 400 );
		}
	}

	/* ---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	/**
	 * Create a new export job and return its initial status.
	 *
	 * Accepts optional Advanced-export options. When 'advanced' is not '1', a
	 * full export (database + all files) is created.
	 */
	public function start_export() {
		$this->guard_ajax();
		$this->require_environment();

		$args = $this->parse_export_options();

		if ( empty( $args['include_db'] ) && empty( $args['include_files'] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Choose at least the database or some files to export.', 'revertix' ) ),
				400
			);
		}

		$job = Revertix_Export_Job::create( $args );
		wp_send_json_success( $this->start_response( $job ) );
	}

	/**
	 * Sanitize the export options from the request.
	 *
	 * @return array
	 */
	protected function parse_export_options() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$advanced = isset( $_POST['advanced'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['advanced'] ) );

		// Simple export: everything.
		if ( ! $advanced ) {
			return array(
				'include_db'    => true,
				'tables'        => array(),
				'include_files' => true,
				'file_areas'    => array_keys( Revertix_Filesystem::file_areas( 'export' ) ),
				'exclude_names' => array(),
				'plugins_mode'  => 'all',
				'plugins'       => array(),
				'themes_mode'   => 'all',
				'themes'        => array(),
			);
		}

		$include_db    = isset( $_POST['include_db'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['include_db'] ) );
		$include_files = isset( $_POST['include_files'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['include_files'] ) );

		// Tables: 'all' or an explicit subset.
		$db_mode = isset( $_POST['db_mode'] ) ? sanitize_key( wp_unslash( $_POST['db_mode'] ) ) : 'all';
		$tables  = array();
		if ( 'selected' === $db_mode && isset( $_POST['db_tables'] ) && is_array( $_POST['db_tables'] ) ) {
			$tables = array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['db_tables'] ) );
		}

		// File areas.
		$valid_areas = array_keys( Revertix_Filesystem::file_areas( 'export' ) );
		$areas       = array();
		if ( isset( $_POST['file_areas'] ) && is_array( $_POST['file_areas'] ) ) {
			$raw   = array_map( 'sanitize_key', wp_unslash( $_POST['file_areas'] ) );
			$areas = array_values( array_intersect( $valid_areas, $raw ) );
		}

		// Custom "excluded folders" list. Empty → the engine falls back to the
		// built-in defaults (.git, node_modules, …).
		$exclude_names = array();
		if ( isset( $_POST['exclude_names'] ) && is_array( $_POST['exclude_names'] ) ) {
			$exclude_names = self::sanitize_slugs( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['exclude_names'] ) ) );
		}

		// Per-item selection for plugins / themes.
		$plugins_mode = isset( $_POST['plugins_mode'] ) ? sanitize_key( wp_unslash( $_POST['plugins_mode'] ) ) : 'all';
		$plugins      = ( 'selected' === $plugins_mode && isset( $_POST['plugins'] ) && is_array( $_POST['plugins'] ) )
			? self::sanitize_slugs( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['plugins'] ) ) )
			: array();

		$themes_mode = isset( $_POST['themes_mode'] ) ? sanitize_key( wp_unslash( $_POST['themes_mode'] ) ) : 'all';
		$themes      = ( 'selected' === $themes_mode && isset( $_POST['themes'] ) && is_array( $_POST['themes'] ) )
			? self::sanitize_slugs( array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['themes'] ) ) )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'include_db'    => $include_db,
			'tables'        => $tables,
			'include_files' => $include_files,
			'file_areas'    => $areas,
			'exclude_names' => $exclude_names,
			'plugins_mode'  => 'selected' === $plugins_mode ? 'selected' : 'all',
			'plugins'       => $plugins,
			'themes_mode'   => 'selected' === $themes_mode ? 'selected' : 'all',
			'themes'        => $themes,
		);
	}

	/**
	 * Sanitize a list of file/dir slugs (plugin/theme folders, exclude names).
	 * Keeps letters, numbers, dot, dash and underscore; drops anything else.
	 *
	 * @param array $raw Raw values.
	 * @return string[]
	 */
	protected static function sanitize_slugs( array $raw ) {
		$clean = array();
		foreach ( $raw as $value ) {
			$value = preg_replace( '/[^A-Za-z0-9._-]/', '', (string) $value );
			if ( '' !== $value ) {
				$clean[] = $value;
			}
		}
		return array_values( array_unique( $clean ) );
	}

	/**
	 * Delete a saved export package.
	 */
	public function delete_package() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$id = isset( $_POST['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $id || ! Revertix_Package_Registry::get( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'This package no longer exists.', 'revertix' ) ), 404 );
		}

		Revertix_Package_Registry::delete( $id );
		wp_send_json_success( array( 'deleted' => $id ) );
	}

	/**
	 * Create a public download link for a package.
	 *
	 * The token comes back exactly once: only its hash is stored, so it cannot
	 * be shown again later.
	 */
	public function share_package() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$id = isset( $_POST['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';

		$args = array();
		foreach ( $_POST as $key => $value ) {
			if ( in_array( $key, array( 'action', 'nonce', 'id' ), true ) || ! is_scalar( $value ) ) {
				continue;
			}
			$args[ sanitize_key( $key ) ] = (string) wp_unslash( $value );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'This package no longer exists.', 'revertix' ) ), 404 );
		}

		/**
		 * Filter the options accepted when creating a share link.
		 *
		 * @param array  $args Requested options.
		 * @param string $id   Package id.
		 */
		$args = (array) apply_filters( 'revertix_share_request_args', $args, $id );

		$link = Revertix_Share::create( $id, $args );

		if ( is_wp_error( $link ) ) {
			wp_send_json_error( array( 'message' => $link->get_error_message() ), 404 );
		}

		wp_send_json_success(
			array(
				'url'     => $link['url'],
				'expires' => $link['expires']
					? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $link['expires'] )
					: '',
			)
		);
	}

	/**
	 * Return the live link for a package, so it can be read again.
	 */
	public function show_share() {
		$this->guard_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$id = isset( $_POST['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';

		$link = '' !== $id ? Revertix_Share::link( $id ) : null;

		if ( ! $link ) {
			wp_send_json_error(
				array( 'message' => __( 'This link is still valid but cannot be displayed - it was created before Revertix could store it readably. Revoke it and create a new one to see the address.', 'revertix' ) ),
				404
			);
		}

		wp_send_json_success(
			array(
				'url'     => $link['url'],
				'expires' => $link['expires']
					? date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $link['expires'] )
					: '',
			)
		);
	}

	/**
	 * Withdraw a package's public download link.
	 */
	public function unshare_package() {
		$this->guard_ajax();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$id = isset( $_POST['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';

		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'This package no longer exists.', 'revertix' ) ), 404 );
		}

		Revertix_Share::revoke( $id );
		wp_send_json_success( array( 'revoked' => $id ) );
	}

	/**
	 * Run one time-budgeted slice of an existing job.
	 */
	public function run_job() {
		// Verify the AJAX nonce up front (non-fatal): this endpoint also accepts a
		// per-job continuation secret for a caller whose session was destroyed by
		// a mid-import users-table replacement, so a missing nonce is not fatal by
		// itself - authorization is decided below.
		$has_nonce = (bool) check_ajax_referer( self::NONCE_ACTION, 'nonce', false );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- the nonce is verified above; these reads only locate the job so its per-job secret can be compared.
		$id    = isset( $_POST['job'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['job'] ) ) ) : '';
		$token = isset( $_POST['token'] ) ? preg_replace( '/[^a-zA-Z0-9]/', '', sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$job = '' !== $id ? Revertix_Job::load( $id ) : null;

		$by_secret = ( $job && '' !== $token && hash_equals( $job->secret(), $token ) );

		// Authorize: either a logged-in user with a valid nonce AND the required
		// capability, or the correct per-job secret. A nonce alone is never
		// sufficient for authorization.
		if ( ! $by_secret && ! ( $has_nonce && current_user_can( revertix_capability() ) ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'revertix' ) ), 403 );
		}

		$this->require_environment();

		if ( ! $job ) {
			wp_send_json_error( array( 'message' => __( 'This job no longer exists. Please start again.', 'revertix' ) ), 404 );
		}

		$status = $job->run();
		wp_send_json_success( $status );
	}

	/**
	 * Cancel a job and remove its scratch directory.
	 */
	public function cancel_job() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$id = isset( $_POST['job'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['job'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' !== $id ) {
			Revertix_Filesystem::delete_tree(
				Revertix_Storage::sub_dir( 'tmp' ) . $id,
				Revertix_Storage::sub_dir( 'tmp' )
			);
		}

		wp_send_json_success( array( 'cancelled' => true ) );
	}

	/* ---------------------------------------------------------------------
	 * Staging
	 * ------------------------------------------------------------------- */

	/**
	 * Create a staging environment from the live site.
	 */
	public function start_staging() {
		$this->guard_ajax();
		$this->require_environment();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// The name becomes the subdirectory/URL slug (stage, stage1, … by default).
		$slug = Revertix_Staging::make_slug( $name );
		if ( '' === $name ) {
			$name = $slug;
		}

		$job = Revertix_Staging_Job::create(
			array(
				'slug'       => $slug,
				'name'       => $name,
				'created_by' => $this->current_user_login(),
			)
		);

		wp_send_json_success( $this->start_response( $job ) );
	}

	/**
	 * The login of the current user (for staging "created by"). Empty if unknown.
	 *
	 * @return string
	 */
	protected function current_user_login() {
		$user = wp_get_current_user();
		return ( $user && $user->exists() ) ? $user->user_login : '';
	}

	/**
	 * Reset a staging environment: wipe it and re-clone the live site over it,
	 * keeping the same name and URL. Pulls in current code + database from live.
	 */
	public function reset_staging() {
		$this->guard_ajax();
		$this->require_environment();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$slug = isset( $_POST['slug'] ) ? preg_replace( '/[^a-z0-9]/i', '', sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$entry = '' !== $slug ? Revertix_Staging::get( $slug ) : null;
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'This staging site no longer exists.', 'revertix' ) ), 404 );
		}

		// Wipe the current copy (tables + files) so the re-clone is clean.
		Revertix_Database::drop_tables_with_prefix( Revertix_Staging::prefix_for( $slug ) );
		Revertix_Filesystem::delete_tree(
			rtrim( Revertix_Staging::dir_for( $slug ), '/' ),
			rtrim( Revertix_Filesystem::root(), '/' )
		);

		Revertix_Log::info( 'Staging reset - re-cloning from live.', array( 'slug' => $slug ) );

		// Re-create with the SAME slug + name (so URL is unchanged).
		$job = Revertix_Staging_Job::create(
			array(
				'slug'       => $slug,
				'name'       => isset( $entry['name'] ) ? $entry['name'] : $slug,
				'created_by' => ! empty( $entry['created_by'] ) ? $entry['created_by'] : $this->current_user_login(),
			)
		);

		wp_send_json_success( $this->start_response( $job ) );
	}

	/**
	 * Delete a staging environment (its tables + directory).
	 */
	public function delete_staging() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$slug = isset( $_POST['slug'] ) ? preg_replace( '/[^a-z0-9]/i', '', sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $slug || ! Revertix_Staging::get( $slug ) ) {
			wp_send_json_error( array( 'message' => __( 'This staging site no longer exists.', 'revertix' ) ), 404 );
		}

		Revertix_Staging::delete( $slug );
		wp_send_json_success( array( 'deleted' => $slug ) );
	}

	/* ---------------------------------------------------------------------
	 * Backups
	 * ------------------------------------------------------------------- */

	/**
	 * Create a backup (a full export stored in the backups folder).
	 *
	 * Also used to take the automatic safety backup before a restore — the
	 * 'safety' flag just labels it so the UI can show it differently.
	 */
	public function start_backup() {
		$this->guard_ajax();
		$this->require_environment();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$safety = isset( $_POST['safety'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['safety'] ) );
		$custom = isset( $_POST['custom'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['custom'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( $custom && ! $safety ) {
			$args = $this->parse_backup_options();
			if ( empty( $args['include_db'] ) && empty( $args['file_areas'] ) ) {
				wp_send_json_error(
					array( 'message' => __( 'Choose at least the database or some files to back up.', 'revertix' ) ),
					400
				);
			}
		} else {
			// Safety/default backup: a complete snapshot of the whole site.
			$args = array(
				'include_db'    => true,
				'include_files' => true,
				'file_areas'    => array_keys( Revertix_Filesystem::file_areas( 'backup' ) ),
			);
		}

		$job = Revertix_Export_Job::create(
			array(
				'include_db'    => ! empty( $args['include_db'] ),
				'tables'        => array(),
				'include_files' => ! empty( $args['file_areas'] ),
				'file_areas'    => $args['file_areas'],
				'store'         => 'backup',
				'label'         => $name,
				'safety'        => $safety,
			)
		);

		wp_send_json_success( $this->start_response( $job ) );
	}

	/**
	 * Parse the per-area backup checkboxes into export args.
	 *
	 * @return array{include_db:bool,file_areas:string[]}
	 */
	protected function parse_backup_options() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$map = array(
			'uploads'    => 'backup_uploads',
			'themes'     => 'backup_themes',
			'mu-plugins' => 'backup_mu',
			'plugins'    => 'backup_plugins',
			'wpcontent'  => 'backup_wpcontent',
			'root'       => 'backup_root',
		);

		$areas = array();
		foreach ( $map as $area => $field ) {
			if ( isset( $_POST[ $field ] ) && '1' === sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) ) {
				$areas[] = $area;
			}
		}

		// Only package areas this install actually offers. 
		$areas = array_values( array_intersect( $areas, array_keys( Revertix_Filesystem::file_areas( 'backup' ) ) ) );

		$include_db = isset( $_POST['backup_db'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['backup_db'] ) );
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return array(
			'include_db' => $include_db,
			'file_areas' => $areas,
		);
	}

	/**
	 * Start restoring a backup (reuses the import engine).
	 *
	 * Safety gates, in order: the backup must exist on disk and be a valid
	 * Revertix package (zip + manifest). Only then is the import job created.
	 * The browser is expected to have already taken a safety backup before
	 * calling this.
	 */
	public function start_restore() {
		$this->guard_ajax();
		$this->require_environment();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$id = isset( $_POST['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$entry = '' !== $id ? Revertix_Backup::get( $id ) : null;
		if ( ! $entry ) {
			wp_send_json_error( array( 'message' => __( 'This backup no longer exists.', 'revertix' ) ), 404 );
		}

		if ( ! Revertix_Backup::can_restore( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'This backup can’t be restored - its file is missing.', 'revertix' ) ), 404 );
		}

		// Containment: the file must live inside the backups folder.
		$base = Revertix_Filesystem::normalize( Revertix_Storage::sub_dir( 'backups' ) );
		$real = ! empty( $entry['path'] ) ? realpath( $entry['path'] ) : false;

		if ( ! $real || 0 !== strpos( Revertix_Filesystem::normalize( $real ), $base ) || ! is_file( $real ) ) {
			wp_send_json_error( array( 'message' => __( 'The backup file could not be found.', 'revertix' ) ), 404 );
		}

		// Validate it really is a Revertix package before we touch anything.
		if ( ! Revertix_Zip::has_manifest( $real ) ) {
			wp_send_json_error( array( 'message' => __( 'This backup is not a valid Revertix package and cannot be restored.', 'revertix' ) ), 400 );
		}

		Revertix_Log::info( 'Restore started from backup.', array( 'backup' => $id ) );

		$job = Revertix_Import_Job::create( array( 'source_path' => $real ) );
		wp_send_json_success( $this->start_response( $job ) );
	}

	/**
	 * Delete a backup (its file + registry entry).
	 */
	public function delete_backup() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$id = isset( $_POST['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $id || ! Revertix_Backup::get( $id ) ) {
			wp_send_json_error( array( 'message' => __( 'This backup no longer exists.', 'revertix' ) ), 404 );
		}

		if ( ! Revertix_Backup::can_delete( $id ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Your most recent backup is kept as a safety net and can’t be deleted.', 'revertix' ) ),
				403
			);
		}

		Revertix_Backup::delete( $id );
		wp_send_json_success( array( 'deleted' => $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Diagnostic log
	 * ------------------------------------------------------------------- */

	/**
	 * Empty the diagnostic log.
	 */
	public function clear_log() {
		$this->guard_ajax();

		Revertix_Log::clear();

		wp_send_json_success( array( 'cleared' => true ) );
	}

	/**
	 * Save the log retention settings (auto-delete toggle + window).
	 */
	public function save_log_settings() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$auto_prune = isset( $_POST['auto_prune'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['auto_prune'] ) );
		$retention  = isset( $_POST['retention_days'] ) ? absint( wp_unslash( $_POST['retention_days'] ) ) : 30;
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$saved = Revertix_Log::update_settings( $auto_prune, $retention );

		wp_send_json_success( $saved );
	}

	/**
	 * Save the general Revertix settings (backups, staging, notifications,
	 * uninstall). Revertix_Settings handles per-field sanitization and Pro gating,
	 * so anything the free install isn't allowed to change is ignored server-side.
	 */
	public function save_settings() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$input = array(
			'safety_keep'              => isset( $_POST['safety_keep'] ) ? absint( wp_unslash( $_POST['safety_keep'] ) ) : null,
			'rollback_keep'            => isset( $_POST['rollback_keep'] ) ? absint( wp_unslash( $_POST['rollback_keep'] ) ) : null,
			'staging_disable_auth'     => ! empty( $_POST['staging_disable_auth'] ),
			'remove_data_on_uninstall' => ! empty( $_POST['remove_data_on_uninstall'] ),
			'staging_bar_color'        => isset( $_POST['staging_bar_color'] ) ? sanitize_text_field( wp_unslash( $_POST['staging_bar_color'] ) ) : null,
			'staging_access_users'     => isset( $_POST['staging_access_users'] ) ? sanitize_text_field( wp_unslash( $_POST['staging_access_users'] ) ) : null,
			'staging_access_roles'     => isset( $_POST['staging_access_roles'] ) && is_array( $_POST['staging_access_roles'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['staging_access_roles'] ) )
				: null,
			'notify_email_errors'      => ! empty( $_POST['notify_email_errors'] ),
			'notify_email_warnings'    => ! empty( $_POST['notify_email_warnings'] ),
			'notify_email_status'      => ! empty( $_POST['notify_email_status'] ),
			'notify_email_html'        => ! empty( $_POST['notify_email_html'] ),
			'notify_email_address'     => isset( $_POST['notify_email_address'] ) ? sanitize_text_field( wp_unslash( $_POST['notify_email_address'] ) ) : null,
		);
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Drop the keys that weren't sent so update() keeps their stored value.
		$input = array_filter(
			$input,
			static function ( $value ) {
				return null !== $value;
			}
		);

		$saved = Revertix_Settings::update( $input );

		// Push staging-facing settings (login wall, bar colour, access) down to any
		// existing staging sites, so a change here takes effect immediately - not
		// only on staging copies created afterwards.
		Revertix_Staging::propagate_settings( $saved );

		Revertix_Log::info( 'Settings updated.' );

		wp_send_json_success( $saved );
	}

	/* ---------------------------------------------------------------------
	 * Rollback (plugin/theme versions)
	 * ------------------------------------------------------------------- */

	/**
	 * Lazily load the available versions + snapshots for one item.
	 */
	public function rollback_versions() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$type    = ( isset( $_POST['type'] ) && 'theme' === $_POST['type'] ) ? 'theme' : 'plugin';
		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$slug    = isset( $_POST['slug'] ) ? preg_replace( '/[^a-z0-9._-]/i', '', sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
		$current = isset( $_POST['current'] ) ? sanitize_text_field( wp_unslash( $_POST['current'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$versions = Revertix_Rollback::wporg_versions( $type, $slug );

		$list = array();
		if ( is_array( $versions ) ) {
			$latest = Revertix_Rollback::latest_version( $versions );
			// "previous" badge: the version right below what's installed.
			$prev = Revertix_Rollback::previous_version( $current, $versions );
			foreach ( $versions as $v ) {
				$installed = ( $v === $current );
				$list[] = array(
					'version'   => $v,
					'installed' => $installed,
					'allowed'   => $installed ? false : Revertix_Rollback::can_rollback_to( $v, $current, $versions ),
					'previous'  => ( $v === $prev ),
					'latest'    => ( $v === $latest ),
				);
			}
		}

		/**
		 * Snapshots recorded for this item, shaped for the browser. This plugin
		 * returns none - the separate Revertix PRO add-on provides user-managed
		 * snapshots and fills the list in.
		 *
		 * @param array  $snapshots Formatted snapshot entries.
		 * @param string $id        Item id (plugin file or theme stylesheet).
		 */
		$snapshots = (array) apply_filters( 'revertix_rollback_snapshots_list', array(), $id );

		wp_send_json_success(
			array(
				'source'    => is_array( $versions ) ? 'wporg' : 'custom',
				'premium'   => revertix_is_premium(),
				'current'   => $current,
				'versions'  => $list,
				'changelog' => is_array( $versions ) ? Revertix_Rollback::wporg_changelog( $type, $slug ) : '',
				'snapshots' => $snapshots,
			)
		);
	}

	/**
	 * Roll an item back to a released wordpress.org version.
	 *
	 * This handler only deals with wordpress.org versions. Rolling back to a
	 * user-managed snapshot is provided by the separate Revertix PRO add-on,
	 * which hooks the same AJAX action at an earlier priority and handles
	 * snapshot-sourced requests before this runs.
	 */
	public function rollback_run() {
		$this->guard_ajax();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$type    = ( isset( $_POST['type'] ) && 'theme' === $_POST['type'] ) ? 'theme' : 'plugin';
		$id      = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$slug    = isset( $_POST['slug'] ) ? preg_replace( '/[^a-z0-9._-]/i', '', sanitize_text_field( wp_unslash( $_POST['slug'] ) ) ) : '';
		$current = isset( $_POST['current'] ) ? sanitize_text_field( wp_unslash( $_POST['current'] ) ) : '';
		$version = isset( $_POST['version'] ) ? sanitize_text_field( wp_unslash( $_POST['version'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing item.', 'revertix' ) ) );
		}

		$versions = Revertix_Rollback::wporg_versions( $type, $slug );
		if ( ! is_array( $versions ) || ! Revertix_Rollback::can_rollback_to( $version, $current, $versions ) ) {
			wp_send_json_error(
				array( 'message' => __( 'That version isn’t available for this item.', 'revertix' ) )
			);
		}
		$package = Revertix_Rollback::package_for( $type, $slug, $version );

		/**
		 * Fires just before a wordpress.org-version rollback runs. Add-ons may
		 * take a safety copy here (the free plugin relies on re-downloading the
		 * previous release to undo a rollback).
		 *
		 * @param string $type    'plugin' | 'theme'.
		 * @param string $id      Item id.
		 * @param string $current Current (installed) version.
		 */
		do_action( 'revertix_before_rollback', $type, $id, $current );

		$result = Revertix_Rollback_Runner::rollback( $type, $id, $slug, $version, $package );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}

	/**
	 * Stream the diagnostic log as a text download, with a system-info header so
	 * a support report carries the context needed to reproduce the problem.
	 */
	public function download_log() {
		if ( ! current_user_can( revertix_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'revertix' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'revertix_download_log' );

		// One file with everything support needs: full system info + the log.
		$body  = Revertix_System_Info::as_text();
		$body .= "\n\n=== Diagnostic Log ===\n";
		$body .= Revertix_Log::read( 5242880 ); // up to 5 MB.

		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="revertix-report-' . gmdate( 'Ymd-His' ) . '.txt"' );
		header( 'Content-Length: ' . strlen( $body ) );

		while ( ob_get_level() ) {
			ob_end_clean();
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain-text download.
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------- */

	/**
	 * Receive one chunk of an uploaded package and append it to the part file.
	 *
	 * The browser slices the zip into small pieces so the upload never hits the
	 * server's upload_max_filesize / post_max_size limits. The first chunk
	 * (index 0) starts a fresh file; the final chunk finalizes and validates it.
	 *
	 * Expected POST: token, index, total, and a 'chunk' file part.
	 */
	public function upload_chunk() {
		$this->guard_ajax();
		$this->require_environment();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$token = isset( $_POST['token'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
		$index = isset( $_POST['index'] ) ? absint( wp_unslash( $_POST['index'] ) ) : 0;
		$total = isset( $_POST['total'] ) ? absint( wp_unslash( $_POST['total'] ) ) : 0;

		if ( '' === $token || $total < 1 || $index >= $total ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload request.', 'revertix' ) ), 400 );
		}

		if ( empty( $_FILES['chunk'] ) || ! isset( $_FILES['chunk']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No data was received for this chunk.', 'revertix' ) ), 400 );
		}

		// The file part is validated below (error code + is_uploaded_file) and its
		// bytes are streamed to disk; only tmp_name/error are ever read from it.
		$chunk = $_FILES['chunk']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		if ( ! empty( $chunk['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'A chunk failed to upload. Please try again.', 'revertix' ) ), 400 );
		}

		$tmp = isset( $chunk['tmp_name'] ) ? $chunk['tmp_name'] : '';
		if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
			wp_send_json_error( array( 'message' => __( 'The chunk could not be verified.', 'revertix' ) ), 400 );
		}

		$imports = Revertix_Storage::sub_dir( 'imports' );
		wp_mkdir_p( $imports );
		Revertix_Storage::harden( $imports );

		$part = $imports . $token . '.zip.part';

		// Containment guard: the part file must stay inside the imports folder.
		$base = Revertix_Filesystem::normalize( $imports );
		if ( 0 !== strpos( Revertix_Filesystem::normalize( $part ), $base ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload target.', 'revertix' ) ), 403 );
		}

		$bytes = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $bytes ) {
			wp_send_json_error( array( 'message' => __( 'Could not read the uploaded chunk.', 'revertix' ) ), 500 );
		}

		// First chunk truncates/creates; the rest append in order.
		$flags   = ( 0 === $index ) ? 0 : FILE_APPEND;
		$written = file_put_contents( $part, $bytes, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written ) {
			wp_send_json_error( array( 'message' => __( 'Could not store the uploaded chunk.', 'revertix' ) ), 500 );
		}

		// Not the last chunk yet - acknowledge and wait for more.
		if ( $index + 1 < $total ) {
			wp_send_json_success(
				array(
					'done'     => false,
					'received' => $index + 1,
					'total'    => $total,
				)
			);
		}

		// Last chunk: finalize into a .zip and validate it's a Revertix package.
		$final = $imports . $token . '.zip';
		@unlink( $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		if ( ! @rename( $part, $final ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_send_json_error( array( 'message' => __( 'Could not assemble the uploaded package.', 'revertix' ) ), 500 );
		}

		if ( ! Revertix_Zip::has_manifest( $final ) ) {
			@unlink( $final ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			wp_send_json_error( array( 'message' => __( 'This zip is not a Revertix package (no manifest found).', 'revertix' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'done'  => true,
				'token' => $token,
			)
		);
	}

	/**
	 * Create an import job from either an uploaded token or a saved package id.
	 */
	public function start_import() {
		$this->guard_ajax();
		$this->require_environment();

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- nonce checked in guard_ajax().
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : '';
		$path   = '';

		if ( 'upload' === $source ) {
			$token = isset( $_POST['token'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['token'] ) ) ) : '';
			$path  = Revertix_Storage::sub_dir( 'imports' ) . $token . '.zip';
		} elseif ( 'package' === $source ) {
			$id    = isset( $_POST['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_POST['id'] ) ) ) : '';
			$entry = Revertix_Package_Registry::get( $id );
			$path  = ( $entry && ! empty( $entry['path'] ) ) ? $entry['path'] : '';
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Containment: the source must live inside Revertix's storage.
		$base = Revertix_Filesystem::normalize( Revertix_Storage::base_dir() );
		$real = $path ? realpath( $path ) : false;

		if ( ! $real || 0 !== strpos( Revertix_Filesystem::normalize( $real ), $base ) || ! is_file( $real ) ) {
			wp_send_json_error( array( 'message' => __( 'The package to import could not be found.', 'revertix' ) ), 404 );
		}

		$job = Revertix_Import_Job::create( array( 'source_path' => $real ) );
		wp_send_json_success( $this->start_response( $job ) );
	}

	/* ---------------------------------------------------------------------
	 * Download (admin-post, streams the finished package)
	 * ------------------------------------------------------------------- */

	/**
	 * Stream a finished export package to the browser.
	 */
	public function download() {
		if ( ! current_user_can( revertix_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'revertix' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'revertix_download' );

		$id = isset( $_GET['job'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_GET['job'] ) ) ) : '';

		// Resolve the package from the persistent registry so downloads keep
		// working after the page is reloaded and scratch state is gone.
		$entry = Revertix_Package_Registry::get( $id );
		if ( ! $entry || empty( $entry['path'] ) ) {
			wp_die( esc_html__( 'Package not found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		$path = $entry['path'];

		// Containment guard: only serve files from inside the packages folder.
		$packages = Revertix_Filesystem::normalize( Revertix_Storage::sub_dir( 'packages' ) );
		$real     = $path ? realpath( $path ) : false;

		if ( ! $real || 0 !== strpos( Revertix_Filesystem::normalize( $real ), $packages ) || ! is_file( $real ) ) {
			wp_die( esc_html__( 'Package not found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		Revertix_Filesystem::stream_download( $real, Revertix_Storage::untokenize( basename( $real ) ) );
	}

	/**
	 * Stream a backup zip to the browser.
	 */
	public function download_backup() {
		if ( ! current_user_can( revertix_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'revertix' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'revertix_download_backup' );

		$id = isset( $_GET['id'] ) ? Revertix_Job::sanitize_id( sanitize_text_field( wp_unslash( $_GET['id'] ) ) ) : '';

		$entry = Revertix_Backup::get( $id );
		if ( ! $entry || empty( $entry['path'] ) ) {
			wp_die( esc_html__( 'Backup not found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		if ( ! Revertix_Backup::can_download( $id ) ) {
			wp_die( esc_html__( 'This backup could not be found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		// Containment guard: only serve files from inside the backups folder.
		$backups = Revertix_Filesystem::normalize( Revertix_Storage::sub_dir( 'backups' ) );
		$real    = realpath( $entry['path'] );

		if ( ! $real || 0 !== strpos( Revertix_Filesystem::normalize( $real ), $backups ) || ! is_file( $real ) ) {
			wp_die( esc_html__( 'Backup not found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		Revertix_Filesystem::stream_download( $real, Revertix_Storage::untokenize( basename( $real ) ) );
	}
}
