<?php
/**
 * Roll WordPress itself back (or forward) to another released version.
 *
 * Runs through WordPress' own Core_Upgrader, exactly like a manual
 * "Re-install version X" from Dashboard -> Updates, but pointed at the release
 * the user picked. WordPress therefore handles maintenance mode, file copying
 * with FS_CHMOD_DIR/FS_CHMOD_FILE, the database step and its own safety checks
 * (minimum PHP / MySQL of the target release).
 *
 * After a rollback WordPress would quietly update itself again within hours,
 * so the version can be pinned: automatic core updates are held while the
 * pinned version is installed, and the pin clears itself as soon as the site
 * runs any other version.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WordPress core version switching.
 */
class Revertix_Core_Rollback {

	const PIN_OPTION = 'revertix_core_pin';
	const CACHE_KEY  = 'revertix_core_versions1';
	const CACHE_TTL  = 12 * HOUR_IN_SECONDS;
	const API_URL    = 'https://api.wordpress.org/core/stable-check/1.0/';

	/**
	 * Oldest WordPress Revertix itself runs on (its "Requires at least"). Going
	 * below it would switch off the tool needed to undo the change.
	 */
	const MIN_WP = '6.2';

	/**
	 * Oldest WordPress release that supports each PHP version. Older releases
	 * fatal or flood deprecation notices on that PHP.
	 *
	 * @var array<string,string>
	 */
	protected static $php_min_wp = array(
		'8.0' => '5.6',
		'8.1' => '5.9',
		'8.2' => '6.1',
		'8.3' => '6.4',
		'8.4' => '6.7',
		'8.5' => '6.9',
	);

	/**
	 * Hook the auto-update hold. Runs on every request (cron included).
	 */
	public static function init() {
		add_filter( 'auto_update_core', array( __CLASS__, 'filter_auto_update' ), 100 );
	}

	/* ---------------------------------------------------------------------
	 * Pin (hold automatic core updates)
	 * ------------------------------------------------------------------- */

	/**
	 * The pinned version, or ''.
	 *
	 * @return string
	 */
	public static function pinned() {
		return (string) get_option( self::PIN_OPTION, '' );
	}

	/**
	 * Hold automatic core updates while the pinned version is installed.
	 *
	 * @param bool $update Whether WordPress wants to update itself.
	 * @return bool
	 */
	public static function filter_auto_update( $update ) {
		$pin = self::pinned();
		if ( '' === $pin ) {
			return $update;
		}

		// The site moved on (updated by hand) - the pin has done its job.
		if ( self::installed_version() !== $pin ) {
			delete_option( self::PIN_OPTION );
			Revertix_Log::info( 'WordPress version pin cleared: the site now runs another version.', array( 'pinned' => $pin ) );
			return $update;
		}

		return false;
	}

	/**
	 * Release the pin.
	 */
	public static function unpin() {
		$pin = self::pinned();
		delete_option( self::PIN_OPTION );
		Revertix_Log::info( 'Automatic WordPress updates resumed.', array( 'pinned' => $pin ) );
	}

	/* ---------------------------------------------------------------------
	 * Versions
	 * ------------------------------------------------------------------- */

