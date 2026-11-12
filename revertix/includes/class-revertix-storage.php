<?php
/**
 * Protected working storage for Revertix.
 *
 * All scratch state, export packages and backups live under
 * wp-content/uploads/revertix/, hardened so they are not web-guessable:
 *   - an .htaccess "Deny from all" (for Apache)
 *   - an empty index.html (directory listing guard)
 *   - a random token kept in an option, used to name the packages subfolder.
 *
 * Layout:
 *   uploads/revertix/
 *     .htaccess, index.html
 *     tmp/        in-progress job state + scratch
 *     backups/    saved backup packages (manual + scheduled)
 *     packages/   export packages awaiting download
 *     logs/       persistent diagnostic log (see Revertix_Log)
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Creates and locates Revertix's working directories.
 */
class Revertix_Storage {

	const OPTION_TOKEN = 'revertix_storage_token';

	/**
	 * Absolute path to the base working directory (trailing slash).
	 *
	 * @return string
	 */
	public static function base_dir() {
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'revertix/';
	}

	/**
	 * Absolute path to a named subdirectory (tmp|backups|packages), trailing slash.
	 *
	 * @param string $sub Subdirectory name.
	 * @return string
	 */
	public static function sub_dir( $sub ) {
		return self::base_dir() . trim( $sub, '/' ) . '/';
	}

	/**
	 * Whether the base directory exists and is writable (creating it if missing).
	 *
	 * @return bool
	 */
	public static function is_writable() {
		$base = self::base_dir();

		if ( ! file_exists( $base ) ) {
			wp_mkdir_p( $base );
		}

		return file_exists( $base ) && wp_is_writable( $base );
	}

	/**
	 * Create and harden all working directories. Runs on activation, and is
	 * idempotent so it can be called defensively before any operation.
	 */
	public static function ensure() {
		$base = self::base_dir();
		wp_mkdir_p( $base );

		self::harden( $base );

		// Harden each subdirectory too, so a server that ignores the parent's
		// rules still meets one in the directory actually being requested.
		foreach ( array( 'tmp', 'backups', 'packages', 'logs' ) as $sub ) {
			$dir = self::sub_dir( $sub );
			wp_mkdir_p( $dir );
			self::harden( $dir );
		}

		// Stable random token used when building public-facing package paths.
		if ( ! get_option( self::OPTION_TOKEN ) ) {
			update_option( self::OPTION_TOKEN, wp_generate_password( 20, false, false ), false );
		}
	}

