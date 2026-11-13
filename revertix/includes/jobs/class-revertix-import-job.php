<?php
/**
 * Import job: restore a Revertix package onto this installation.
 *
 * Stages (each resumable):
 *   1. unzip         - extract the package into a scratch folder, in batches
 *   2. read_manifest - load manifest.json; build the URL/path replacement map
 *   3. import_db     - replay database.sql, rewriting the table prefix
 *   4. restore_files - copy package files back into the site (protected paths
 *                      such as wp-config.php and Revertix itself are skipped)
 *   5. search_replace- serialize-safe rewrite of old URLs/paths across the DB
 *   6. finalize      - clean up scratch and flush caches
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Restores a package built by Revertix_Export_Job.
 */
class Revertix_Import_Job extends Revertix_Job {

	/**
	 * The must-use plugin that holds the site's plugins back during an import.
	 */
	const GUARD_FILE = 'revertix-import-guard.php';

	/**
	 * {@inheritDoc}
	 */
	public static function type_slug() {
		return 'import';
	}

	/**
	 * {@inheritDoc}
	 */
	public function stages() {
		// Files before the database, deliberately. The database carries the source
		// site's list of active plugins; loading it first tells WordPress to run
		// plugins whose files have not arrived yet, and the next request dies on a
		// missing include - taking the import with it.
		return array( 'unzip', 'read_manifest', 'restore_files', 'import_db', 'search_replace', 'swap_core', 'finalize' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step_labels() {
		return array(
			'unzip'          => __( 'Extracting package', 'revertix' ),
			'read_manifest'  => __( 'Reading package', 'revertix' ),
			'import_db'      => __( 'Importing database', 'revertix' ),
			'restore_files'  => __( 'Restoring files', 'revertix' ),
			'search_replace' => __( 'Updating URLs', 'revertix' ),
			'swap_core'      => __( 'Installing WordPress', 'revertix' ),
			'finalize'       => __( 'Finishing up', 'revertix' ),
		);
	}

	/**
	 * Entries extracted per chunk.
	 *
	 * @return int
	 */
	protected function unzip_per_chunk() {
		return (int) apply_filters( 'revertix_import_unzip_per_chunk', 300 );
	}

	/**
	 * Files restored per chunk.
	 *
	 * @return int
	 */
	protected function restore_per_chunk() {
		return (int) apply_filters( 'revertix_import_restore_per_chunk', 200 );
	}

	/**
	 * Rows scanned per search-replace chunk (CPU-heavy, so smaller).
	 *
	 * @return int
	 */
	protected function sr_rows_per_chunk() {
		return (int) apply_filters( 'revertix_import_sr_rows_per_chunk', 150 );
	}

	/**
	 * Prepare state from args: state['args']['source_path'] is the package zip.
	 */
	protected function init() {
		$this->state['data'] = array(
			'source_path'   => isset( $this->state['args']['source_path'] ) ? $this->state['args']['source_path'] : '',
			'extract_dir'   => $this->dir() . 'extract/',
			// unzip
			'unzip_idx'     => 0,
			'unzip_total'   => 0,
			// manifest-derived
			'old_prefix'    => '',
			'new_prefix'    => '',
			'has_db'        => false,
			'has_files'     => false,
			'search'        => array(),
			'replace'       => array(),
			'sr_tables'     => array(),
			// import_db
			'db_offset'     => 0,
			// restore_files
			'restore_bytes' => 0,
			'restored'      => 0,
			'restore_failed' => 0,
			// search_replace
			'sr_table_idx'  => 0,
			'sr_row_offset' => 0,
			'sr_pk'         => array(),
			'sr_changed'    => 0,
		);

		$this->start_guard();
	}

	/**
	 * Keep every plugin but Revertix switched off until the import is finished.
	 *
	 * Ordering the stages carefully is not quite enough on its own: the moment the
	 * imported database lands, WordPress is told to run the source site's plugins,
	 * and any one of them that objects to this environment takes down the requests
	 * that drive the import. So a must-use plugin filters the active list down to
	 * Revertix for the duration, and finalize removes it.
	 *
	 * The guard carries its own expiry, so an import that dies half-way cannot
	 * leave a site with its plugins permanently switched off. It cannot rely on
	 * anything in the database: the import replaces that.
	 */
	protected function start_guard() {
		$dir = trailingslashit( Revertix_Filesystem::content_dir() ) . 'mu-plugins/';
		wp_mkdir_p( $dir );

		$code = <<<'GUARD'
<?php
/**
 * Plugin Name: Revertix import guard
 * Description: Temporary. Holds this site's plugins back while Revertix restores a package. Removed automatically when the import finishes.
 */

// Written when the import began. Deliberately a literal rather than an option:
// an import replaces wp_options part-way through, and a guard that asked the
// database whether it was still needed would switch itself off at exactly the
// wrong moment - which is how it first went wrong.
$revertix_import_started = %d;

add_filter(
	'option_active_plugins',
	static function ( $plugins ) use ( $revertix_import_started ) {
		// Never outlive the import: if this file is somehow left behind, it stops
		// mattering after an hour rather than disabling the site's plugins for good.
		if ( ( time() - $revertix_import_started ) > HOUR_IN_SECONDS ) {
			return $plugins;
		}

		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		return array_values(
			array_filter(
				$plugins,
				static function ( $plugin ) {
					return 0 === strpos( (string) $plugin, 'revertix' );
				}
			)
		);
	}
);
GUARD;

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $dir . self::GUARD_FILE, sprintf( $code, time() ) . "\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Drop what the imported database says about pending updates.
	 *
	 * Those records describe another server at another moment: which plugins had
	 * updates waiting, where to download them, when the check last ran. WordPress
	 * trusts them on the very next request and can start installing straight
	 * away - putting the freshly imported site into maintenance mode before
	 * anyone has even logged in, and leaving it there if the attempt fails.
	 *
	 * Clearing them costs nothing: WordPress simply checks again, against this
	 * site, in its own time.
	 */
	protected function forget_update_state() {
		foreach ( array( 'update_core', 'update_plugins', 'update_themes' ) as $transient ) {
			delete_site_transient( $transient );
		}

		delete_option( 'auto_updater.lock' );
		delete_option( 'core_updater.lock' );
	}

	/**
	 * Let the site's plugins run again.
	 */
	protected function end_guard() {
		$file = trailingslashit( Revertix_Filesystem::content_dir() ) . 'mu-plugins/' . self::GUARD_FILE;

		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step() {
		switch ( $this->state['stage'] ) {
			case 'unzip':
				$this->step_unzip();
				break;
			case 'read_manifest':
				$this->step_read_manifest();
				break;
			case 'import_db':
				$this->step_import_db();
				break;
			case 'restore_files':
				$this->step_restore_files();
				break;
			case 'search_replace':
				$this->step_search_replace();
				break;
			case 'swap_core':
				$this->step_swap_core();
				break;
			case 'finalize':
				$this->step_finalize();
				break;
			default:
				$this->complete();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 1: unzip
	 * ------------------------------------------------------------------- */

	/**
	 * Extract the package in batches.
	 */
	protected function step_unzip() {
		$source = $this->state['data']['source_path'];

		if ( '' === $source || ! is_file( $source ) ) {
			$this->fail( __( 'The package file could not be found.', 'revertix' ) );
			return;
		}

		$dest = $this->state['data']['extract_dir'];

		// The package is unpacked in full before anything is restored, so the
		// disk has to hold the archive AND its contents at the same time. Running
		// out mid-way leaves a half-extracted package and a confusing failure.
		if ( 0 === (int) $this->state['data']['unzip_idx'] && ! $this->check_free_space( $source ) ) {
			return;
		}

		wp_mkdir_p( $dest );

		while ( $this->time_left() > 3.0 ) {
			$result = Revertix_Zip::extract_batch(
				$source,
				$dest,
				$this->state['data']['unzip_idx'],
				$this->unzip_per_chunk()
			);

			$this->state['data']['unzip_total'] = $result['total'];
			$this->state['data']['unzip_idx']   = $result['next'];

			$this->set_progress( $result['next'], max( 1, $result['total'] ) );
			$this->state['message'] = sprintf(
				/* translators: 1: extracted entries, 2: total entries. */
				__( 'Extracting package… %1$s of %2$s', 'revertix' ),
				revertix_number( $result['next'] ),
				revertix_number( $result['total'] )
			);

			if ( $result['next'] >= $result['total'] ) {
				$this->advance_stage();
				return;
			}
		}
	}

	/**
	 * Refuse to unpack when the disk cannot hold the extracted package.
	 *
	 * @param string $source Absolute path to the package.
	 * @return bool True when there is room (or we cannot tell).
	 */
	protected function check_free_space( $source ) {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return true;
		}

		$size = (int) @filesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( $size <= 0 ) {
			return true;
		}

		$free = @disk_free_space( Revertix_Storage::base_dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $free ) {
			return true;
		}

		// Extracted contents are at least the archive size; add headroom for the
		// database dump and the files copied into place afterwards.
		$required = (int) ( $size * 1.5 );
		if ( $free >= $required ) {
			return true;
		}

		Revertix_Log::error(
			'Import stopped: not enough free disk space.',
			array(
				'job'      => $this->id,
				'required' => $required,
				'free'     => (int) $free,
			)
		);

		$this->fail(
			sprintf(
				/* translators: 1: required space, 2: available space. */
				__( 'Not enough free disk space to unpack this package: about %1$s is needed and only %2$s is free. Free up some space and try again.', 'revertix' ),
				revertix_size( $required ),
				revertix_size( (int) $free )
			)
		);

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Stage 2: read manifest, build replacement map
	 * ------------------------------------------------------------------- */

	/**
	 * Load the manifest and compute the URL/path replacements.
	 */
	protected function step_read_manifest() {
		$manifest_path = $this->state['data']['extract_dir'] . 'manifest.json';

		if ( ! is_file( $manifest_path ) ) {
			$this->fail( __( 'This package is missing its manifest and cannot be imported.', 'revertix' ) );
			return;
		}

		$manifest = Revertix_Manifest::from_json( (string) file_get_contents( $manifest_path ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( null === $manifest ) {
			$this->fail( __( 'The package manifest is invalid.', 'revertix' ) );
			return;
		}

		global $wpdb;
		$old = isset( $manifest['site'] ) ? $manifest['site'] : array();

		$this->state['data']['old_prefix'] = isset( $manifest['database']['table_prefix'] ) ? $manifest['database']['table_prefix'] : $wpdb->prefix;
		$this->state['data']['new_prefix'] = $wpdb->prefix;
		$this->state['data']['has_db']     = is_file( $this->state['data']['extract_dir'] . 'database.sql' );
		$this->state['data']['has_files']  = is_file( $this->state['data']['extract_dir'] . 'files-index.txt' );

		// Build the from→to map: URLs first, then filesystem paths.
		$uploads = wp_upload_dir();
		$map     = array();

		if ( ! empty( $old['home_url'] ) ) {
			$map[ $old['home_url'] ] = home_url();
		}
		if ( ! empty( $old['site_url'] ) ) {
			$map[ $old['site_url'] ] = site_url();
		}
		if ( ! empty( $old['uploads_url'] ) ) {
			$map[ $old['uploads_url'] ] = $uploads['baseurl'];
		}
		if ( ! empty( $old['abspath'] ) ) {
			$map[ $old['abspath'] ] = Revertix_Filesystem::normalize( Revertix_Filesystem::root() );
		}
		if ( ! empty( $old['content_dir'] ) ) {
			$map[ $old['content_dir'] ] = Revertix_Filesystem::normalize( Revertix_Filesystem::content_dir() );
		}
		if ( ! empty( $old['uploads_dir'] ) ) {
			$map[ $old['uploads_dir'] ] = Revertix_Filesystem::normalize( $uploads['basedir'] );
		}

		list( $search, $replace )         = Revertix_Search_Replace::prepare_map( $map );
		$this->state['data']['search']    = $search;
		$this->state['data']['replace']   = $replace;

		// Limit the search-replace to the tables we actually imported.
		$tables = isset( $manifest['database']['tables'] ) ? (array) $manifest['database']['tables'] : array();
		$mapped = array();
		foreach ( $tables as $t ) {
			$mapped[] = $this->state['data']['old_prefix'] === $this->state['data']['new_prefix']
				? $t
				: preg_replace( '/^' . preg_quote( $this->state['data']['old_prefix'], '/' ) . '/', $this->state['data']['new_prefix'], $t, 1 );
		}
		$this->state['data']['sr_tables'] = array_values( array_intersect( $mapped, Revertix_Database::site_tables() ) );

		$db_path = $this->state['data']['extract_dir'] . 'database.sql';

		$versions = isset( $manifest['versions'] ) ? (array) $manifest['versions'] : array();

		Revertix_Log::info(
			'Import package read.',
			array(
				'package'      => basename( (string) $this->state['data']['source_path'] ),
				'format'       => isset( $manifest['format'] ) ? $manifest['format'] : '?',
				'created'      => isset( $manifest['created'] ) ? $manifest['created'] : '?',
				'from_revertix' => isset( $versions['revertix'] ) ? $versions['revertix'] : '?',
				'from_wp'      => isset( $versions['wp'] ) ? $versions['wp'] : '?',
				'from_php'     => isset( $versions['php'] ) ? $versions['php'] : '?',
				'old_prefix'   => $this->state['data']['old_prefix'],
				'new_prefix'   => $this->state['data']['new_prefix'],
				'has_db'       => $this->state['data']['has_db'] ? 'yes' : 'no',
				'has_files'    => $this->state['data']['has_files'] ? 'yes' : 'no',
				'db_bytes'     => $this->state['data']['has_db'] && is_file( $db_path ) ? (int) filesize( $db_path ) : 0,
				'sr_tables'    => count( $this->state['data']['sr_tables'] ),
				'replacements' => count( $this->state['data']['search'] ),
			)
		);

		// The package's WordPress replaces this site's, so the version can change
		// - including downwards. That is what restoring a site means, but it is
		// worth saying out loud rather than leaving to be discovered.
		$package_wp = isset( $versions['wp'] ) ? (string) $versions['wp'] : '';
		$this->state['data']['wp_mismatch'] = '';

		if ( '' !== $package_wp && get_bloginfo( 'version' ) !== $package_wp ) {
			$this->state['data']['wp_mismatch'] = $package_wp;

			Revertix_Log::info(
				'Package carries a different WordPress version; this site will be moved to it.',
				array(
					'package_wp' => $package_wp,
					'site_wp'    => get_bloginfo( 'version' ),
				)
			);
		}

		$this->state['message'] = __( 'Reading package…', 'revertix' );
		$this->advance_stage();
	}

	/* ---------------------------------------------------------------------
	 * Stage 3: import database
	 * ------------------------------------------------------------------- */

	/**
	 * Replay database.sql in resumable chunks.
	 */
	protected function step_import_db() {
		if ( empty( $this->state['data']['has_db'] ) ) {
			$this->advance_stage();
			return;
		}

		$sql_path = $this->state['data']['extract_dir'] . 'database.sql';
		$fh       = fopen( $sql_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not read the database dump from the package.', 'revertix' ) );
			return;
		}

		fseek( $fh, (int) $this->state['data']['db_offset'] );

		$result = Revertix_Database::import_sql(
			$fh,
			function () {
				return $this->time_left();
			},
			$this->state['data']['old_prefix'],
			$this->state['data']['new_prefix']
		);

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->state['data']['db_offset'] = $result['offset'];

		$size = (int) filesize( $sql_path );
		$this->set_progress( $result['offset'], max( 1, $size ) );
		$this->state['message'] = __( 'Importing database…', 'revertix' );

		if ( $result['done'] ) {
			$this->advance_stage();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 4: restore files
	 * ------------------------------------------------------------------- */

	/**
	 * Copy extracted files back into the site root, skipping protected paths.
	 */
	protected function step_restore_files() {
		if ( empty( $this->state['data']['has_files'] ) ) {
			$this->advance_stage();
			return;
		}

		$index_path = $this->state['data']['extract_dir'] . 'files-index.txt';
		$files_root = $this->state['data']['extract_dir'] . 'files/';
		$site_root  = trailingslashit( Revertix_Filesystem::root() );

		$fh = fopen( $index_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not read the file list from the package.', 'revertix' ) );
			return;
		}

		fseek( $fh, (int) $this->state['data']['restore_bytes'] );

		$processed = 0;
		$per_chunk = $this->restore_per_chunk();

		while ( $this->time_left() > 3.0 ) {
			$line = fgets( $fh );

			if ( false === $line ) {
				$this->state['data']['restore_bytes'] = ftell( $fh );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->advance_stage();
				return;
			}

			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( "\t", $line );
			$rel   = Revertix_Filesystem::safe_relative( $parts[0] );

			// Reject traversal / absolute / unsafe entries from the (untrusted)
			// package index before they become a source or destination path.
			if ( false === $rel ) {
				continue;
			}

			// WordPress' own files are written to a staging folder rather than
			// straight over the install: see step_swap_core() for why.
			$is_core = Revertix_Filesystem::is_core_relative( $rel );
			$target  = $is_core ? $this->core_stage_dir() . $rel : $site_root . $rel;

			if ( $is_core || ! Revertix_Filesystem::is_restore_excluded( $rel ) ) {
				$source = $files_root . $rel;
				if ( is_file( $source ) ) {
					if ( Revertix_Filesystem::copy_file( $source, $target ) ) {
						++$this->state['data']['restored'];
					} else {
						++$this->state['data']['restore_failed'];
						// Log the first handful so a permissions/disk problem is
						// visible without flooding the log on a large site.
						if ( $this->state['data']['restore_failed'] <= 20 ) {
							$last  = error_get_last();
							$why   = ( $last && ! empty( $last['message'] ) ) ? $last['message'] : 'unknown error';
							Revertix_Log::warning(
								'Could not restore a file.',
								array(
									'file'   => $rel,
									'reason' => $why,
								)
							);
						}
					}
				}
			}

			++$processed;

			if ( $processed >= $per_chunk ) {
				$this->state['data']['restore_bytes'] = ftell( $fh );
				$this->state['message']               = sprintf(
					/* translators: %s: number of files restored. */
					__( 'Restoring files… %s copied', 'revertix' ),
					revertix_number( $this->state['data']['restored'] )
				);
				$processed = 0;
			}
		}

		$this->state['data']['restore_bytes'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------------
	 * Stage 5: serialize-safe search & replace
	 * ------------------------------------------------------------------- */

	/**
	 * Rewrite old URLs/paths across the imported tables.
	 */
	protected function step_search_replace() {
		$tables = $this->state['data']['sr_tables'];

		// Nothing to replace (e.g. same site/paths) or no tables → skip.
		if ( empty( $this->state['data']['search'] ) || empty( $tables ) ) {
			$this->advance_stage();
			return;
		}

		global $wpdb;
		$search  = $this->state['data']['search'];
		$replace = $this->state['data']['replace'];
		$total   = count( $tables );

		while ( $this->time_left() > 2.5 ) {
			$idx = $this->state['data']['sr_table_idx'];

			if ( $idx >= $total ) {
				$this->advance_stage();
				return;
			}

			$table = $tables[ $idx ];

			// Resolve the primary key once per table.
			if ( 0 === $this->state['data']['sr_row_offset'] || ! isset( $this->state['data']['sr_pk'][ $table ] ) ) {
				$this->state['data']['sr_pk'][ $table ] = Revertix_Database::primary_key( $table );
			}
			$pk = $this->state['data']['sr_pk'][ $table ];

			$rows = Revertix_Database::fetch_rows(
				$table,
				$this->state['data']['sr_row_offset'],
				$this->sr_rows_per_chunk()
			);

			foreach ( $rows as $row ) {
				$set = array();
				foreach ( $row as $col => $value ) {
					if ( ! is_string( $value ) || '' === $value ) {
						continue;
					}
					$new = Revertix_Search_Replace::apply( $value, $search, $replace, false );
					if ( $new !== $value ) {
						$set[ $col ] = $new;
					}
				}

				if ( ! empty( $set ) ) {
					$where = $this->row_where( $row, $pk );
					if ( ! empty( $where ) ) {
						// phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$wpdb->update( $table, $set, $where );
						++$this->state['data']['sr_changed'];
					}
				}
			}

			if ( count( $rows ) < $this->sr_rows_per_chunk() ) {
				// Table finished.
				$this->state['data']['sr_table_idx']  = $idx + 1;
				$this->state['data']['sr_row_offset'] = 0;
			} else {
				$this->state['data']['sr_row_offset'] += count( $rows );
			}

			$this->set_progress( $this->state['data']['sr_table_idx'], $total );
			$this->state['message'] = sprintf(
				/* translators: 1: current table number, 2: total tables. */
				__( 'Updating URLs and paths… table %1$d of %2$d', 'revertix' ),
				min( $this->state['data']['sr_table_idx'] + 1, $total ),
				$total
			);
		}
	}

	/**
	 * Build a WHERE clause for a row: prefer the primary key, otherwise fall
	 * back to all non-null columns.
	 *
	 * @param array<string,mixed> $row Row data.
	 * @param string[]            $pk  Primary-key columns.
	 * @return array<string,mixed>
	 */
	protected function row_where( array $row, array $pk ) {
		if ( ! empty( $pk ) ) {
			$where = array();
			foreach ( $pk as $col ) {
				if ( array_key_exists( $col, $row ) ) {
					$where[ $col ] = $row[ $col ];
				}
			}
			return $where;
		}

		// No primary key: match on all non-null columns (best effort).
		return array_filter(
			$row,
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/* ---------------------------------------------------------------------
	 * Stage 6: swap in the package's WordPress
	 * ------------------------------------------------------------------- */

	/**
	 * Where the package's copy of WordPress is assembled before it goes live.
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	protected function core_stage_dir() {
		return trailingslashit( Revertix_Filesystem::root() ) . '.revertix-core-new/';
	}

	/**
	 * Put the package's WordPress in place of this site's.
	 *
	 * The restore cannot simply write these files where they belong. An import
	 * runs as a series of AJAX requests that WordPress itself serves, so the
	 * moment wp-settings.php belongs to one release while wp-includes still
	 * belongs to another, the site stops loading - and with it the requests that
	 * would have finished the job. That is exactly how an import used to strand
	 * itself part-way behind a fatal error.
	 *
	 * So the files were copied into a staging folder as they were restored, and
	 * here they are swapped in by renaming, which is one filesystem operation per
	 * folder rather than thousands per file. The whole swap happens inside this
	 * single request, so no tick of this job ever sees a half-swapped install.
	 *
	 * A visitor could, though: for the moment between renaming wp-includes and
	 * renaming wp-settings.php, the two belong to different releases. That is
	 * precisely the situation WordPress' own core updater faces, and it is solved
	 * the same way - a .maintenance file, so anyone arriving mid-swap is told the
	 * site is briefly unavailable instead of meeting a fatal error. If this
	 * request dies half-way the file stays, which is the safe failure: a holding
	 * page rather than a broken site.
	 */
	/**
	 * {@inheritDoc}
	 *
	 * An import that gives up has to undo what it put in place first, or it
	 * leaves the site behind a holding page with its plugins switched off.
	 *
	 * @param string $message Failure message.
	 */
	protected function fail( $message ) {
		$this->end_guard();
		$this->end_maintenance();

		parent::fail( $message );
	}

	/**
	 * Hold visitors on a "briefly unavailable" page while WordPress is swapped.
	 *
	 * The same mechanism, and the same file, that core's own updater uses.
	 */
	protected function start_maintenance() {
		$file = trailingslashit( Revertix_Filesystem::root() ) . '.maintenance';

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		@file_put_contents( $file, '<?php $upgrading = ' . time() . '; // [revertix] replacing WordPress' . "\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	/**
	 * Let visitors back in.
	 */
	protected function end_maintenance() {
		$file = trailingslashit( Revertix_Filesystem::root() ) . '.maintenance';

		if ( is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}

	protected function step_swap_core() {
		$stage = $this->core_stage_dir();

		// A wp-content-only package brings no WordPress with it.
		if ( ! is_dir( $stage ) ) {
			$this->advance_stage();
			return;
		}

		$root = trailingslashit( Revertix_Filesystem::root() );

		// Refuse to swap in something incomplete: far better to keep the
		// WordPress that is running than to install half of another one.
		foreach ( array( 'wp-settings.php', 'wp-includes/version.php', 'wp-includes/functions.php' ) as $proof ) {
			if ( ! is_file( $stage . $proof ) ) {
				Revertix_Log::warning(
					'The package did not contain a complete copy of WordPress; this site keeps its own.',
					array( 'missing' => $proof )
				);
				Revertix_Filesystem::delete_tree( untrailingslashit( $stage ), untrailingslashit( $root ) );
				$this->advance_stage();
				return;
			}
		}

		$old = $root . '.revertix-core-old/';
		Revertix_Filesystem::mkdir_p( $old );

		// Make sure the new folders are reachable by the web server before they
		// go live. The very next tick of this job is served through
		// wp-admin/admin-ajax.php, so a wp-admin the server cannot enter (0750 on
		// cPanel) would strand the import here and lock everyone out of the admin.
		$perms_before = array();
		foreach ( array( 'wp-includes', 'wp-admin' ) as $dir ) {
			$perms_before[ $dir ] = self::perms_of( $root . $dir );
			if ( is_dir( $stage . $dir ) ) {
				$this->fix_stage_dir_perms( $stage . $dir );
			}
		}

		$this->start_maintenance();

		// Directories first, so wp-includes is already the new one by the time
		// anything loads the new wp-settings.php.
		foreach ( array( 'wp-includes', 'wp-admin' ) as $dir ) {
			if ( ! is_dir( $stage . $dir ) ) {
				continue;
			}

			if ( is_dir( $root . $dir ) && ! @rename( $root . $dir, $old . $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$this->end_maintenance();
				$this->fail(
					sprintf(
						/* translators: %s: folder name. */
						__( 'Could not replace WordPress: the %s folder could not be moved aside. Nothing was changed.', 'revertix' ),
						$dir
					)
				);
				return;
			}

			if ( ! @rename( $stage . $dir, $root . $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@rename( $old . $dir, $root . $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$this->end_maintenance();
				$this->fail(
					sprintf(
						/* translators: %s: folder name. */
						__( 'Could not replace WordPress: the new %s folder could not be moved into place. The original was put back.', 'revertix' ),
						$dir
					)
				);
				return;
			}
		}

		// Then the loose files, wp-settings.php last of all.
		$files = array();
		foreach ( Revertix_Filesystem::core_relatives() as $rel ) {
			if ( '/' !== substr( $rel, -1 ) && 'wp-settings.php' !== $rel && is_file( $stage . $rel ) ) {
				$files[] = $rel;
			}
		}
		if ( is_file( $stage . 'wp-settings.php' ) ) {
			$files[] = 'wp-settings.php';
		}

		$swapped = 0;
		foreach ( $files as $rel ) {
			if ( @rename( $stage . $rel, $root . $rel ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				++$swapped;
			}
		}

		Revertix_Filesystem::delete_tree( untrailingslashit( $old ), untrailingslashit( $root ) );
		Revertix_Filesystem::delete_tree( untrailingslashit( $stage ), untrailingslashit( $root ) );

		$this->end_maintenance();

		$this->state['data']['core_swapped'] = $swapped;

		Revertix_Log::info(
			'Replaced WordPress with the copy from the package.',
			array(
				'files'           => $swapped,
				'package_wp'      => ! empty( $this->state['data']['wp_mismatch'] ) ? $this->state['data']['wp_mismatch'] : get_bloginfo( 'version' ),
				'dir_perms'       => decoct( Revertix_Filesystem::dir_perms() ),
				'old_wp_includes' => $perms_before['wp-includes'],
				'old_wp_admin'    => $perms_before['wp-admin'],
				'new_wp_includes' => self::perms_of( $root . 'wp-includes' ),
				'new_wp_admin'    => self::perms_of( $root . 'wp-admin' ),
			)
		);

		$this->state['message'] = __( 'Finishing up…', 'revertix' );
		$this->advance_stage();
	}

	/**
	 * Give a staged folder and every folder inside it dir_perms().
	 *
	 * Files are already right: copy_file() set them as it wrote them. This is a
	 * safety net for folders, since rename() carries their permissions over.
	 *
	 * @param string $path Absolute path of the staged folder.
	 */
	protected function fix_stage_dir_perms( $path ) {
		$perms = Revertix_Filesystem::dir_perms();

		@chmod( $path, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions

		try {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::SELF_FIRST
			);
			foreach ( $items as $item ) {
				if ( $item->isDir() && ! $item->isLink() ) {
					@chmod( $item->getPathname(), $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				}
			}
		} catch ( Exception $e ) {
			Revertix_Log::warning(
				'Could not check folder permissions in the new WordPress.',
				array(
					'path'  => $path,
					'error' => $e->getMessage(),
				)
			);
		}
	}

	/**
	 * Octal permissions of a path, for the log.
	 *
	 * @param string $path Absolute path.
	 * @return string
	 */
	protected static function perms_of( $path ) {
		$perms = @fileperms( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return ( false === $perms ) ? 'n/a' : decoct( $perms & 0777 );
	}

	/* ---------------------------------------------------------------------
	 * Stage 7: finalize
	 * ------------------------------------------------------------------- */

	/**
	 * Clean up scratch files and flush caches.
	 */
	protected function step_finalize() {
		// Remove the extracted copy (guarded to this job's tmp dir).
		Revertix_Filesystem::delete_tree(
			rtrim( $this->state['data']['extract_dir'], '/' ),
			rtrim( $this->dir(), '/' )
		);

		// A package from a site with a different table prefix carries role and
		// capability keys named after ITS prefix. Rename them before anything else
		// happens: until they match this site's prefix every user, including the
		// administrator, has no capabilities at all and the admin answers "you are
		// not allowed to access this page".
		global $wpdb;
		Revertix_Database::rekey_for_prefix(
			$wpdb->options,
			$wpdb->usermeta,
			$this->state['data']['old_prefix'],
			$this->state['data']['new_prefix']
		);

		// Plugins back on, and WordPress out of maintenance if anything left it
		// there. Both are safe to call when they were never needed.
		$this->end_guard();
		$this->end_maintenance();
		$this->forget_update_state();

		wp_cache_flush();

		Revertix_Log::info(
			'Import finished.',
			array(
				'files_restored' => (int) $this->state['data']['restored'],
				'files_failed'   => (int) $this->state['data']['restore_failed'],
				'rows_rewritten' => (int) $this->state['data']['sr_changed'],
			)
		);

		$this->complete( __( 'Import complete.', 'revertix' ) );
	}

	/**
	 * Final summary for the UI.
	 *
	 * @return array
	 */
	protected function result() {
		if ( self::STATUS_COMPLETE !== $this->state['status'] ) {
			return array();
		}

		$result = array(
			'restored'   => (int) $this->state['data']['restored'],
			'sr_changed' => (int) $this->state['data']['sr_changed'],
		);

		if ( ! empty( $this->state['data']['wp_mismatch'] ) ) {
			$result['notice'] = sprintf(
				/* translators: %s: WordPress version the package was made on. */
				__( 'This site now runs WordPress %s, the version the package was made on.', 'revertix' ),
				$this->state['data']['wp_mismatch']
			);
		}

		return $result;
	}
}
