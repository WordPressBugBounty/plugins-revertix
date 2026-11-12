<?php
/**
 * Persistent diagnostic log.
 *
 * Revertix's heavy work (export/import) runs across many AJAX ticks, so an error
 * shown once in the browser is easily missed and the job's transient `message`
 * is overwritten by the next tick. This class keeps a durable, append-only log
 * file the user can read, copy and download from the admin UI - the single
 * artifact to send when reporting a problem.
 *
 * The file lives inside Revertix's hardened storage (uploads/revertix/logs/), so
 * it is not web-guessable and is removed on uninstall with the rest of storage.
 * It self-rotates: once it passes MAX_BYTES the current file is moved aside to
 * a single .1 backup and a fresh file is started, bounding disk use.
 *
 * Logging is best-effort and must never disrupt an operation: every write is
 * wrapped so a non-writable directory simply means "no log", not a fatal error.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Append-only, self-rotating log for support diagnostics.
 */
class Revertix_Log {

	const LEVEL_ERROR   = 'ERROR';
	const LEVEL_WARNING = 'WARNING';
	const LEVEL_INFO    = 'INFO';
	const LEVEL_DEBUG   = 'DEBUG';

	/**
	 * Rotate once the log grows past this size (bytes).
	 *
	 * @var int
	 */
	const MAX_BYTES = 1048576; // 1 MB.

	/**
	 * Option storing the retention settings.
	 *
	 * @var string
	 */
	const OPTION_SETTINGS = 'revertix_log_settings';

	/**
	 * Daily cron hook that prunes old entries.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'revertix_prune_log';

	/**
	 * Log an error.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Optional structured context appended as JSON.
	 */
	public static function error( $message, array $context = array() ) {
		self::write( self::LEVEL_ERROR, $message, $context );
	}

	/**
	 * Log a warning.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Optional structured context appended as JSON.
	 */
	public static function warning( $message, array $context = array() ) {
		self::write( self::LEVEL_WARNING, $message, $context );
	}

	/**
	 * Log an informational message.
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Optional structured context appended as JSON.
	 */
	public static function info( $message, array $context = array() ) {
		self::write( self::LEVEL_INFO, $message, $context );
	}

	/**
	 * Log a fine-grained debug message (stage transitions, counts, timings).
	 *
	 * @param string $message Human-readable message.
	 * @param array  $context Optional structured context appended as JSON.
	 */
	public static function debug( $message, array $context = array() ) {
		self::write( self::LEVEL_DEBUG, $message, $context );
	}

	/**
	 * Append one line to the log. Best-effort; never throws.
	 *
	 * @param string $level   One of the LEVEL_* constants.
	 * @param string $message Message text.
	 * @param array  $context Optional structured context.
	 */
	public static function write( $level, $message, array $context = array() ) {
		/**
		 * Allow disabling the file log entirely (e.g. on read-only filesystems).
		 *
		 * @param bool $enabled Whether to write to the log file.
		 */
		if ( ! apply_filters( 'revertix_log_enabled', true ) ) {
			return;
		}

		$file = self::file();
		if ( '' === $file ) {
			return;
		}

		self::maybe_rotate( $file );

		$line = sprintf(
			'[%s] %s - %s',
			gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			$level,
			self::one_line( (string) $message )
		);

		// Context is rendered as indented "key: value" continuation lines so each
		// entry stays scannable both on screen and in a downloaded file.
		foreach ( $context as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$value = (string) $value;
			} else {
				$value = wp_json_encode( $value );
			}
			$line .= "\n    " . $key . ': ' . self::one_line( (string) $value );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		@file_put_contents( $file, $line . "\n", FILE_APPEND | LOCK_EX );
	}

	/**
	 * Absolute path to the active log file, ensuring its directory exists and is
	 * hardened. Returns '' if storage is not writable.
	 *
	 * @return string
	 */
	public static function file() {
		$dir = self::dir();
		if ( '' === $dir ) {
			return '';
		}
		// Tokenised: on nginx nothing stops this being fetched directly, and a
		// diagnostic log is the one file here with a name anyone could guess.
		return $dir . Revertix_Storage::tokenize( 'revertix.log' );
	}

	/**
	 * The hardened logs directory (created on demand). Returns '' if unwritable.
	 *
	 * @return string
	 */
	protected static function dir() {
		$dir = Revertix_Storage::sub_dir( 'logs' );

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
			Revertix_Storage::harden( $dir );
		}

