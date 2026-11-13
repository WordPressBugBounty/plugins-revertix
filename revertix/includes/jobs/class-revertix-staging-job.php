<?php
/**
 * Staging job: clone the live site 1:1 into a browsable web-root subdirectory.
 *
 * Stages (each resumable):
 *   1. index_files    - walk the live root → files-index.txt (skips other staging
 *                       dirs, Revertix storage, .git/node_modules, etc.)
 *   2. copy_files     - copy each indexed file into the staging dir (wp-config.php
 *                       is skipped; a tailored one is written in stage 4)
 *   3. clone_db       - clone every site table into <prefix>stg<slug>_*, chunked
 *   4. write_config   - write the staging wp-config.php with the staging prefix
 *   5. search_replace - rewrite live URLs/paths → staging URLs/paths in the clone
 *   6. finalize       - set siteurl/home + noindex, mark the entry ready, clean up
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB -- direct, uncached queries are inherent to a backup/migration/staging engine. Table/column identifiers are bound with the %i placeholder and all values with %s/%d via $wpdb->prepare().

/**
 * Creates a staging environment from the live site.
 */
class Revertix_Staging_Job extends Revertix_Job {

	/**
	 * {@inheritDoc}
	 */
	public static function type_slug() {
		return 'staging';
	}

	/**
	 * {@inheritDoc}
	 */
	public function stages() {
		return array( 'index_files', 'copy_files', 'clone_db', 'write_config', 'search_replace', 'finalize' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function step_labels() {
		return array(
			'index_files'    => __( 'Scanning files', 'revertix' ),
			'copy_files'     => __( 'Copying files', 'revertix' ),
			'clone_db'       => __( 'Cloning database', 'revertix' ),
			'write_config'   => __( 'Configuring', 'revertix' ),
			'search_replace' => __( 'Updating URLs', 'revertix' ),
			'finalize'       => __( 'Finishing up', 'revertix' ),
		);
	}

	/**
	 * Files copied per chunk.
	 *
	 * @return int
	 */
	protected function copy_per_chunk() {
		return (int) apply_filters( 'revertix_staging_copy_per_chunk', 200 );
	}

	/**
	 * Rows cloned per chunk.
	 *
	 * @return int
	 */
	protected function clone_rows_per_chunk() {
		return (int) apply_filters( 'revertix_staging_clone_rows_per_chunk', 2000 );
	}

	/**
	 * Rows scanned per search-replace chunk.
	 *
	 * @return int
	 */
	protected function sr_rows_per_chunk() {
		return (int) apply_filters( 'revertix_staging_sr_rows_per_chunk', 150 );
	}

	/**
	 * Prepare state from args: state['args']['slug'] and ['name'].
	 */
	protected function init() {
		global $wpdb;

		$slug = isset( $this->state['args']['slug'] ) ? $this->state['args']['slug'] : '';
		$name = isset( $this->state['args']['name'] ) ? $this->state['args']['name'] : '';

		$this->state['data'] = array(
			'slug'          => $slug,
			'name'          => $name,
			'created_by'    => isset( $this->state['args']['created_by'] ) ? $this->state['args']['created_by'] : '',
			'db_name'       => defined( 'DB_NAME' ) ? DB_NAME : '',
			'staging_dir'   => Revertix_Staging::dir_for( $slug ),
			'staging_url'   => Revertix_Staging::url_for( $slug ),
			'live_prefix'   => $wpdb->prefix,
			'stg_prefix'    => Revertix_Staging::prefix_for( $slug ),
			// index_files
			'scan_stack'    => array( Revertix_Filesystem::normalize( Revertix_Filesystem::root() ) ),
			'files_total'   => 0,
			// copy_files
			'copy_bytes'    => 0,
			'copied'        => 0,
			'copy_failed'   => 0,
			// clone_db
			'tables'        => array(),
			'tbl_idx'       => 0,
			'row_offset'    => 0,
			'schema_done'   => false,
			// search_replace
			'search'        => array(),
			'replace'       => array(),
			'sr_tables'     => array(),
			'sr_table_idx'  => 0,
			'sr_row_offset' => 0,
			'sr_pk'         => array(),
			'sr_changed'    => 0,
		);

		// Register immediately (status "building") so a cancelled or interrupted
		// staging is always tracked and removable - never an untracked orphan.
		Revertix_Staging::save( $this->registry_entry( 'building' ) );

		Revertix_Log::info(
			'Staging started.',
			array(
				'slug'   => $slug,
				'name'   => $name,
				'dir'    => $this->state['data']['staging_dir'],
				'prefix' => $this->state['data']['stg_prefix'],
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
			case 'copy_files':
				$this->step_copy_files();
				break;
			case 'clone_db':
				$this->step_clone_db();
				break;
			case 'write_config':
				$this->step_write_config();
				break;
			case 'search_replace':
				$this->step_search_replace();
				break;
			case 'finalize':
				$this->step_finalize();
				break;
			default:
				$this->complete();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 1: index files
	 * ------------------------------------------------------------------- */

	/**
	 * Walk the live root in chunks, listing files to copy.
	 */
	protected function step_index_files() {
		Revertix_Filesystem::mkdir_p( $this->state['data']['staging_dir'] );

		$index_path = $this->dir() . 'files-index.txt';
		$fh         = fopen( $index_path, 'a' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not write the file index.', 'revertix' ) );
			return;
		}

		// Never recurse into this staging dir (being built) or any other staging
		// site - they are not part of the live site being cloned.
		$extra = array_merge(
			array( Revertix_Filesystem::normalize( $this->state['data']['staging_dir'] ) ),
			Revertix_Staging::all_roots()
		);

		$result = Revertix_Filesystem::scan_step(
			$this->state['data']['scan_stack'],
			$fh,
			function () {
				return $this->time_left();
			},
			$extra
		);

		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		$this->state['data']['scan_stack']   = $result['stack'];
		$this->state['data']['files_total'] += $result['files'];

		$this->state['message'] = sprintf(
			/* translators: %s: number of files. */
			__( 'Scanning files… %s found', 'revertix' ),
			revertix_number( $this->state['data']['files_total'] )
		);

		if ( $result['done'] ) {
			$this->advance_stage();
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 2: copy files
	 * ------------------------------------------------------------------- */

	/**
	 * Copy indexed files into the staging directory (skipping wp-config.php).
	 */
	protected function step_copy_files() {
		$index_path = $this->dir() . 'files-index.txt';
		$site_root  = trailingslashit( Revertix_Filesystem::root() );
		$stg_root   = trailingslashit( $this->state['data']['staging_dir'] );

		$fh = fopen( $index_path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $fh ) {
			$this->fail( __( 'Could not read the file list.', 'revertix' ) );
			return;
		}

		fseek( $fh, (int) $this->state['data']['copy_bytes'] );

		$index_size = max( 1, (int) filesize( $index_path ) );
		$processed  = 0;
		$per_chunk  = $this->copy_per_chunk();

		while ( $this->time_left() > 3.0 ) {
			$line = fgets( $fh );

			if ( false === $line ) {
				$this->state['data']['copy_bytes'] = ftell( $fh );
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				$this->advance_stage();
				return;
			}

			$rel = explode( "\t", rtrim( $line, "\r\n" ) )[0];
			if ( '' === $rel || Revertix_Filesystem::is_protected_relative( $rel ) ) {
				continue; // Our own wp-config.php is written later.
			}

			$source = $site_root . $rel;
			if ( is_file( $source ) ) {
				if ( Revertix_Filesystem::copy_file( $source, $stg_root . $rel ) ) {
					++$this->state['data']['copied'];
				} else {
					++$this->state['data']['copy_failed'];
					if ( $this->state['data']['copy_failed'] <= 20 ) {
						$last = error_get_last();
						Revertix_Log::warning(
							'Could not copy a file into staging.',
							array(
								'file'   => $rel,
								'reason' => ( $last && ! empty( $last['message'] ) ) ? $last['message'] : 'unknown error',
							)
						);
					}
				}
			}

			++$processed;
			if ( $processed >= $per_chunk ) {
				$this->state['data']['copy_bytes'] = ftell( $fh );
				$this->set_progress( $this->state['data']['copy_bytes'], $index_size );
				$this->state['message']            = sprintf(
					/* translators: %s: number of files copied. */
					__( 'Copying files… %s copied', 'revertix' ),
					revertix_number( $this->state['data']['copied'] )
				);
				$processed = 0;
			}
		}

		$this->state['data']['copy_bytes'] = ftell( $fh );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/* ---------------------------------------------------------------------
	 * Stage 3: clone database tables
	 * ------------------------------------------------------------------- */

	/**
	 * Clone each site table into the staging prefix, chunked by rows.
	 */
	protected function step_clone_db() {
		if ( empty( $this->state['data']['tables'] ) ) {
			$this->state['data']['tables'] = Revertix_Database::site_tables();
			if ( empty( $this->state['data']['tables'] ) ) {
				$this->advance_stage();
				return;
			}
		}

		$tables = $this->state['data']['tables'];
		$total  = count( $tables );

		while ( $this->time_left() > 3.0 ) {
			$idx = $this->state['data']['tbl_idx'];
			if ( $idx >= $total ) {
				$this->advance_stage();
				return;
			}

			$source = $tables[ $idx ];
			$target = $this->staging_table( $source );

			// Create the empty clone once, at the start of this table.
			if ( ! $this->state['data']['schema_done'] ) {
				if ( ! Revertix_Database::clone_schema( $source, $target ) ) {
					$this->fail(
						sprintf(
							/* translators: %s: table name. */
							__( 'Could not create the staging copy of table %s.', 'revertix' ),
							$source
						)
					);
					return;
				}
				$this->state['data']['schema_done'] = true;
			}

			$copied = Revertix_Database::copy_rows_into(
				$source,
				$target,
				$this->state['data']['row_offset'],
				$this->clone_rows_per_chunk()
			);

			$this->state['data']['row_offset'] += $copied;

			if ( $copied < $this->clone_rows_per_chunk() ) {
				// Table finished - move to the next one.
				$this->state['data']['tbl_idx']     = $idx + 1;
				$this->state['data']['row_offset']  = 0;
				$this->state['data']['schema_done'] = false;
			}

			$this->set_progress( $this->state['data']['tbl_idx'], $total );
			$this->state['message'] = sprintf(
				/* translators: 1: current table number, 2: total tables. */
				__( 'Cloning database… table %1$d of %2$d', 'revertix' ),
				min( $this->state['data']['tbl_idx'] + 1, $total ),
				$total
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 4: write the staging wp-config.php
	 * ------------------------------------------------------------------- */

	/**
	 * Write a wp-config.php into the staging dir with the staging table prefix.
	 */
	protected function step_write_config() {
		$source = $this->live_wp_config();
		if ( '' === $source ) {
			$this->fail( __( 'Could not locate the site’s wp-config.php to clone.', 'revertix' ) );
			return;
		}

		$config = (string) file_get_contents( $source ); // phpcs:ignore WordPress.WP.AlternativeFunctions

		// Swap the table prefix. Both installs share one database, so this is the
		// only thing separating them - the result is verified before any write.
		$prefix = (string) $this->state['data']['stg_prefix'];
		$config = $this->set_table_prefix( $config, $prefix, $how );

		// Neutralise hard-coded URL/path constants so the staging DB options win.
		foreach ( array( 'WP_HOME', 'WP_SITEURL', 'WP_CONTENT_URL' ) as $const ) {
			$config = $this->safe_preg_replace(
				'/^[ \t]*define\(\s*([\'"])' . $const . '\1.*$/m',
				'// [revertix] removed ' . $const . ' for staging',
				$config
			);
		}

		// Give staging its OWN auth keys/salts so live login sessions never carry
		// over: opening staging then always shows its login screen first, and the
		// two sites' cookies/nonces can't validate against each other.
		$config = $this->randomize_salts( $config );

		// Never write a config that does not declare the staging prefix: that
		// would hand the user a second front end wired into the live tables.
		if ( ! $this->config_declares_prefix( $config, $prefix ) ) {
			$this->fail(
				sprintf(
					/* translators: %s: staging table prefix. */
					__( 'Could not set the staging table prefix (%s) in wp-config.php, so the staging site would have used the live database tables. Nothing was written. This usually means wp-config.php sets $table_prefix in a way Revertix does not recognise.', 'revertix' ),
					$prefix
				)
			);
			return;
		}

		$dest    = trailingslashit( $this->state['data']['staging_dir'] ) . 'wp-config.php';
		$written = file_put_contents( $dest, $config ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $written || $written < 1 ) {
			$this->fail( __( 'Could not write the staging wp-config.php.', 'revertix' ) );
			return;
		}

		Revertix_Log::info(
			'Staging wp-config.php written.',
			array(
				'job'    => $this->id,
				'slug'   => $this->state['data']['slug'],
				'prefix' => $prefix,
				'method' => $how,
			)
		);

		$this->state['message'] = __( 'Configuring staging…', 'revertix' );
		$this->advance_stage();
	}

	/* ---------------------------------------------------------------------
	 * Stage 5: search & replace (live → staging) in the cloned tables
	 * ------------------------------------------------------------------- */

	/**
	 * Build the replacement map (once) and rewrite the cloned tables.
	 */
	protected function step_search_replace() {
		// Build the map + table list once.
		if ( empty( $this->state['data']['sr_tables'] ) ) {
			$this->build_search_replace();
			if ( empty( $this->state['data']['sr_tables'] ) || empty( $this->state['data']['search'] ) ) {
				$this->advance_stage();
				return;
			}
		}

		global $wpdb;
		$tables  = $this->state['data']['sr_tables'];
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

			if ( 0 === $this->state['data']['sr_row_offset'] || ! isset( $this->state['data']['sr_pk'][ $table ] ) ) {
				$this->state['data']['sr_pk'][ $table ] = Revertix_Database::primary_key( $table );
			}
			$pk = $this->state['data']['sr_pk'][ $table ];

			$rows = Revertix_Database::fetch_rows( $table, $this->state['data']['sr_row_offset'], $this->sr_rows_per_chunk() );

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
				$this->state['data']['sr_table_idx']  = $idx + 1;
				$this->state['data']['sr_row_offset'] = 0;
			} else {
				$this->state['data']['sr_row_offset'] += count( $rows );
			}

			$this->set_progress( $this->state['data']['sr_table_idx'], $total );
			$this->state['message'] = sprintf(
				/* translators: 1: current table number, 2: total tables. */
				__( 'Updating staging URLs and paths… table %1$d of %2$d', 'revertix' ),
				min( $this->state['data']['sr_table_idx'] + 1, $total ),
				$total
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Stage 6: finalize
	 * ------------------------------------------------------------------- */

	/**
	 * Set the staging URL options + noindex, register the site, clean up.
	 */
	protected function step_finalize() {
		global $wpdb;

		$options = $this->state['data']['stg_prefix'] . 'options';
		$url     = rtrim( $this->state['data']['staging_url'], '/' );

		// CRITICAL: the clone uses a new table prefix, so rename the prefix-bound
		// role/capability keys - otherwise every user loses their capabilities and
		// the staging admin loads with no menus.
		Revertix_Database::rekey_for_prefix(
			$options,
			$this->state['data']['stg_prefix'] . 'usermeta',
			$this->state['data']['live_prefix'],
			$this->state['data']['stg_prefix']
		);

		// Point the clone at its own URL and keep it out of search engines.
		$this->set_staging_option( $options, 'siteurl', $url );
		$this->set_staging_option( $options, 'home', $url );
		$this->set_staging_option( $options, 'blog_public', '0' );

		// Permalinks. The clone already carries the live permalink_structure.
		//   - Apache/LiteSpeed: write a subdirectory .htaccess (RewriteBase
		//     /<slug>/) so PRETTY permalinks work - the staging site then has the
		//     same clean URLs as live, like a real separate site.
		//   - nginx/other: we can't add per-directory rewrites, so fall back to
		//     PLAIN permalinks (?p=…) which resolve to /<slug>/index.php directly.
		$this->set_staging_option( $options, 'rewrite_rules', '' ); // Force a rebuild.

		if ( $this->server_uses_htaccess() ) {
			$this->write_staging_htaccess();
		} else {
			$this->set_staging_option( $options, 'permalink_structure', '' );
		}

		// Markers the admin-bar indicator/switcher reads to know it is staging and
		// where the live site is. Set on the clone only (live never has these).
		$this->set_staging_option( $options, 'revertix_is_staging', $this->state['data']['slug'] );
		$this->set_staging_option( $options, 'revertix_staging_name', (string) $this->state['data']['name'] );
		$this->set_staging_option( $options, 'revertix_live_url', trailingslashit( home_url( '/' ) ) );

		// Lock the staging site behind login (guests are sent to the login screen
		// and, once signed in, straight into wp-admin). Like WP Staging, this keeps
		// the copy private instead of being reachable by anyone with the URL.
		$this->install_login_protection();

		Revertix_Staging::save( $this->registry_entry( 'ready' ) );

		// Remove the job scratch (the index file lives there).
		Revertix_Filesystem::delete_tree( rtrim( $this->dir(), '/' ), rtrim( Revertix_Storage::sub_dir( 'tmp' ), '/' ) );

		Revertix_Log::info(
			'Staging finished.',
			array(
				'slug'       => $this->state['data']['slug'],
				'url'        => $this->state['data']['staging_url'],
				'permalinks' => $this->server_uses_htaccess() ? 'pretty (.htaccess)' : 'plain (nginx fallback)',
				'files'      => (int) $this->state['data']['copied'],
				'failed'     => (int) $this->state['data']['copy_failed'],
				'sr_changed' => (int) $this->state['data']['sr_changed'],
			)
		);

		$this->complete( __( 'Staging site is ready.', 'revertix' ) );
	}

	/**
	 * Final result for the UI.
	 *
	 * @return array
	 */
	protected function result() {
		if ( self::STATUS_COMPLETE !== $this->state['status'] ) {
			return array();
		}

		return array(
			'slug' => $this->state['data']['slug'],
			'name' => $this->state['data']['name'],
			'url'  => $this->state['data']['staging_url'],
		);
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Build the registry entry for this staging site at a given status.
	 *
	 * @param string $status 'building' or 'ready'.
	 * @return array
	 */
	protected function registry_entry( $status ) {
		return array(
			'slug'       => $this->state['data']['slug'],
			'name'       => $this->state['data']['name'],
			'path'       => $this->state['data']['staging_dir'],
			'url'        => $this->state['data']['staging_url'],
			'prefix'     => $this->state['data']['stg_prefix'],
			'db_name'    => $this->state['data']['db_name'],
			'created_by' => $this->state['data']['created_by'],
			'created'    => time(),
			'status'     => $status,
		);
	}

	/**
	 * The staging table name for a given live table.
	 *
	 * @param string $live_table Live table name.
	 * @return string
	 */
	protected function staging_table( $live_table ) {
		return preg_replace(
			'/^' . preg_quote( $this->state['data']['live_prefix'], '/' ) . '/',
			$this->state['data']['stg_prefix'],
			$live_table,
			1
		);
	}

	/**
	 * Build the from→to map and the staging table set for search-replace.
	 */
	protected function build_search_replace() {
		$stg_path = Revertix_Filesystem::normalize( $this->state['data']['staging_dir'] );
		$stg_url  = rtrim( $this->state['data']['staging_url'], '/' );

		// Staging lives in a SUBDIRECTORY of the live site, so the staging URL and
		// path CONTAIN the live ones. Map ONLY the base URL and base filesystem
		// path: every uploads/content URL and path sits under them and is rewritten
		// correctly in a single pass. Adding the sub-paths as separate rules would
		// double-replace (…/staging/staging/…) - which is what broke the images.
		// (site_url, when WP is in its own subdir, also starts with home_url, so the
		// base mapping covers it too.)
		$map = array(
			home_url()                                            => $stg_url,
			Revertix_Filesystem::normalize( Revertix_Filesystem::root() ) => $stg_path,
		);

		list( $search, $replace )       = Revertix_Search_Replace::prepare_map( $map );
		$this->state['data']['search']  = $search;
		$this->state['data']['replace'] = $replace;

		$sr = array();
		foreach ( $this->state['data']['tables'] as $live_table ) {
			$sr[] = $this->staging_table( $live_table );
		}
		$this->state['data']['sr_tables'] = $sr;
	}

	/**
	 * WHERE clause for a row: prefer the primary key, else all non-null columns.
	 *
	 * @param array<string,mixed> $row Row data.
	 * @param string[]            $pk  Primary key columns.
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

		return array_filter(
			$row,
			static function ( $value ) {
				return null !== $value;
			}
		);
	}

	/**
	 * Insert/update a single option in the staging options table.
	 *
	 * @param string $table Staging options table.
	 * @param string $name  Option name.
	 * @param string $value Option value.
	 */
	protected function set_staging_option( $table, $name, $value ) {
		global $wpdb;

		$exists = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE option_name = %s', $table, $name ) );

		if ( $exists ) {
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $table, $value, $name ) );
		} else {
			$wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'yes')", $table, $name, $value ) );
		}
	}

	/**
	 * Whether the server honours per-directory .htaccess (Apache / LiteSpeed),
	 * which lets pretty permalinks work inside the staging subdirectory.
	 *
	 * @return bool
	 */
	protected function server_uses_htaccess() {
		return Revertix_Storage::server_uses_htaccess();
	}

	/**
	 * Write a subdirectory .htaccess into the staging dir so WordPress pretty
	 * permalinks resolve to /<slug>/index.php (overwriting the live one copied in).
	 */
	protected function write_staging_htaccess() {
		$path = wp_parse_url( $this->state['data']['staging_url'], PHP_URL_PATH );
		$path = '/' . trim( (string) $path, '/' ) . '/'; // e.g. "/staging/".

		$rules  = "# BEGIN WordPress (Revertix staging)\n";
		$rules .= "<IfModule mod_rewrite.c>\n";
		$rules .= "RewriteEngine On\n";
		$rules .= "RewriteBase {$path}\n";
		$rules .= "RewriteRule ^index\\.php$ - [L]\n";
		$rules .= "RewriteCond %{REQUEST_FILENAME} !-f\n";
		$rules .= "RewriteCond %{REQUEST_FILENAME} !-d\n";
		$rules .= "RewriteRule . {$path}index.php [L]\n";
		$rules .= "</IfModule>\n";
		$rules .= "# END WordPress\n";

		$file = trailingslashit( $this->state['data']['staging_dir'] ) . '.htaccess';
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		file_put_contents( $file, $rules );
	}

	/**
	 * Locate the live wp-config.php (in ABSPATH, or one level up).
	 *
	 * @return string Absolute path, or '' if not found.
	 */
	protected function live_wp_config() {
		$candidates = array(
			ABSPATH . 'wp-config.php',
			dirname( ABSPATH ) . '/wp-config.php',
		);
		foreach ( $candidates as $path ) {
			if ( is_file( $path ) ) {
				return $path;
			}
		}
		return '';
	}

	/**
	 * preg_replace that never turns the subject into null.
	 *
	 * On a PCRE failure preg_replace returns null; assigning that back over the
	 * config would silently produce an empty file, so keep the original instead.
	 *
	 * @param string $pattern     Pattern.
	 * @param string $replacement Replacement.
	 * @param string $subject     Subject.
	 * @param int    $limit       Max replacements (-1 = all).
	 * @return string
	 */
	protected function safe_preg_replace( $pattern, $replacement, $subject, $limit = -1 ) {
		$out = preg_replace( $pattern, $replacement, $subject, $limit );
		return ( null === $out ) ? $subject : (string) $out;
	}

	/**
	 * Matches a whole `$table_prefix = …;` statement whatever the right-hand side
	 * is: a literal, a constant, a concatenation or a getenv()-style call.
	 * Line-anchored, so commented-out assignments are left alone.
	 */
	const TABLE_PREFIX_RE = '/^[ \t]*\$table_prefix\s*=\s*[^;]+;/m';

	/**
	 * Force the staging table prefix into a wp-config.php.
	 *
	 * Rewrites every existing assignment; if the file declares the prefix in a
	 * form we cannot match, appends our own instead - the last assignment before
	 * wp-settings.php is the one PHP uses.
	 *
	 * @param string $config wp-config.php contents.
	 * @param string $prefix Staging table prefix.
	 * @param string $how    Set to 'replace', 'append' or 'none' (out param).
	 * @return string
	 */
	protected function set_table_prefix( $config, $prefix, &$how = null ) {
		$how       = 'none';
		$statement = "\$table_prefix = '" . addcslashes( $prefix, "'\\" ) . "';";

		if ( preg_match( self::TABLE_PREFIX_RE, $config ) ) {
			$replaced = preg_replace_callback(
				self::TABLE_PREFIX_RE,
				static function () use ( $statement ) {
					return $statement;
				},
				$config
			);
			if ( null !== $replaced ) {
				$config = (string) $replaced;
				$how    = 'replace';
			}
		}

		if ( $this->config_declares_prefix( $config, $prefix ) ) {
			return $config;
		}

		// Fall back to appending, as late as possible but before wp-settings.php.
		$line = "\n// [revertix] staging table prefix (Revertix could not rewrite the original assignment).\n" . $statement . "\n";

		$anchors = array(
			'/^[ \t]*\/\*[^\n]*stop editing[^\n]*\*\/[ \t]*$/mi',
			'/^[ \t]*(require|include)(_once)?[^\n;]*wp-settings\.php[^\n;]*;[ \t]*$/mi',
			'/\?>\s*$/',
		);

		foreach ( $anchors as $anchor ) {
			if ( ! preg_match( $anchor, $config ) ) {
				continue;
			}
			$out = preg_replace_callback(
				$anchor,
				static function ( $m ) use ( $line ) {
					return $line . $m[0];
				},
				$config,
				1
			);
			if ( null !== $out ) {
				$config = (string) $out;
				$how    = 'append';
			}
			break;
		}

		if ( ! $this->config_declares_prefix( $config, $prefix ) ) {
			// No anchor matched: last resort, end of file.
			$config = rtrim( $config, "\r\n" ) . "\n" . $line;
			$how    = 'append';
		}

		return $config;
	}

	/**
	 * Whether the config's effective table prefix is the one we want: the last
	 * assignment wins, and it must be a plain quoted literal of our prefix.
	 *
	 * @param string $config wp-config.php contents.
	 * @param string $prefix Expected staging table prefix.
	 * @return bool
	 */
	protected function config_declares_prefix( $config, $prefix ) {
		if ( '' === (string) $prefix ) {
			return false;
		}

		$found = preg_match_all( self::TABLE_PREFIX_RE, (string) $config, $matches );
		if ( ! $found ) {
			return false;
		}

		$last = trim( end( $matches[0] ) );

		return (bool) preg_match(
			'/^\$table_prefix\s*=\s*([\'"])' . preg_quote( $prefix, '/' ) . '\1\s*;$/',
			$last
		);
	}

	/* ---------------------------------------------------------------------
	 * Login protection (keep staging private)
	 * ------------------------------------------------------------------- */

	/**
	 * Give the staging wp-config its OWN eight WordPress secret keys/salts so its
	 * login sessions and nonces are independent of live. We strip any existing
	 * defines and inject a freshly generated block - injecting (rather than only
	 * replacing) matters because some setups (e.g. Local) don't list salts in
	 * wp-config at all and generate them in the database, which the clone would
	 * otherwise inherit. Passwords don't depend on these salts, so users still
	 * sign in to staging with their normal credentials.
	 *
	 * @param string $config wp-config.php contents.
	 * @return string
	 */
	protected function randomize_salts( $config ) {
		$keys = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
		);

		// Drop any existing single-line defines so ours are authoritative.
		foreach ( $keys as $key ) {
			$config = $this->safe_preg_replace(
				'/^[ \t]*define\(\s*([\'"])' . $key . '\1\s*,.*$/m',
				'',
				$config
			);
		}

		// Build a fresh block of staging-only keys.
		$lines = array( '', '// [revertix] staging-only auth keys and salts (independent from live).' );
		foreach ( $keys as $key ) {
			$value   = addcslashes( wp_generate_password( 64, true, true ), "'\\" );
			$lines[] = "define( '" . $key . "', '" . $value . "' );";
		}
		$lines[] = '';
		$block    = implode( "\n", $lines );

		// Insert before $table_prefix (always present and loaded before
		// wp-settings.php, so the constants are defined in time). Use a callback so
		// the random values - which may contain $ or \ - are inserted literally.
		if ( preg_match( '/^[ \t]*\$table_prefix\s*=/m', $config ) ) {
			$out = preg_replace_callback(
				'/^([ \t]*\$table_prefix\s*=)/m',
				static function ( $m ) use ( $block ) {
					return $block . "\n" . $m[1];
				},
				$config,
				1
			);
		} else {
			$out = preg_replace_callback(
				'/^<\?php/',
				static function ( $m ) use ( $block ) {
					return $m[0] . "\n" . $block;
				},
				$config,
				1
			);
		}

		return ( null === $out ) ? $config : (string) $out;
	}

	/**
	 * Drop the must-use plugin that forces login for guests into the staging site.
	 * Best-effort: a failure is logged but does not fail the whole build.
	 */
	protected function install_login_protection() {
		$content_rel = trim(
			str_replace(
				Revertix_Filesystem::normalize( Revertix_Filesystem::root() ),
				'',
				Revertix_Filesystem::normalize( Revertix_Filesystem::content_dir() )
			),
			'/'
		);
		// Fall back to the standard layout if wp-content sits outside the web root.
		if ( '' === $content_rel || false !== strpos( $content_rel, '..' ) || false !== strpos( $content_rel, ':' ) ) {
			$content_rel = 'wp-content';
		}

		$mu_dir = trailingslashit( $this->state['data']['staging_dir'] ) . $content_rel . '/mu-plugins';

		if ( ! is_dir( $mu_dir ) && ! wp_mkdir_p( $mu_dir ) ) {
			Revertix_Log::warning( 'Could not create staging mu-plugins directory for login protection.', array( 'dir' => $mu_dir ) );
			return;
		}

		$dest = trailingslashit( $mu_dir ) . Revertix_Staging::PROTECT_MU;
		if ( false === file_put_contents( $dest, $this->login_protection_source() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			Revertix_Log::warning( 'Could not write staging login-protection mu-plugin.', array( 'file' => $dest ) );
			return;
		}

		Revertix_Log::info( 'Staging login protection installed.', array( 'file' => $dest ) );
	}

	/**
	 * Source of the staging login-protection must-use plugin. Sends guests to the
	 * login screen and, after they authenticate, straight into wp-admin.
	 *
	 * @return string
	 */
	protected function login_protection_source() {
		return <<<'PHP'
<?php
/**
 * Plugin Name: Revertix Staging Protection
 * Description: Locks this staging site behind login. Guests are sent to the login screen and, once signed in, straight into the dashboard. Behaviour is driven by Revertix Settings (Settings -> Staging) on THIS site. Installed and managed by Revertix - do not edit or delete (it is excluded from sync so it never reaches the live site).
 * Author: Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'revertix_staging_settings' ) ) {
	/**
	 * This staging site's Revertix settings (its own cloned option).
	 */
	function revertix_staging_settings() {
		$s = get_option( 'revertix_settings' );
		return is_array( $s ) ? $s : array();
	}
}

if ( ! function_exists( 'revertix_staging_access_denied' ) ) {
	/**
	 * Whether the current logged-in user is denied access to this staging site.
	 *
	 * The free plugin never denies a logged-in user beyond the login wall:
	 * administrators and the "disable auth" toggle always pass, and otherwise
	 * this returns false. The separate Revertix PRO add-on may restrict access
	 * by user/role by answering the `revertix_staging_deny_access` filter (its
	 * code runs on the cloned staging site).
	 */
	function revertix_staging_access_denied( $settings ) {
		if ( ! empty( $settings['staging_disable_auth'] ) || current_user_can( 'manage_options' ) ) {
			return false;
		}
		return (bool) apply_filters( 'revertix_staging_deny_access', false, $settings );
	}
}

add_action(
	'admin_init',
	function () {
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		if ( is_user_logged_in() && revertix_staging_access_denied( revertix_staging_settings() ) ) {
			wp_die(
				esc_html__( 'You do not have access to this staging site.', 'revertix' ),
				'',
				array( 'response' => 403 )
			);
		}
	}
);

add_action(
	'template_redirect',
	function () {
		$settings = revertix_staging_settings();

		// The login wall can be switched off in Settings -> Staging (or via the
		// REVERTIX_STAGING_PUBLIC constant). The "noindex" tag is unaffected.
		$wall_off = ! empty( $settings['staging_disable_auth'] )
			|| ( defined( 'REVERTIX_STAGING_PUBLIC' ) && REVERTIX_STAGING_PUBLIC );

		if ( is_user_logged_in() ) {
			if ( ! $wall_off && revertix_staging_access_denied( $settings ) ) {
				wp_die(
					esc_html__( 'You do not have access to this staging site.', 'revertix' ),
					'',
					array( 'response' => 403 )
				);
			}
			return;
		}

		if ( $wall_off ) {
			return;
		}

		// Never interfere with the login/registration screen itself.
		if ( isset( $GLOBALS['pagenow'] ) && 'wp-login.php' === $GLOBALS['pagenow'] ) {
			return;
		}

		nocache_headers();
		// Send guests to login, then straight into wp-admin once authenticated.
		wp_safe_redirect( wp_login_url( admin_url() ) );
		exit;
	},
	0
);
PHP;
	}
}