	/**
	 * The WordPress version on disk right now.
	 *
	 * Read from version.php rather than $wp_version, which still holds the old
	 * value for the rest of the request that performed a switch.
	 *
	 * @return string
	 */
	public static function installed_version() {
		$file = ABSPATH . WPINC . '/version.php';
		$src  = is_readable( $file ) ? (string) file_get_contents( $file ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( preg_match( '/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $m ) ) {
			return $m[1];
		}
		return (string) get_bloginfo( 'version' );
	}

	/**
	 * Every released WordPress version with its wordpress.org status
	 * ('latest' | 'outdated' | 'insecure'), or null when the API is unreachable.
	 *
	 * @return array<string,string>|null
	 */
	protected static function all_releases() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_get( self::API_URL, array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			Revertix_Log::warning(
				'Could not load the WordPress version list from wordpress.org.',
				array( 'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) )
			);
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return null;
		}

		$out = array();
		foreach ( $data as $ver => $status ) {
			$ver = (string) $ver;
			if ( preg_match( '/^\d+\.\d+(\.\d+)?$/', $ver ) ) {
				$out[ $ver ] = (string) $status;
			}
		}

		set_transient( self::CACHE_KEY, $out, self::CACHE_TTL );

		return $out;
	}

	/**
	 * The oldest version this site may switch to, and why.
	 *
	 * @return array{version:string,reason:string}
	 */
	public static function minimum() {
		$min    = self::MIN_WP;
		$reason = 'revertix';

		$php  = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		$need = '';
		foreach ( self::$php_min_wp as $php_ver => $wp_ver ) {
			if ( version_compare( $php, $php_ver, '>=' ) ) {
				$need = $wp_ver; // Map is ascending, so the last match wins.
			}
		}
		if ( '' !== $need && version_compare( $need, $min, '>' ) ) {
			$min    = $need;
			$reason = 'php';
		}

		return array(
			'version' => $min,
			'reason'  => $reason,
		);
	}

	/**
	 * The branch ("6.8") of a version.
	 *
	 * @param string $version Version.
	 * @return string
	 */
	protected static function branch( $version ) {
		$parts = explode( '.', $version );
		return $parts[0] . '.' . ( isset( $parts[1] ) ? $parts[1] : '0' );
	}

	/**
	 * Versions offered for this site, newest first: every release of the
	 * installed branch, plus the newest release of each other branch, from the
	 * minimum upwards.
	 *
	 * @param string $current Installed version.
	 * @return array<int,array>|null Null when wordpress.org is unreachable.
	 */
	public static function versions( $current ) {
		$all = self::all_releases();
		if ( null === $all ) {
			return null;
		}

		$min            = self::minimum();
		$current_branch = self::branch( $current );

		// Newest release per branch.
		$newest = array();
		foreach ( array_keys( $all ) as $ver ) {
			$b = self::branch( $ver );
			if ( ! isset( $newest[ $b ] ) || version_compare( $ver, $newest[ $b ], '>' ) ) {
				$newest[ $b ] = $ver;
			}
		}

		$picked = array();
		foreach ( $all as $ver => $status ) {
			if ( version_compare( self::branch( $ver ), self::branch( $min['version'] ), '<' ) ) {
				continue;
			}
			if ( self::branch( $ver ) === $current_branch || $newest[ self::branch( $ver ) ] === $ver || $ver === $current ) {
				$picked[ $ver ] = $status;
			}
		}

		uksort(
			$picked,
			static function ( $a, $b ) {
				return version_compare( $b, $a );
			}
		);

		$out = array();
		foreach ( $picked as $ver => $status ) {
			$out[] = array(
				'version'   => (string) $ver,
				'installed' => ( (string) $ver === $current ),
				'latest'    => ( 'latest' === $status ),
				'insecure'  => ( 'insecure' === $status ),
				'allowed'   => ( (string) $ver !== $current ),
			);
		}

		return $out;
	}

	/**
	 * Whether $version is one this site may switch to.
	 *
	 * @param string $version Target.
	 * @param string $current Installed version.
	 * @return bool
	 */
	public static function can_switch_to( $version, $current ) {
		$list = self::versions( $current );
		if ( ! is_array( $list ) ) {
			return false;
		}
		foreach ( $list as $row ) {
			if ( $row['version'] === $version ) {
				return ! empty( $row['allowed'] );
			}
		}
		return false;
	}

	/**
	 * Why this site cannot switch WordPress versions at all, or ''.
	 *
	 * @return string
	 */
	public static function blocker() {
		if ( ! current_user_can( 'update_core' ) ) {
			return ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS )
				? __( 'WordPress updates are switched off on this site (DISALLOW_FILE_MODS), so its version cannot be changed from here.', 'revertix' )
				: __( 'Your account is not allowed to update WordPress on this site.', 'revertix' );
		}

		if ( ! function_exists( 'get_filesystem_method' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( 'direct' !== get_filesystem_method( array(), ABSPATH ) ) {
			return __( 'This server only lets WordPress change its own files through FTP, so the WordPress version cannot be changed from here. Use Dashboard → Updates instead.', 'revertix' );
		}

		return '';
	}

	/* ---------------------------------------------------------------------
	 * Switching
	 * ------------------------------------------------------------------- */

	/**
	 * Download URLs for a release: the site's language when wordpress.org has
	 * that build, otherwise the English one without the bundled content.
	 *
	 * @param string $version Target version.
	 * @return array{full:string,no_content:string|false,locale:string}
	 */
	protected static function packages( $version ) {
		$base   = 'https://downloads.wordpress.org/release/';
		$locale = get_locale();

		if ( 'en_US' !== $locale && preg_match( '/^[a-zA-Z_]+$/', $locale ) ) {
			$url  = $base . $locale . '/wordpress-' . $version . '.zip';
			$head = wp_remote_head( $url, array( 'timeout' => 10, 'redirection' => 3 ) );
			if ( ! is_wp_error( $head ) && 200 === (int) wp_remote_retrieve_response_code( $head ) ) {
				// A localized build has no no_content variant; update_core()
				// leaves wp-content alone either way.
				return array(
					'full'       => $url,
					'no_content' => false,
					'locale'     => $locale,
				);
			}
		}

		return array(
			'full'       => $base . 'wordpress-' . $version . '.zip',
			'no_content' => $base . 'wordpress-' . $version . '-no-content.zip',
			'locale'     => 'en_US',
		);
	}

	/**
	 * Switch WordPress to $version.
	 *
	 * @param string $version Target version (already validated).
	 * @param bool   $pin     Hold automatic updates afterwards.
	 * @return array{ok:bool,message:string}
	 */
	public static function run( $version, $pin ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		$from     = self::installed_version();
		$packages = self::packages( $version );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		Revertix_Log::info(
			'Switching WordPress version.',
			array(
				'from'    => $from,
				'to'      => $version,
				'locale'  => $packages['locale'],
				'package' => $packages['no_content'] ? $packages['no_content'] : $packages['full'],
				'pin'     => $pin,
			)
		);

		// Shaped like an offer from the wordpress.org update API, so
		// Core_Upgrader treats it as a re-install of this release.
		$offer = (object) array(
			'response'        => 'reinstall',
			'download'        => $packages['full'],
			'locale'          => $packages['locale'],
			'packages'        => (object) array(
				'full'        => $packages['full'],
				'no_content'  => $packages['no_content'],
				'new_bundled' => false,
				'partial'     => false,
				'rollback'    => false,
			),
			'current'         => $version,
			'version'         => $version,
			'php_version'     => '',
			'mysql_version'   => '',
			'new_bundled'     => '',
			'partial_version' => '',
		);

		$skin     = new Automatic_Upgrader_Skin();
		$upgrader = new Core_Upgrader( $skin );
		$result   = $upgrader->upgrade(
			$offer,
			array(
				'pre_check_md5'                => false,
				'allow_relaxed_file_ownership' => true,
			)
		);

		$now = self::installed_version();

		if ( is_wp_error( $result ) || ! $result || $now !== $version ) {
			$messages = (array) $skin->get_upgrade_messages();
			$detail   = is_wp_error( $result ) ? $result->get_error_message() : (string) end( $messages );

			Revertix_Log::error(
				'Switching WordPress version failed.',
				array(
					'from'      => $from,
					'to'        => $version,
					'installed' => $now,
					'code'      => is_wp_error( $result ) ? $result->get_error_code() : '',
					'detail'    => $detail,
					'messages'  => $messages,
				)
			);

			return array(
				'ok'      => false,
				'message' => '' !== $detail
					? wp_strip_all_tags( $detail )
					: __( 'WordPress could not be switched to that version. See the Logs tab for details.', 'revertix' ),
			);
		}

		if ( $pin ) {
			update_option( self::PIN_OPTION, $version, false );
		} else {
			delete_option( self::PIN_OPTION );
		}

		Revertix_Log::info(
			'WordPress version switched.',
			array(
				'from'   => $from,
				'to'     => $now,
				'pinned' => $pin,
			)
		);

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: WordPress version. */
				__( 'WordPress is now version %s.', 'revertix' ),
				$now
			),
		);
	}
}
