<?php
/**
 * Export job: package the whole site (DB + files) into a single zip.
 *
 * Stages (each resumable):
 *   1. index_files  - resumable walk of the web root → files-index.txt
 *   2. dump_db      - row-chunked SQL dump of all site tables → database.sql
 *   3. manifest     - write manifest.json describing the package
 *   4. archive      - incrementally add metadata + every file into the zip
 *   5. finalize     - move the finished zip into the protected packages folder
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Builds a downloadable Revertix package.
 */
class Revertix_Export_Job extends Revertix_Job {

	/**
	 * {@inheritDoc}
	 */
	public static function type_slug() {
		return 'export';
	}

	/**
	 * {@inheritDoc}
	 */
	public function stages() {
		return array( 'index_files', 'dump_db', 'manifest', 'archive', 'finalize' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step_labels() {
		return array(
			'index_files' => __( 'Scanning files', 'revertix' ),
			'dump_db'     => __( 'Exporting database', 'revertix' ),
			'manifest'    => __( 'Writing manifest', 'revertix' ),
			'archive'     => __( 'Building archive', 'revertix' ),
			'finalize'    => __( 'Finishing up', 'revertix' ),
		);
	}

	/**
	 * Files added to the zip per chunk. Filterable.
	 *
	 * @return int
	 */
	protected function files_per_chunk() {
		return (int) apply_filters( 'revertix_export_files_per_chunk', 200 );
	}

	/**
	 * Bytes archived per batch, so one batch stays bounded no matter how large
	 * the individual files are.
	 *
	 * @return int
	 */
	protected function bytes_per_chunk() {
		return (int) apply_filters( 'revertix_export_bytes_per_chunk', 32 * 1024 * 1024 );
	}

	/**
	 * Prepare initial data when the job is created.
	 *
	 * Reads the (already sanitized) options from state['args']:
	 *   - include_db    bool
	 *   - tables        string[]  (subset of site tables; empty = all)
	 *   - include_files bool
	 *   - file_areas    string[]  (area keys; empty = all)
	 */
	protected function init() {
		// This job builds both migration packages and backups; the areas on offer
		// differ between the two, so the fallback has to follow the destination.
		$context = ( isset( $this->state['args']['store'] ) && 'backup' === $this->state['args']['store'] ) ? 'backup' : 'export';

		$args = wp_parse_args(
			$this->state['args'],
			array(
				'include_db'    => true,
				'tables'        => array(),
				'include_files' => true,
				'file_areas'    => array_keys( Revertix_Filesystem::file_areas( $context ) ),
				'exclude_names' => array(),
				'plugins_mode'  => 'all',
				'plugins'       => array(),
				'themes_mode'   => 'all',
				'themes'        => array(),
				// Where the finished zip is stored: 'package' (default) or 'backup'.
				'store'         => 'package',
				'label'         => '',
				'safety'        => false,
			)
		);

		// Resolve the database table set.
		$tables = array();
		if ( $args['include_db'] ) {
			$site_tables = Revertix_Database::site_tables();
			if ( ! empty( $args['tables'] ) ) {
				$tables = array_values( array_intersect( $site_tables, $args['tables'] ) );
			} else {
				$tables = $site_tables;
			}
		}

		// Resolve the file scan roots + carve-out exclusions.
		$roots    = array();
		$excludes = array();
		if ( $args['include_files'] ) {
			$resolved = Revertix_Filesystem::resolve_areas( $args['file_areas'] );
			$roots    = $resolved['roots'];
			$excludes = $resolved['excludes'];

			// Per-item selection: when an area is included but only some of its
			// plugins/themes are chosen, exclude the rest.
			if ( 'selected' === $args['plugins_mode'] && in_array( 'plugins', (array) $args['file_areas'], true ) ) {
				$excludes = array_merge( $excludes, Revertix_Filesystem::area_item_excludes( 'plugins', (array) $args['plugins'] ) );
			}
			if ( 'selected' === $args['themes_mode'] && in_array( 'themes', (array) $args['file_areas'], true ) ) {
				$excludes = array_merge( $excludes, Revertix_Filesystem::area_item_excludes( 'themes', (array) $args['themes'] ) );
			}
			// Never package an existing staging site into a live export.
			$excludes = array_merge( $excludes, Revertix_Staging::all_roots() );

			$excludes = array_values( array_unique( $excludes ) );
		}

		// The protective defaults (.git, node_modules, …) are ALWAYS enforced;
		// any client-supplied names can only add to them, never remove them. This
		// guarantees a removed default can't reintroduce read-only restore errors.
		$exclude_names = array_values(
			array_unique(
				array_merge( Revertix_Filesystem::excluded_names(), (array) $args['exclude_names'] )
			)
		);

		$this->state['data'] = array(
			// options
			'include_db'     => (bool) $args['include_db'],
			'include_files'  => (bool) $args['include_files'],
			'file_areas'     => array_values( (array) $args['file_areas'] ),
			'extra_excludes' => $excludes,
			'exclude_names'  => $exclude_names,
			// index_files
			'scan_stack'     => $roots,
			'files_total'    => 0,
			'bytes_total'    => 0,
			// dump_db
			'tables'         => $tables,
			'table_idx'      => 0,
			'row_offset'     => 0,
			'sql_repaired'   => 0,
			'db_header'      => false,
			// archive
			'meta_added'     => false,
			'index_bytes'    => 0,
			'files_added'    => 0,
			// finalize / storage
			'store'          => ( 'backup' === $args['store'] ) ? 'backup' : 'package',
			'label'          => (string) $args['label'],
			'safety'         => (bool) $args['safety'],
			'package_path'   => '',
			'package_name'   => '',
			'package_size'   => 0,
		);

		Revertix_Log::info(
			'Export configured.',
			array(
				'include_db'    => $this->state['data']['include_db'] ? 'yes' : 'no',
				'include_files' => $this->state['data']['include_files'] ? 'yes' : 'no',
				'tables'        => count( $tables ),
				'file_areas'    => implode( ',', $this->state['data']['file_areas'] ),
				'plugins'       => 'selected' === $args['plugins_mode'] ? implode( ',', (array) $args['plugins'] ) : 'all',
				'themes'        => 'selected' === $args['themes_mode'] ? implode( ',', (array) $args['themes'] ) : 'all',
				'exclude_names' => implode( ',', $exclude_names ),
				'extra_excludes' => count( $excludes ),
			)
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step() {
		switch ( $this->state['stage'] ) {
			case 'index_files':
				$this->step_index_files();
				break;
			case 'dump_db':
				$this->step_dump_db();
				break;
			case 'manifest':
				$this->step_manifest();
				break;
			case 'archive':
				$this->step_archive();
				break;
			case 'finalize':
				$this->step_finalize();
				break;
			default:
				$this->complete();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 1: resumable file index
	 * ------------------------------------------------------------------- */

	/**
	 * Walk the tree in chunks, appending discovered files to the index file.
	 */
	protected function step_index_files() {
		// Files excluded (or no roots to scan) → nothing to index.
		if ( empty( $this->state['data']['include_files'] ) || empty( $this->state['data']['scan_stack'] ) ) {
			$this->advance_stage();
			return;
		}

		$index_path = $this->dir() . 'files-index.txt';
		$fh         = fopen( $index_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $fh ) {
			$this->fail( __( 'Could not write the file index.', 'revertix' ) );
			return;
		}

		$result = Revertix_Filesystem::scan_step(
			$this->state['data']['scan_stack'],
			$fh,
			function () {
				return $this->time_left();
			},
			$this->state['data']['extra_excludes'],
			isset( $this->state['data']['exclude_names'] ) ? $this->state['data']['exclude_names'] : null
		);

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->state['data']['scan_stack']   = $result['stack'];
		$this->state['data']['files_total'] += $result['files'];
		$this->state['data']['bytes_total'] += $result['bytes'];

		$this->state['message'] = sprintf(
			/* translators: %s: number of files. */
			__( 'Scanning files… %s found', 'revertix' ),
			revertix_number( $this->state['data']['files_total'] )
		);

		if ( $result['done'] ) {
			if ( ! $this->check_free_space() ) {
				return;
			}
			$this->advance_stage();
		}
	}

	/**
	 * Refuse to start archiving when the disk cannot hold the package.
	 *
	 * Without this the archive simply stops being written part-way through: the
	 * job keeps reporting progress and finishes "successfully" with a truncated
	 * package. Failing up front, with the numbers, is far kinder.
	 *
	 * @return bool True when there is room (or we cannot tell).
	 */
	protected function check_free_space() {
		if ( ! function_exists( 'disk_free_space' ) ) {
			return true;
		}

		$needed = (int) $this->state['data']['bytes_total'];
		if ( $needed <= 0 ) {
			return true;
		}

		$free = @disk_free_space( Revertix_Storage::base_dir() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $free ) {
			return true;
		}

		// Ask for the payload plus 10% headroom for the database dump, the zip
		// bookkeeping and the move out of the scratch directory at the end.
		$required = (int) ( $needed * 1.1 );
		if ( $free >= $required ) {
			return true;
		}

		Revertix_Log::error(
			'Export stopped: not enough free disk space.',
			array(
				'job'      => $this->id,
				'required' => $required,
				'free'     => (int) $free,
			)
		);

		$this->fail(
			sprintf(
				/* translators: 1: required space, 2: available space. */
				__( 'Not enough free disk space to build the package: about %1$s is needed and only %2$s is free. Free up some space, or export fewer areas at a time.', 'revertix' ),
				revertix_size( $required ),
				revertix_size( (int) $free )
			)
		);

		return false;
	}

	/* ---------------------------------------------------------------------
	 * Stage 2: database dump
	 * ------------------------------------------------------------------- */

	/**
	 * Dump tables in row-sized chunks into database.sql.
	 */
	protected function step_dump_db() {
		// Database excluded (or no tables) → skip this stage entirely.
		if ( empty( $this->state['data']['include_db'] ) || empty( $this->state['data']['tables'] ) ) {
			$this->advance_stage();
			return;
		}

		$sql_path = $this->dir() . 'database.sql';
		$fh       = fopen( $sql_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		if ( false === $fh ) {
			$this->fail( __( 'Could not write the database dump.', 'revertix' ) );
			return;
		}

		if ( ! $this->state['data']['db_header'] ) {
			fwrite( $fh, Revertix_Database::dump_header() );
			$this->state['data']['db_header'] = true;
		}

		$tables = $this->state['data']['tables'];
		$total  = count( $tables );

		// Work until time runs low or the dump is finished.
		while ( $this->time_left() > 2.0 ) {
			$idx = $this->state['data']['table_idx'];

			if ( $idx >= $total ) {
				fwrite( $fh, Revertix_Database::dump_footer() );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->advance_stage();
				return;
			}

			$table = $tables[ $idx ];

			// Write the CREATE statement once, at the start of each table.
			if ( 0 === $this->state['data']['row_offset'] ) {
				fwrite( $fh, Revertix_Database::create_statement( $table ) );
			}

			$chunk = Revertix_Database::dump_rows(
				$table,
				$this->state['data']['row_offset'],
				Revertix_Database::rows_per_chunk()
			);

			if ( ! empty( $chunk['repaired'] ) ) {
				$this->state['data']['sql_repaired'] += (int) $chunk['repaired'];
			}

			if ( '' !== $chunk['sql'] ) {
				fwrite( $fh, $chunk['sql'] );
			}

			if ( $chunk['rows'] < Revertix_Database::rows_per_chunk() ) {
				// Table finished - move to the next one.
				$this->state['data']['table_idx']  = $idx + 1;
				$this->state['data']['row_offset'] = 0;
			} else {
				$this->state['data']['row_offset'] += $chunk['rows'];
			}

			$this->set_progress( $this->state['data']['table_idx'], $total );
			$this->state['message'] = sprintf(
				/* translators: 1: current table number, 2: total tables. */
				__( 'Exporting database… table %1$d of %2$d', 'revertix' ),
				min( $this->state['data']['table_idx'] + 1, $total ),
				$total
			);
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------------
	 * Stage 3: manifest
	 * ------------------------------------------------------------------- */

	/**
	 * Write manifest.json describing the package.
	 */
	protected function step_manifest() {
		$manifest = Revertix_Manifest::build( $this->state['data']['tables'] );
		file_put_contents( $this->dir() . 'manifest.json', Revertix_Manifest::to_json( $manifest ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->write_reference_wp_config();

		$this->state['message'] = __( 'Building package…', 'revertix' );
		$this->advance_stage();
	}

	/**
	 * Write a safe copy of wp-config.php for the package to carry.
	 *
	 * A package without a wp-config.php does not look like a WordPress install:
	 * tools that set a site up from an archive (Local, most hosting importers)
	 * find the site root by looking for that file, and a person unpacking it by
	 * hand loses every custom constant they had - WP_MEMORY_LIMIT, WP_DEBUG, a
	 * custom content directory, constants a plugin needs to work.
	 *
	 * The real file cannot travel as-is: its database credentials belong to the
	 * environment it came from, and shipping them inside a downloadable archive
	 * is a leak. So the file is copied with the credentials replaced by
	 * placeholders and fresh salts generated - the target environment fills in
	 * its own values (Local does it automatically). Everything else, including
	 * the table prefix that database.sql depends on, is preserved.
	 *
	 * Revertix's own import never restores it (is_restore_excluded), so this
	 * only ever serves the person handling the package.
	 */
	protected function write_reference_wp_config() {
		if ( empty( $this->state['data']['include_files'] ) ) {
			return;
		}

		// Only when the package actually carries the WordPress root. In a
		// wp-content-only package a wp-config.php would be a loose file nobody
		// asked for, sitting next to a tree it does not belong to.
		if ( ! in_array( 'root', (array) $this->state['data']['file_areas'], true ) ) {
			return;
		}

		$source = '';
		foreach ( array( ABSPATH . 'wp-config.php', dirname( ABSPATH ) . '/wp-config.php' ) as $candidate ) {
			if ( is_file( $candidate ) && is_readable( $candidate ) ) {
				$source = $candidate;
				break;
			}
		}

		if ( '' === $source ) {
			return;
		}

		$config = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$config = Revertix_Filesystem::sanitize_wp_config( $config );

		// Belt and braces: prove the secrets are gone before letting the file
		// into a package that gets downloaded and passed around. If anything is
		// still there, ship no reference config rather than a leaking one.
		$leaked = $this->config_still_leaks( $config );
		if ( '' !== $leaked ) {
			Revertix_Log::warning(
				'Left the reference wp-config.php out of the package: a value could not be removed.',
				array(
					'job'      => $this->id,
					'constant' => $leaked,
				)
			);
			return;
		}

		file_put_contents( $this->dir() . 'wp-config.php', $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Whether a sanitised wp-config still contains one of this site's secrets.
	 *
	 * Compares against the values the running site actually uses, so it catches
	 * any declaration style the rewriter failed to match.
	 *
	 * @param string $config Sanitised contents.
	 * @return string Name of the first constant still present, or ''.
	 */
	protected function config_still_leaks( $config ) {
		$secrets = array( 'DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST' );

		foreach ( array( 'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY', 'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT', 'WP_CACHE_KEY_SALT' ) as $key ) {
			$secrets[] = $key;
		}

		foreach ( $secrets as $const ) {
			if ( ! defined( $const ) ) {
				continue;
			}

			$value = constant( $const );

			// Short or empty values (an empty password, DB_HOST 'localhost')
			// cannot be searched for meaningfully without false positives.
			if ( ! is_string( $value ) || strlen( $value ) < 8 ) {
				continue;
			}

			if ( false !== strpos( $config, $value ) ) {
				return $const;
			}
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Stage 4: archive
	 * ------------------------------------------------------------------- */

	/**
	 * Add metadata files, then every site file, into the zip in batches.
	 */
	protected function step_archive() {
		$zip_path = $this->dir() . 'package.zip';

		// First pass: add the small metadata files at the archive root.
		if ( ! $this->state['data']['meta_added'] ) {
			$meta = array();
			foreach ( array( 'database.sql', 'manifest.json', 'files-index.txt' ) as $name ) {
				$abs = $this->dir() . $name;
				if ( is_file( $abs ) ) {
					$meta[] = array(
						'abs'   => $abs,
						'entry' => $name,
					);
				}
			}

			// The sanitised wp-config goes INSIDE files/, where the real one would
			// have been, so unpacking files/ yields a complete, recognisable
			// WordPress root. Revertix's own import skips it.
			$reference = $this->dir() . 'wp-config.php';
			if ( is_file( $reference ) ) {
				$meta[] = array(
					'abs'   => $reference,
					'entry' => 'files/wp-config.php',
				);
			}

			Revertix_Zip::add_files( $zip_path, $meta );
			$this->state['data']['meta_added'] = true;
		}

		$index_path = $this->dir() . 'files-index.txt';

		// No file index means files were excluded - the archive is metadata-only.
		if ( ! is_file( $index_path ) ) {
			$this->advance_stage();
			return;
		}

		$fh = fopen( $index_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not read the file index.', 'revertix' ) );
			return;
		}

		fseek( $fh, (int) $this->state['data']['index_bytes'] );

		$root          = trailingslashit( Revertix_Filesystem::root() );
		$batch         = array();
		$batch_bytes   = 0;
		$per_chunk     = $this->files_per_chunk();
		$bytes_per_chunk = $this->bytes_per_chunk();
		$files_total   = max( 1, (int) $this->state['data']['files_total'] );

		while ( $this->time_left() > 3.0 ) {
			$line = fgets( $fh );

			if ( false === $line ) {
				// End of index - flush remaining batch and finish the stage.
				if ( ! empty( $batch ) ) {
					Revertix_Zip::add_files( $zip_path, $batch );
					$this->state['data']['files_added'] += count( $batch );
				}
				$this->state['data']['index_bytes'] = ftell( $fh );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->advance_stage();
				return;
			}

			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
				continue;
			}

			$parts = explode( "\t", $line );
			$rel   = $parts[0];

			$batch[]      = array(
				'abs'   => $root . $rel,
				'entry' => 'files/' . $rel,
			);
			$batch_bytes += isset( $parts[1] ) ? (int) $parts[1] : 0;

			// Flush on file count OR bytes. Counting files alone is not enough:
			// deep in an uploads folder 200 entries can be several gigabytes, and
			// since the time budget is only checked between batches, one request
			// would then run for minutes - long enough for the server to cut it
			// off, which leaves the browser waiting on a reply that never comes
			// and the progress bar frozen with no error.
			if ( count( $batch ) >= $per_chunk || $batch_bytes >= $bytes_per_chunk ) {
				Revertix_Zip::add_files( $zip_path, $batch );
				$this->state['data']['files_added'] += count( $batch );
				$this->state['data']['index_bytes']  = ftell( $fh );
				$batch                                = array();
				$batch_bytes                          = 0;

				$this->set_progress( $this->state['data']['files_added'], $files_total );
				$this->state['message'] = sprintf(
					/* translators: 1: files archived, 2: total files. */
					__( 'Archiving files… %1$s of %2$s', 'revertix' ),
					revertix_number( $this->state['data']['files_added'] ),
					revertix_number( $this->state['data']['files_total'] )
				);
			}
		}

		// Time budget hit mid-batch: persist what we have and record position.
		if ( ! empty( $batch ) ) {
			Revertix_Zip::add_files( $zip_path, $batch );
			$this->state['data']['files_added'] += count( $batch );
			$this->state['data']['index_bytes']  = ftell( $fh );
		}

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->set_progress( $this->state['data']['files_added'], $files_total );
	}

	/* ---------------------------------------------------------------------
	 * Stage 5: finalize
	 * ------------------------------------------------------------------- */

	/**
	 * Move the finished zip into the protected packages directory.
	 */
	protected function step_finalize() {
		$zip_path = $this->dir() . 'package.zip';

		if ( ! is_file( $zip_path ) ) {
			$this->fail( __( 'The package could not be created.', 'revertix' ) );
			return;
		}

		$dest_dir = $this->package_dir();
		wp_mkdir_p( $dest_dir );
		Revertix_Storage::harden( Revertix_Storage::sub_dir( $this->store_sub() ) );

		$name = $this->package_filename();
		$dest = $dest_dir . $name;

		if ( ! @rename( $zip_path, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$this->fail( __( 'Could not move the finished package.', 'revertix' ) );
			return;
		}

		$this->state['data']['package_path'] = $dest;
		$this->state['data']['package_name'] = $name;
		$this->state['data']['package_size'] = (int) filesize( $dest );

		$parts = array(
			'database' => (bool) $this->state['data']['include_db'],
			'files'    => (bool) $this->state['data']['include_files'],
			'tables'   => count( $this->state['data']['tables'] ),
			'areas'    => $this->state['data']['include_files'] ? $this->state['data']['file_areas'] : array(),
		);

		// Record in the right registry so the list survives page reloads.
		if ( 'backups' === $this->store_sub() ) {
			$is_safety = (bool) $this->state['data']['safety'];

			$entry = array(
				'id'      => $this->id,
				'name'    => '' !== $this->state['data']['label'] ? $this->state['data']['label'] : Revertix_Storage::untokenize( $name ),
				'file'    => $name,
				'path'    => $dest,
				'size'    => (int) filesize( $dest ),
				'created' => time(),
				'safety'  => $is_safety,
				'parts'   => $parts,
			);
			Revertix_Backup::add( $entry );

			/**
			 * Fires once a backup (manual, scheduled or safety) is stored and
			 * registered. Revertix PRO uses it to copy the backup to remote
			 * storage.
			 *
			 * @param array $entry Backup registry entry.
			 */
			do_action( 'revertix_backup_created', $entry );

			// Enforce the safety-backup retention limit once the new one is recorded.
			if ( $is_safety ) {
				Revertix_Backup::prune_safety();
			}

			// Routine "backup finished" alert (opt-in, Pro). Safety snapshots are
			// part of other operations, so they don't trigger a status email.
			if ( ! $is_safety ) {
				Revertix_Notify::send(
					'status',
					__( 'Backup completed', 'revertix' ),
					sprintf(
						/* translators: 1: backup name, 2: human-readable size. */
						__( 'The backup “%1$s” finished successfully (%2$s).', 'revertix' ),
						$name,
						revertix_size( (int) $this->state['data']['package_size'] )
					)
				);
			}
		} else {
			Revertix_Package_Registry::add(
				array(
					'id'      => $this->id,
					'name'    => Revertix_Storage::untokenize( $name ),
					'path'    => $dest,
					'size'    => (int) filesize( $dest ),
					'created' => time(),
					'parts'   => $parts,
				)
			);
		}

		Revertix_Log::info(
			'Export finished.',
			array(
				'package'    => $name,
				'size_bytes' => (int) $this->state['data']['package_size'],
				'files'      => (int) $this->state['data']['files_added'],
			)
		);

		// Worth surfacing: it means another plugin has been writing broken values
		// into this site's database, and the package quietly corrected them.
		if ( ! empty( $this->state['data']['sql_repaired'] ) ) {
			Revertix_Log::warning(
				'Repaired stray WordPress placeholder markers while dumping the database.',
				array(
					'job'      => $this->id,
					'repaired' => (int) $this->state['data']['sql_repaired'],
				)
			);
		}

		$this->complete( __( 'Export complete.', 'revertix' ) );
	}

	/**
	 * Destination directory for finished packages (token subfolder).
	 *
	 * @return string
	 */
	protected function package_dir() {
		return Revertix_Storage::sub_dir( $this->store_sub() ) . $this->id . '/';
	}

	/**
	 * Alert (opt-in, Pro) when a backup job fails, on top of the base logging.
	 *
	 * @param string $message Error message.
	 */
	protected function fail( $message ) {
		parent::fail( $message );

		if ( 'backups' === $this->store_sub() ) {
			Revertix_Notify::send(
				'error',
				__( 'Backup failed', 'revertix' ),
				sprintf(
					/* translators: %s: error detail. */
					__( 'A Revertix backup failed: %s', 'revertix' ),
					$message
				)
			);
		}
	}

	/**
	 * The storage subdirectory for this job's output ('packages' or 'backups').
	 *
	 * @return string
	 */
	protected function store_sub() {
		return ( isset( $this->state['data']['store'] ) && 'backup' === $this->state['data']['store'] ) ? 'backups' : 'packages';
	}

	/**
	 * Build a human-friendly package filename.
	 *
	 * @return string
	 */
	protected function package_filename() {
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		$host = $host ? preg_replace( '/[^a-z0-9\-]/i', '-', $host ) : 'site';

		// Lead with the site's own host. Tools that build a site straight from an
		// archive (Local, most hosting importers) name it after the file, so a
		// "revertix-" prefix in front would end up as part of the site's name.
		// The token keeps the file unguessable on servers that ignore .htaccess;
		// the download endpoint strips it again so the person gets a clean name.
		return Revertix_Storage::tokenize( $host . '-' . gmdate( 'Ymd-His' ) . '.zip' );
	}

	/**
	 * Expose the download link + size once complete.
	 *
	 * @return array
	 */
	protected function result() {
		if ( self::STATUS_COMPLETE !== $this->state['status'] ) {
			return array();
		}

		// Build the URL with add_query_arg (raw "&"), NOT wp_nonce_url - the
		// latter HTML-escapes ampersands to "&amp;", which break the query
		// string when the URL is assigned to an element's href in JavaScript.
		$download_url = add_query_arg(
			array(
				'action'   => 'revertix_download',
				'job'      => $this->id,
				'_wpnonce' => wp_create_nonce( 'revertix_download' ),
			),
			admin_url( 'admin-post.php' )
		);

		$parts = array();
		if ( $this->state['data']['include_db'] ) {
			$parts[] = __( 'Database', 'revertix' );
		}
		if ( $this->state['data']['include_files'] ) {
			$parts[] = __( 'Files', 'revertix' );
		}

		return array(
			'id'           => $this->id,
			'download_url' => esc_url_raw( $download_url ),
			'filename'     => Revertix_Storage::untokenize( $this->state['data']['package_name'] ),
			'size'         => revertix_size( $this->state['data']['package_size'] ),
			'created'      => __( 'Just now', 'revertix' ),
			'parts_label'  => implode( ' + ', $parts ),
		);
	}

	/**
	 * Absolute path of the finished package (used by the download handler).
	 *
	 * @return string
	 */
	public function package_path() {
		return (string) $this->state['data']['package_path'];
	}
}
