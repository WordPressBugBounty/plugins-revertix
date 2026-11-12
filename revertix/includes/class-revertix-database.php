<?php
/**
 * Database export/clone helpers.
 *
 * Dumps are written in row-chunked passes so a large table never blows the time
 * or memory budget. The dump format is plain SQL (DROP/CREATE/INSERT) that the
 * import engine replays. Table cloning (for staging) is added later; this file
 * currently provides the export side.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- creating/dropping/reading table schemas is the core job of a backup/migration/staging engine. Table/column identifiers are bound with the %i placeholder and all values with %s/%d via $wpdb->prepare().

/**
 * Reads the live database in resumable chunks.
 */
class Revertix_Database {

	/**
	 * Number of rows pulled per query when dumping. Filterable for tuning.
	 *
	 * @return int
	 */
	public static function rows_per_chunk() {
		return (int) apply_filters( 'revertix_db_rows_per_chunk', 500 );
	}

	/**
	 * Sanitise a table/column identifier for use in generated dump-file text
	 * (which is not run through $wpdb->prepare). Executed queries use the %i
	 * placeholder instead and do not need this. Keeps only characters valid in
	 * a MySQL identifier.
	 *
	 * @param string $name Identifier.
	 * @return string
	 */
	protected static function safe_identifier( $name ) {
		return preg_replace( '/[^A-Za-z0-9_$]/', '', (string) $name );
	}

	/**
	 * The tables that belong to this WordPress install.
	 *
	 * Returns tables matching the live prefix but EXCLUDES Revertix staging
	 * tables (wp_stg<id>_*) so a staging clone is never dumped as part of live.
	 *
	 * @return string[]
	 */
	public static function site_tables() {
		global $wpdb;

		$prefix = $wpdb->prefix;
		$like   = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		$staging_pattern = '#^' . preg_quote( $prefix, '#' ) . 'stg[0-9a-z]+_#i';

		$tables = array_filter(
			(array) $tables,
			static function ( $table ) use ( $staging_pattern ) {
				return ! preg_match( $staging_pattern, $table );
			}
		);

		return array_values( $tables );
	}

	/**
	 * The CREATE TABLE statement for a table, wrapped with a DROP guard and a
	 * preamble suitable for the start of a dump file.
	 *
	 * @param string $table Table name.
	 * @return string SQL.
	 */
	public static function create_statement( $table ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );

		$create = isset( $row[1] ) ? $row[1] : '';

		$ident = self::safe_identifier( $table );
		$sql   = "\n-- Table: {$ident}\n";
		$sql  .= 'DROP TABLE IF EXISTS `' . $ident . "`;\n";
		$sql  .= $create . ";\n";