	/**
	 * Drop the protective files into a directory.
	 *
	 * @param string $dir Absolute directory path.
	 */
	public static function harden( $dir ) {
		$dir = trailingslashit( $dir );

		$htaccess = $dir . '.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			// "Deny from all" (2.x) + Require all denied (2.4+) for portability.
			$rules = "Order deny,allow\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n";
			@file_put_contents( $htaccess, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		$index = $dir . 'index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}

		// index.php as well: some servers are configured to serve index.php ahead
		// of index.html, and a directory listing here would expose backup names.
		$index_php = $dir . 'index.php';
		if ( ! file_exists( $index_php ) ) {
			@file_put_contents( $index_php, "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		}
	}

	/**
	 * A per-site random string used to make stored file names unguessable.
	 *
	 * nginx has no per-directory configuration, so no file the plugin can write
	 * will stop it serving what is under uploads/. What DOES work is making the
	 * names impossible to guess: nothing here is ever linked to, because
	 * downloads are streamed by an authenticated PHP endpoint, so a name nobody
	 * can guess is a name nobody can fetch.
	 *
	 * @return string
	 */
	public static function token() {
		$token = get_option( self::OPTION_TOKEN );

		if ( ! is_string( $token ) || strlen( $token ) < 12 ) {
			$token = wp_generate_password( 20, false, false );
			update_option( self::OPTION_TOKEN, $token, false );
		}

		return $token;
	}

	/**
	 * Append the site token to a file name, before its extension.
	 *
	 * @param string $filename e.g. "example-com-20260820-131052.zip".
	 * @return string          e.g. "example-com-20260820-131052-<token>.zip".
	 */
	public static function tokenize( $filename ) {
		$ext  = pathinfo( $filename, PATHINFO_EXTENSION );
		$base = ( '' !== $ext ) ? substr( $filename, 0, -( strlen( $ext ) + 1 ) ) : $filename;

		return $base . '-' . self::token() . ( '' !== $ext ? '.' . $ext : '' );
	}

	/**
	 * Strip the site token back out, for showing a name to a person.
	 *
	 * @param string $filename Stored file name.
	 * @return string
	 */
	public static function untokenize( $filename ) {
		$token = self::token();

		if ( '' === $token ) {
			return $filename;
		}

		return str_replace( '-' . $token, '', $filename );
	}

	/**
	 * Clear a .maintenance file that nothing is going to clear itself.
	 *
	 * @param int $max_age_seconds How old before it counts as abandoned.
	 * @return bool Whether a stale file was removed.
	 */
	public static function clear_stale_maintenance( $max_age_seconds = 900 ) {
		$file = trailingslashit( Revertix_Filesystem::root() ) . '.maintenance';

		if ( ! is_file( $file ) ) {
			return false;
		}

		$age = time() - (int) filemtime( $file );

		if ( $age < (int) $max_age_seconds ) {
			return false;
		}

		wp_delete_file( $file );

		Revertix_Log::warning(
			'Removed a .maintenance file that was left behind.',
			array( 'age_seconds' => $age )
		);

		return true;
	}

	/**
	 * Whether the web server reads .htaccess files.
	 *
	 * Everything under the storage directory is protected by .htaccess, which is
	 * inert on nginx - there the only thing standing between a visitor and a
	 * backup archive (which contains wp-config.php) is the unguessable path.
	 *
	 * @return bool
	 */
	public static function server_uses_htaccess() {
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';

		$uses = ( false !== strpos( $software, 'apache' ) ) || ( false !== strpos( $software, 'litespeed' ) );

		/**
		 * Filter whether .htaccess protects the storage directory. Hosts running
		 * nginx in front of Apache should return true.
		 *
		 * @param bool   $uses     Whether .htaccess rules are honoured.
		 * @param string $software The SERVER_SOFTWARE string, lowercased.
		 */
		return (bool) apply_filters( 'revertix_server_uses_htaccess', $uses, $software );
	}

	/**
	 * The web-root-relative path of the storage directory, for the nginx snippet.
	 *
	 * @return string
	 */
	public static function public_relative_dir() {
		$uploads = wp_upload_dir();
		if ( empty( $uploads['baseurl'] ) ) {
			return '/wp-content/uploads/revertix/';
		}

		$path = wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );

		return trailingslashit( $path ? $path : '/wp-content/uploads' ) . 'revertix/';
	}

	/**
	 * Activation callback. Creates storage and stores the install version.
	 */
	public static function on_activation() {
		self::ensure();
		update_option( 'revertix_version', REVERTIX_VERSION, false );
	}

	/**
	 * Remove stale scratch so finished/abandoned jobs don't pile up on the site.
	 *
	 * Deletes job working dirs under tmp/ and leftover uploaded packages under
	 * imports/ that are older than the cutoff (default 6h, so a long in-progress
	 * job is never touched). Hooked to the daily cron.
	 *
	 * @param int $max_age_seconds Age threshold in seconds.
	 */
	public static function cleanup_tmp( $max_age_seconds = 21600 ) {
		$cutoff = time() - (int) $max_age_seconds;

		// Old job working directories.
		$tmp = self::sub_dir( 'tmp' );
		if ( is_dir( $tmp ) ) {
			foreach ( (array) glob( $tmp . '*', GLOB_ONLYDIR ) as $dir ) {
				if ( (int) filemtime( $dir ) < $cutoff ) {
					Revertix_Filesystem::delete_tree( $dir, rtrim( $tmp, '/' ) );
				}
			}
		}

		// Old uploaded import payloads (.zip / .part).
		$imports = self::sub_dir( 'imports' );
		if ( is_dir( $imports ) ) {
			foreach ( (array) glob( $imports . '*.{zip,part}', GLOB_BRACE ) as $file ) {
				if ( is_file( $file ) && (int) filemtime( $file ) < $cutoff ) {
					@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		}
	}
}