		return wp_is_writable( $dir ) ? $dir : '';
	}

	/**
	 * Move the current log aside to a single .1 backup once it passes MAX_BYTES.
	 *
	 * @param string $file Active log path.
	 */
	protected static function maybe_rotate( $file ) {
		if ( ! is_file( $file ) || filesize( $file ) < self::MAX_BYTES ) {
			return;
		}

		$backup = $file . '.1';
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		@unlink( $backup );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		@rename( $file, $backup );
	}

	/**
	 * Read the log for display. Returns the most recent $max_bytes (newest at the
	 * bottom). Includes a leading note if older content was truncated.
	 *
	 * @param int $max_bytes Maximum bytes to return.
	 * @return string
	 */
	public static function read( $max_bytes = 262144 ) {
		$file = self::file();
		if ( '' === $file || ! is_file( $file ) ) {
			return '';
		}

		$size = (int) filesize( $file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$fh = fopen( $file, 'r' );
		if ( false === $fh ) {
			return '';
		}

		$truncated = false;
		if ( $size > $max_bytes ) {
			fseek( $fh, $size - $max_bytes );
			$truncated = true;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$contents = stream_get_contents( $fh );
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		fclose( $fh );

		$contents = (string) $contents;

		if ( $truncated ) {
			// Drop a partial first line and prepend a notice.
			$nl = strpos( $contents, "\n" );
			if ( false !== $nl ) {
				$contents = substr( $contents, $nl + 1 );
			}
			$contents = "… (older entries truncated) …\n" . $contents;
		}

		return $contents;
	}

	/**
	 * Parse raw log text into structured entries for display.
	 *
	 * A header line ("[<time>] <LEVEL> - <message>") starts an entry; indented
	 * lines that follow are that entry's context. Anything that matches neither
	 * (e.g. the truncation notice) is kept as a loose entry so nothing is lost.
	 *
	 * @param string $raw Raw log contents.
	 * @return array<int,array{time:string,level:string,message:string,context:string[]}>
	 */
	public static function parse( $raw ) {
		$entries = array();
		$current = null;

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			if ( '' === $line ) {
				continue;
			}

			if ( preg_match( '/^\[(.+?)\]\s+(ERROR|WARNING|INFO|DEBUG)\s+-\s+(.*)$/u', $line, $m ) ) {
				if ( null !== $current ) {
					$entries[] = $current;
				}
				$current = array(
					'time'    => $m[1],
					'level'   => $m[2],
					'message' => $m[3],
					'context' => array(),
				);
				continue;
			}

			if ( null !== $current && '' !== trim( $line ) && ( ' ' === $line[0] || "\t" === $line[0] ) ) {
				$current['context'][] = trim( $line );
				continue;
			}

			// Loose line (truncation notice or legacy format): show as-is.
			if ( null !== $current ) {
				$entries[] = $current;
				$current   = null;
			}
			$entries[] = array(
				'time'    => '',
				'level'   => '',
				'message' => trim( $line ),
				'context' => array(),
			);
		}

		if ( null !== $current ) {
			$entries[] = $current;
		}

		return $entries;
	}

	/**
	 * Whether a non-empty log file exists.
	 *
	 * @return bool
	 */
	public static function has_entries() {
		$file = self::file();
		return '' !== $file && is_file( $file ) && filesize( $file ) > 0;
	}

	/**
	 * Empty the log (and its rotated backup).
	 */
	public static function clear() {
		$file = self::file();
		if ( '' === $file ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		@unlink( $file );
		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		@unlink( $file . '.1' );
	}

	/* ---------------------------------------------------------------------
	 * Retention (auto-delete old entries)
	 * ------------------------------------------------------------------- */

	/**
	 * The default retention settings: prune on, keep one month.
	 *
	 * @return array{auto_prune:bool,retention_days:int}
	 */
	public static function default_settings() {
		return array(
			'auto_prune'     => true,
			'retention_days' => 30,
		);
	}

	/**
	 * Current retention settings, merged over the defaults and sanitized.
	 *
	 * @return array{auto_prune:bool,retention_days:int}
	 */
	public static function settings() {
		$stored = get_option( self::OPTION_SETTINGS, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$merged = array_merge( self::default_settings(), $stored );

		$merged['auto_prune']     = ! empty( $merged['auto_prune'] );
		$merged['retention_days'] = self::sanitize_retention( $merged['retention_days'] );

		return $merged;
	}

	/**
	 * Persist retention settings, then apply them immediately.
	 *
	 * @param bool $auto_prune     Whether to delete old entries automatically.
	 * @param int  $retention_days How many days of entries to keep.
	 */
	public static function update_settings( $auto_prune, $retention_days ) {
		$settings = array(
			'auto_prune'     => (bool) $auto_prune,
			'retention_days' => self::sanitize_retention( $retention_days ),
		);

		update_option( self::OPTION_SETTINGS, $settings, false );

		// Reflect the new policy right away.
		if ( $settings['auto_prune'] ) {
			self::prune();
		}

		return $settings;
	}

	/**
	 * Allowed retention windows: days => label. Drives the settings UI and the
	 * validation of an incoming value.
	 *
	 * @return array<int,string>
	 */
	public static function retention_choices() {
		return array(
			7   => __( '1 week', 'revertix' ),
			30  => __( '1 month', 'revertix' ),
			90  => __( '3 months', 'revertix' ),
			180 => __( '6 months', 'revertix' ),
			365 => __( '1 year', 'revertix' ),
		);
	}

	/**
	 * Clamp a retention value to one of the allowed choices.
	 *
	 * @param mixed $days Candidate value.
	 * @return int
	 */
	protected static function sanitize_retention( $days ) {
		$days    = (int) $days;
		$choices = array_keys( self::retention_choices() );
		return in_array( $days, $choices, true ) ? $days : 30;
	}

	/**
	 * Drop entries older than the retention window, if auto-pruning is enabled.
	 *
	 * Rewrites the log file keeping only entries whose timestamp is newer than
	 * the cutoff. Entries without a parseable timestamp (e.g. legacy lines) are
	 * kept, so pruning never silently discards something it cannot date.
	 */
	public static function prune() {
		$settings = self::settings();
		if ( empty( $settings['auto_prune'] ) ) {
			return; // Disabled - never delete.
		}

		$file = self::file();
		if ( '' === $file || ! is_file( $file ) ) {
			return;
		}

		$cutoff = time() - ( $settings['retention_days'] * DAY_IN_SECONDS );

		// phpcs:ignore WordPress.WP.AlternativeFunctions
		$contents = (string) file_get_contents( $file );
		if ( '' === $contents ) {
			return;
		}

		$lines     = preg_split( '/\r\n|\r|\n/', $contents );
		$kept      = array();
		$keep_cur  = true;
		$dropped   = 0;
		$has_drops = false;

		foreach ( $lines as $line ) {
			if ( preg_match( '/^\[(.+?)\]\s+(?:ERROR|WARNING|INFO|DEBUG)\s+-/u', $line, $m ) ) {
				$ts       = strtotime( $m[1] );
				$keep_cur = ( false === $ts ) ? true : ( $ts >= $cutoff );
				if ( ! $keep_cur ) {
					$has_drops = true;
					++$dropped;
					continue;
				}
			} elseif ( ! $keep_cur ) {
				// Continuation line of a dropped entry.
				continue;
			}

			$kept[] = $line;
		}

		if ( ! $has_drops ) {
			return; // Nothing aged out.
		}

		$out = rtrim( implode( "\n", $kept ), "\n" );
		if ( '' !== $out ) {
			$out .= "\n";
		}

		$tmp = $file . '.' . wp_generate_password( 6, false, false ) . '.tmp';
		// phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false !== file_put_contents( $tmp, $out, LOCK_EX ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			@rename( $tmp, $file );
		}
	}

	/**
	 * Ensure the daily prune event is scheduled. Idempotent; safe to call often.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Remove the scheduled prune event (used on deactivation).
	 */
	public static function unschedule() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * A short environment block to prepend to a downloaded log, so a support
	 * report carries the context needed to reproduce an issue.
	 *
	 * @return string
	 */
	public static function environment_report() {
		global $wpdb;

		$lines = array(
			'Revertix '   . REVERTIX_VERSION . ' - diagnostic log',
			'Generated:  ' . gmdate( 'Y-m-d H:i:s' ) . ' UTC',
			'Site URL:   ' . home_url(),
			'WordPress:  ' . get_bloginfo( 'version' ),
			'PHP:        ' . PHP_VERSION,
			'MySQL:      ' . ( is_object( $wpdb ) ? $wpdb->db_version() : '?' ),
			'OS:         ' . PHP_OS,
			'Memory:     ' . ( defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : '?' ) . ' / max_exec ' . ini_get( 'max_execution_time' ) . 's',
			'Premium:    ' . ( revertix_is_premium() ? 'yes' : 'no' ),
		);

		return implode( "\n", $lines ) . "\n" . str_repeat( '-', 60 ) . "\n";
	}

	/**
	 * Collapse a message to a single line so each entry stays one line.
	 *
	 * @param string $message Raw message.
	 * @return string
	 */
	protected static function one_line( $message ) {
		return trim( preg_replace( '/\s+/', ' ', $message ) );
	}
}