		return $sql;
	}

	/**
	 * Total row count for a table (for progress reporting).
	 *
	 * @param string $table Table name.
	 * @return int
	 */
	public static function row_count( $table ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );
	}

	/**
	 * Dump a chunk of rows from a table as INSERT statements.
	 *
	 * @param string $table  Table name.
	 * @param int    $offset Row offset to start at.
	 * @param int    $limit  Maximum rows to read.
	 * @return array{sql:string,rows:int} Generated SQL and number of rows read.
	 */
	public static function dump_rows( $table, $offset, $limit ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, $limit, $offset ),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			return array(
				'sql'  => '',
				'rows' => 0,
			);
		}

		$values   = array();
		$repaired = 0;
		foreach ( $rows as $row ) {
			$cells = array();
			foreach ( $row as $value ) {
				if ( null === $value ) {
					$cells[] = 'NULL';
				} else {
					// _real_escape() ends with add_placeholder_escape(), which swaps
					// every % for a 66-character marker. That is meant to be undone
					// by the `query` filter when the query runs - but this string is
					// written to a .sql FILE and never runs, so the marker has to be
					// removed here or every % in the site's data is destroyed.
					$escaped = $wpdb->remove_placeholder_escape( $wpdb->_real_escape( self::repair_placeholder_escape( $value, $repaired ) ) );

					$cells[] = "'" . $escaped . "'";
				}
			}
			$values[] = '(' . implode( ',', $cells ) . ')';
		}

		$sql = 'INSERT INTO `' . self::safe_identifier( $table ) . '` VALUES ' . implode( ",\n", $values ) . ";\n";

		return array(
			'sql'      => $sql,
			'rows'     => count( $rows ),
			'repaired' => $repaired,
		);
	}

	/**
	 * Undo a WordPress "placeholder escape" that has been left in stored data.
	 *
	 * @param mixed $value Column value.
	 * @param int   $count Incremented by the number of markers replaced.
	 * @return mixed
	 */
	public static function repair_placeholder_escape( $value, &$count = 0 ) {
		if ( ! is_string( $value ) || false === strpos( $value, '{' ) ) {
			return $value;
		}

		/**
		 * Filter whether stray wpdb placeholder markers are repaired on export.
		 *
		 * @param bool $repair Whether to repair.
		 */
		if ( ! apply_filters( 'revertix_repair_placeholder_escape', true ) ) {
			return $value;
		}

		$replacements = 0;
		$out          = preg_replace( '/\{[0-9a-f]{64}\}/', '%', $value, -1, $replacements );

		if ( null === $out ) {
			return $value;
		}

		$count += (int) $replacements;

		return $out;
	}

	/**
	 * The preamble written once at the top of a dump file.
	 *
	 * @return string
	 */
	public static function dump_header() {
		$header  = "-- Revertix database export\n";
		$header .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$header .= "SET FOREIGN_KEY_CHECKS=0;\n";
		$header .= "SET NAMES utf8mb4;\n";
		return $header;
	}

	/**
	 * The footer written once at the end of a dump file.
	 *
	 * @return string
	 */
	public static function dump_footer() {
		return "SET FOREIGN_KEY_CHECKS=1;\n";
	}

	/* ---------------------------------------------------------------------
	 * Import side
	 * ------------------------------------------------------------------- */

	/**
	 * Execute SQL statements from an open dump file until the time budget runs
	 * low or end-of-file is reached. Resumable: only complete statements are run
	 * and the returned offset always points to a statement boundary.
	 *
	 * Statement detection relies on the dump format written by this class: values
	 * are escaped (no literal newlines), so a physical line ending in ';' marks
	 * the end of a statement.
	 *
	 * @param resource $fh         Open file handle (already seeked to the offset).
	 * @param callable $time_left  Returns remaining seconds (float).
	 * @param string   $old_prefix Table prefix the dump was created with.
	 * @param string   $new_prefix Table prefix to import into.
	 * @return array{done:bool,offset:int,executed:int}
	 */
	public static function import_sql( $fh, callable $time_left, $old_prefix, $new_prefix ) {
		$buffer   = '';
		$executed = 0;
		$safe     = ftell( $fh );

		while ( call_user_func( $time_left ) > 2.0 ) {
			$line = fgets( $fh );

			if ( false === $line ) {
				// EOF: run any trailing statement.
				if ( '' !== trim( $buffer ) ) {
					self::run_statement( $buffer, $old_prefix, $new_prefix );
					++$executed;
				}
				return array(
					'done'     => true,
					'offset'   => ftell( $fh ),
					'executed' => $executed,
				);
			}

			$trimmed = trim( $line );

			// Skip comment lines and blank lines between statements.
			if ( '' === $trimmed || 0 === strpos( $trimmed, '--' ) ) {
				if ( '' === $buffer ) {
					$safe = ftell( $fh );
				}
				continue;
			}

			$buffer .= $line;

			if ( ';' === substr( rtrim( $line, "\r\n" ), -1 ) ) {
				self::run_statement( $buffer, $old_prefix, $new_prefix );
				$buffer = '';
				++$executed;
				$safe = ftell( $fh ); // Boundary - safe to resume here.
			}
		}

		return array(
			'done'     => false,
			'offset'   => $safe,
			'executed' => $executed,
		);
	}

	/**
	 * Run a single statement, rewriting the table prefix on identifiers.
	 *
	 * Only backtick-anchored prefixes (`oldprefix...`) are rewritten, which match
	 * table identifiers in our dump and avoid touching ordinary string values.
	 *
	 * @param string $sql        The statement (may include leading whitespace).
	 * @param string $old_prefix Original prefix.
	 * @param string $new_prefix Target prefix.
	 */
	protected static function run_statement( $sql, $old_prefix, $new_prefix ) {
		global $wpdb;

		$sql = trim( $sql );
		if ( '' === $sql ) {
			return;
		}

		if ( $old_prefix !== $new_prefix && '' !== $old_prefix ) {
			$sql = str_replace( '`' . $old_prefix, '`' . $new_prefix, $sql );
		}

		// Suppress wpdb's own error printing so we can capture and report it
		// ourselves instead of letting it leak into the AJAX response.
		$prev = $wpdb->suppress_errors( true );

		// This replays one complete statement from a Revertix dump file that this
		// plugin generated and validated (a manifest-checked package). Row values
		// were escaped when the dump was written; only the leading table prefix is
		// rewritten above. It cannot be run through prepare() as it is a full,
		// pre-built DDL/DML statement.
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$result = $wpdb->query( $sql );

		$wpdb->suppress_errors( $prev );

		// A failed statement during import means the restored database would be
		// incomplete/broken. Record the exact MySQL error to the persistent log
		// and abort the job so the user sees a real message instead of a silent
		// half-import.
		if ( false === $result && '' !== $wpdb->last_error ) {
			$error = $wpdb->last_error;

			Revertix_Log::error(
				'Database import statement failed.',
				array(
					'mysql_error' => $error,
					'statement'   => self::snippet( $sql ),
				)
			);

			throw new Exception(
				esc_html(
					sprintf(
						/* translators: %s: MySQL error message. */
						__( 'A database statement failed during import: %s', 'revertix' ),
						$error
					)
				)
			);
		}
	}

	/**
	 * A short, single-line preview of a SQL statement for logging.
	 *
	 * @param string $sql Statement.
	 * @param int    $max Maximum characters to keep.
	 * @return string
	 */
	protected static function snippet( $sql, $max = 300 ) {
		$sql = trim( preg_replace( '/\s+/', ' ', $sql ) );
		if ( strlen( $sql ) > $max ) {
			$sql = substr( $sql, 0, $max ) . '…';
		}
		return $sql;
	}

	/**
	 * Primary-key column(s) for a table.
	 *
	 * @param string $table Table name.
	 * @return string[] Column names (empty if none).
	 */
	public static function primary_key( $table ) {
		global $wpdb;

		$keys = $wpdb->get_results( $wpdb->prepare( 'SHOW KEYS FROM %i WHERE Key_name = %s', $table, 'PRIMARY' ), ARRAY_A );

		$cols = array();
		foreach ( (array) $keys as $key ) {
			if ( isset( $key['Column_name'] ) ) {
				$cols[] = $key['Column_name'];
			}
		}

		return $cols;
	}

	/* ---------------------------------------------------------------------
	 * Staging: in-database table cloning
	 * ------------------------------------------------------------------- */

	/**
	 * Create an empty clone of a table's schema, dropping any previous clone.
	 *
	 * @param string $source Source table name.
	 * @param string $target Target (clone) table name.
	 * @return bool True on success.
	 */
	public static function clone_schema( $source, $target ) {
		global $wpdb;

		$prev = $wpdb->suppress_errors( true );

		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $target ) );
		$ok = $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i LIKE %i', $target, $source ) );

		$wpdb->suppress_errors( $prev );

		if ( false === $ok && '' !== $wpdb->last_error ) {
			Revertix_Log::error(
				'Could not create staging table.',
				array(
					'source'      => $source,
					'target'      => $target,
					'mysql_error' => $wpdb->last_error,
				)
			);
			return false;
		}

		return true;
	}

	/**
	 * Copy a chunk of rows from a source table into its clone.
	 *
	 * @param string $source Source table name.
	 * @param string $target Target (clone) table name.
	 * @param int    $offset Row offset to start at.
	 * @param int    $limit  Maximum rows to copy.
	 * @return int Rows copied (0 means the table is exhausted).
	 */
	public static function copy_rows_into( $source, $target, $offset, $limit ) {
		global $wpdb;

		$prev = $wpdb->suppress_errors( true );

		$result = $wpdb->query(
			$wpdb->prepare(
				'INSERT INTO %i SELECT * FROM %i LIMIT %d OFFSET %d',
				$target,
				$source,
				$limit,
				$offset
			)
		);

		$wpdb->suppress_errors( $prev );

		if ( false === $result ) {
			if ( '' !== $wpdb->last_error ) {
				Revertix_Log::error(
					'Could not copy rows into staging table.',
					array(
						'source'      => $source,
						'target'      => $target,
						'offset'      => $offset,
						'mysql_error' => $wpdb->last_error,
					)
				);
			}
			return 0;
		}

		return (int) $result;
	}

	/**
	 * Rename the table-prefix-dependent keys after a prefix change.
	 *
	 * WordPress stores a few keys that embed the table prefix - the role map
	 * (`<prefix>user_roles` in options) and per-user capability/settings meta
	 * (`<prefix>capabilities`, `<prefix>user_level`, `<prefix>user-settings`, …).
	 * When a clone uses a new prefix these MUST be renamed, or every user loses
	 * their capabilities and the admin shows up with no menus. This rewrites the
	 * leading prefix on those keys in the cloned options + usermeta tables.
	 *
	 * @param string $options_table  Cloned options table.
	 * @param string $usermeta_table Cloned usermeta table.
	 * @param string $old_prefix     Original table prefix (e.g. wp_).
	 * @param string $new_prefix     New table prefix (e.g. wp_stgabc_).
	 */
	public static function rekey_for_prefix( $options_table, $usermeta_table, $old_prefix, $new_prefix ) {
		global $wpdb;

		if ( $old_prefix === $new_prefix || '' === $old_prefix ) {
			return;
		}

		// The role map option: <old>user_roles → <new>user_roles.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET option_name = %s WHERE option_name = %s',
				$options_table,
				$new_prefix . 'user_roles',
				$old_prefix . 'user_roles'
			)
		);

		// Usermeta keys that begin with the old prefix → swap the leading prefix.
		$like  = $wpdb->esc_like( $old_prefix ) . '%';
		$start = strlen( $old_prefix ) + 1;
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET meta_key = CONCAT(%s, SUBSTRING(meta_key, %d)) WHERE meta_key LIKE %s',
				$usermeta_table,
				$new_prefix,
				$start,
				$like
			)
		);
	}

	/**
	 * Drop every table belonging to a given prefix (used to remove a staging set).
	 *
	 * @param string $prefix Exact table prefix, e.g. wp_stgabc123_.
	 * @return int Number of tables dropped.
	 */
	public static function drop_tables_with_prefix( $prefix ) {
		global $wpdb;

		if ( '' === $prefix ) {
			return 0;
		}

		$like = $wpdb->esc_like( $prefix ) . '%';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$tables = (array) $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

		$dropped = 0;
		foreach ( $tables as $table ) {
			if ( false !== $wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) ) ) {
				++$dropped;
			}
		}

		return $dropped;
	}

	/**
	 * Fetch a chunk of rows from a table as associative arrays.
	 *
	 * @param string $table  Table name.
	 * @param int    $offset Row offset.
	 * @param int    $limit  Max rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function fetch_rows( $table, $offset, $limit ) {
		global $wpdb;

		return (array) $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM %i LIMIT %d OFFSET %d', $table, $limit, $offset ),
			ARRAY_A
		);
	}
}
