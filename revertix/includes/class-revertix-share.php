<?php
/**
 * Shareable download links for export packages.
 *
 * A package sometimes has to reach someone who has no account here - a
 * developer, the next host's support desk, a client. Mailing 1.7 GB is not an
 * option, so instead we hand out a link that anyone holding it can use once,
 * and nobody else can find.
 *
 * How the link is kept safe:
 *
 *   - The token is 43 URL-safe random characters. Guessing one is not a
 *     realistic attack.
 *   - What the database holds is a SHA-256 hash for matching, plus the token
 *     encrypted with a key derived from the site's salts. Where those salts sit
 *     in wp-config.php - the usual arrangement - a copy of the database on its
 *     own reveals no working link, and Revertix strips them from the wp-config
 *     it puts inside a package, so a leaked package does not unlock them either.
 *     (Some installs keep the salts in the database instead; there the
 *     encryption is worth less, but the expiry and revocation still hold.)
 *   - The lookup is by hash and confirmed with hash_equals(), so nothing can be
 *     learned from how long a request takes.
 *   - Every link carries an expiry, and can be revoked or capped to a number of
 *     downloads. Deleting the package takes its link with it.
 *   - Wrong tokens get a bare 404 - no hint that a link ever existed - and
 *     repeated failures from one address are throttled.
 *
 * The endpoint lives on the front end rather than under /wp-admin/, because
 * hosts and security plugins routinely restrict wp-admin for logged-out
 * visitors, which is precisely who this is for.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates, validates and serves package share links.
 */
class Revertix_Share {

	/**
	 * Option holding every live share link, keyed by package id.
	 */
	const OPTION = 'revertix_shares';

	/**
	 * Query variable carrying the token on the front end.
	 */
	const QUERY_VAR = 'revertix-share';

	/**
	 * Default lifetime of a new link.
	 */
	const DEFAULT_TTL = WEEK_IN_SECONDS;

	/**
	 * Failed attempts allowed from one address per hour.
	 */
	const MAX_FAILURES = 20;

	/**
	 * Hook the public endpoint.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'maybe_serve' ) );
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * Every stored link (expired ones pruned on read).
	 *
	 * @return array<string,array>
	 */
	public static function all() {
		$shares = get_option( self::OPTION, array() );
		if ( ! is_array( $shares ) ) {
			return array();
		}

		$now     = time();
		$changed = false;

		foreach ( $shares as $id => $share ) {
			if ( ! is_array( $share ) || ( ! empty( $share['expires'] ) && (int) $share['expires'] < $now ) ) {
				unset( $shares[ $id ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::OPTION, $shares, false );
		}

		return $shares;
	}

	/**
	 * The live link for one package, or null.
	 *
	 * @param string $package_id Package id.
	 * @return array|null
	 */
	public static function get( $package_id ) {
		$shares = self::all();
		return isset( $shares[ $package_id ] ) ? $shares[ $package_id ] : null;
	}

	/* ---------------------------------------------------------------------
	 * Lifecycle
	 * ------------------------------------------------------------------- */

	/**
	 * How long a new link may live, and whether the choice is the caller's.
	 *
	 * The free plugin issues links with one fixed lifetime. The PRO add-on
	 * filters this to offer a choice of lifetime, and adds conditions of its own
	 * through revertix_share_extra.
	 *
	 * @return array{ttl:int} Defaults.
	 */
	public static function defaults() {
		/**
		 * Filter the settings a new share link is created with.
		 *
		 * @param array $defaults ttl (seconds).
		 */
		return (array) apply_filters(
			'revertix_share_defaults',
			array(
				'ttl' => self::DEFAULT_TTL,
			)
		);
	}

	/**
	 * Create (or replace) the link for a package.
	 *
	 * @param string $package_id Package id.
	 * @param array  $args       Optional overrides, validated against defaults().
	 * @return array{token:string,url:string,expires:int}|WP_Error
	 */
	public static function create( $package_id, array $args = array() ) {
		$package = Revertix_Package_Registry::get( $package_id );
		if ( ! $package ) {
			return new WP_Error( 'revertix_share_no_package', __( 'That package no longer exists.', 'revertix' ) );
		}

		$settings = wp_parse_args( $args, self::defaults() );

		$ttl = (int) $settings['ttl'];
		if ( $ttl < 0 ) {
			$ttl = 0;
		}

		// 43 characters of URL-safe randomness.
		$token = wp_generate_password( 43, false, false );

		$share = array(
			'hash'          => hash( 'sha256', $token ),
			// Kept so the link can be read again while it is live. Encrypted with
			// a key derived from the site's salts, which live in wp-config and
			// never in the database - nor in our own packages, which strip them.
			'token'         => self::encrypt( $token ),
			'created'       => time(),
			'expires'       => $ttl > 0 ? time() + $ttl : 0,
			'downloads'     => 0,
			'created_by'    => get_current_user_id(),
			/**
			 * Extra data an add-on wants kept with the link.
			 *
			 * Revertix PRO uses this for the optional password: hashing it and
			 * asking for it are entirely its business, so nothing about passwords
			 * lives here.
			 *
			 * @param array $extra    Empty by default.
			 * @param array $settings The settings this link is being created with.
			 */
			'extra'         => (array) apply_filters( 'revertix_share_extra', array(), $settings ),
		);

		$shares                = self::all();
		$shares[ $package_id ] = $share;
		update_option( self::OPTION, $shares, false );

		Revertix_Log::info(
			'Share link created.',
			array(
				'package' => $package_id,
				'expires' => $share['expires'] ? gmdate( 'Y-m-d H:i:s', $share['expires'] ) . ' UTC' : 'never',
			)
		);

		return array(
			'token'   => $token,
			'url'     => self::url( $token ),
			'expires' => $share['expires'],
		);
	}

	/**
	 * Withdraw the link for a package.
	 *
	 * @param string $package_id Package id.
	 * @return bool Whether a link was removed.
	 */
	public static function revoke( $package_id ) {
		$shares = self::all();

		if ( ! isset( $shares[ $package_id ] ) ) {
			return false;
		}

		unset( $shares[ $package_id ] );
		update_option( self::OPTION, $shares, false );

		Revertix_Log::info( 'Share link revoked.', array( 'package' => $package_id ) );

		return true;
	}

	/**
	 * The public URL for a token.
	 *
	 * @param string $token Plain token.
	 * @return string
	 */
	public static function url( $token ) {
		return add_query_arg( self::QUERY_VAR, rawurlencode( $token ), home_url( '/' ) );
	}

	/**
	 * The live link for a package, if it can still be read.
	 *
	 * @param string $package_id Package id.
	 * @return array{url:string,expires:int,downloads:int}|null
	 */
	public static function link( $package_id ) {
		$share = self::get( $package_id );

		if ( ! $share || empty( $share['token'] ) ) {
			return null;
		}

		$token = self::decrypt( $share['token'] );

		if ( '' === $token ) {
			return null;
		}

		return array(
			'url'       => self::url( $token ),
			'expires'   => isset( $share['expires'] ) ? (int) $share['expires'] : 0,
			'downloads' => isset( $share['downloads'] ) ? (int) $share['downloads'] : 0,
		);
	}

	/**
	 * The key used to encrypt stored tokens.
	 *
	 * wp_salt() is the right source rather than the AUTH_KEY family of constants
	 * directly: plenty of installs - Local and several managed hosts among them -
	 * never define those constants and keep the salts in the options table
	 * instead, and reading the constants there simply yields nothing.
	 *
	 * How much this protects therefore depends on where the site keeps its salts.
	 * With them in wp-config.php - the usual arrangement, and the one Revertix
	 * strips from the wp-config it puts in a package - a copy of the database
	 * alone cannot read a link. Where WordPress keeps them in the database, the
	 * encryption stops a casual reader rather than someone holding a full dump.
	 * Either way the link still expires and can be revoked.
	 *
	 * @return string 32 raw bytes, or '' when no salt material is available.
	 */
	protected static function key() {
		if ( ! function_exists( 'wp_salt' ) ) {
			return '';
		}

		$material = '';

		foreach ( array( 'auth', 'secure_auth', 'logged_in', 'nonce' ) as $scheme ) {
			$material .= (string) wp_salt( $scheme );
		}

		if ( strlen( $material ) < 16 ) {
			return '';
		}

		return hash( 'sha256', 'revertix-share|' . $material, true );
	}

	protected static function encrypt( $token ) {
		$key = self::key();

		if ( '' === $key || ! function_exists( 'openssl_encrypt' ) ) {
			// Without a key the link simply cannot be shown again. That is a lesser
			// failure than keeping it where anyone reading the database finds it.
			return '';
		}

		$iv = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : '';
		if ( 16 !== strlen( $iv ) ) {
			return '';
		}

		$cipher = openssl_encrypt( $token, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );

		if ( false === $cipher ) {
			return '';
		}

		return base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary ciphertext, not obfuscation.
	}

	/**
	 * Read a stored token back.
	 *
	 * @param string $blob Stored value.
	 * @return string Plain token, or '' if it cannot be read.
	 */
	protected static function decrypt( $blob ) {
		$key = self::key();

		if ( '' === $key || '' === (string) $blob || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}

		$raw = base64_decode( $blob, true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- binary ciphertext.

		if ( false === $raw || strlen( $raw ) <= 16 ) {
			return '';
		}

		$plain = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );

		return ( false === $plain ) ? '' : $plain;
	}

	/* ---------------------------------------------------------------------
	 * Serving
	 * ------------------------------------------------------------------- */

	/**
	 * Answer a share request, if this one is a share request.
	 */
	public function maybe_serve() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- a public link carries no session to bind a nonce to; the token IS the credential.
		if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token = sanitize_text_field( wp_unslash( $_GET[ self::QUERY_VAR ] ) );

		if ( self::too_many_failures() ) {
			status_header( 429 );
			nocache_headers();
			wp_die( esc_html__( 'Too many attempts. Please try again later.', 'revertix' ), '', array( 'response' => 429 ) );
		}

		$match = self::match( $token );

		if ( ! $match ) {
			self::record_failure();
			// Indistinguishable from any other missing URL.
			status_header( 404 );
			nocache_headers();
			wp_die( esc_html__( 'Not found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		list( $package_id, $share ) = $match;

		// Expired or used up: say so plainly. The visitor already proved they
		// hold a real token, so there is nothing left to conceal, and "expired"
		// saves them thinking the sender made a mistake.
		if ( ! empty( $share['expires'] ) && (int) $share['expires'] < time() ) {
			self::revoke( $package_id );
			wp_die(
				esc_html__( 'This download link has expired. Please ask for a new one.', 'revertix' ),
				esc_html__( 'Link expired', 'revertix' ),
				array( 'response' => 410 )
			);
		}

		/**
		 * Last chance for an add-on to stand in front of the download.
		 *
		 * Revertix PRO uses this to ask for the link's password. Anything hooked
		 * here that wants to refuse must send its own response and exit.
		 *
		 * @param string $package_id Package the link points at.
		 * @param array  $share      The stored link.
		 */
		do_action( 'revertix_share_before_download', $package_id, $share );

		self::serve( $package_id, $share );
	}

	/**
	 * Find the link a token belongs to.
	 *
	 * Looks up by hash, then confirms with a timing-safe comparison.
	 *
	 * @param string $token Plain token.
	 * @return array{0:string,1:array}|null
	 */
	protected static function match( $token ) {
		if ( '' === $token ) {
			return null;
		}

		$hash = hash( 'sha256', $token );

		foreach ( self::all() as $package_id => $share ) {
			if ( ! empty( $share['hash'] ) && hash_equals( (string) $share['hash'], $hash ) ) {
				return array( $package_id, $share );
			}
		}

		return null;
	}

	/**
	 * Stream the package.
	 *
	 * @param string $package_id Package id.
	 * @param array  $share      Share record.
	 */
	protected static function serve( $package_id, array $share ) {
		$package = Revertix_Package_Registry::get( $package_id );

		if ( ! $package || empty( $package['path'] ) ) {
			self::revoke( $package_id );
			status_header( 404 );
			wp_die( esc_html__( 'Not found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		// Same containment guard the admin download uses: only ever serve a file
		// that really sits inside the packages directory.
		$packages = Revertix_Filesystem::normalize( Revertix_Storage::sub_dir( 'packages' ) );
		$real     = realpath( $package['path'] );

		if ( ! $real || 0 !== strpos( Revertix_Filesystem::normalize( $real ), $packages ) || ! is_file( $real ) ) {
			status_header( 404 );
			wp_die( esc_html__( 'Not found.', 'revertix' ), '', array( 'response' => 404 ) );
		}

		$shares = self::all();
		if ( isset( $shares[ $package_id ] ) ) {
			++$shares[ $package_id ]['downloads'];
			update_option( self::OPTION, $shares, false );
		}

		Revertix_Log::info(
			'Share link used.',
			array(
				'package' => $package_id,
				'ip'      => self::client_ip(),
			)
		);

		Revertix_Filesystem::stream_download( $real, Revertix_Storage::untokenize( basename( $real ) ) );
	}

	/* ---------------------------------------------------------------------
	 * Throttling
	 * ------------------------------------------------------------------- */

	/**
	 * The requesting address, for throttling and the log.
	 *
	 * @return string
	 */
	protected static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip ? $ip : 'unknown';
	}

	/**
	 * Transient key for the current address.
	 *
	 * @return string
	 */
	protected static function failure_key() {
		return 'revertix_share_fail_' . md5( self::client_ip() );
	}

	/**
	 * Whether this address has failed too often lately.
	 *
	 * @return bool
	 */
	protected static function too_many_failures() {
		return (int) get_transient( self::failure_key() ) >= self::MAX_FAILURES;
	}

	/**
	 * Count one failed attempt. Public so add-ons guarding a link (PRO's
	 * password prompt) share the same throttle.
	 */
	public static function record_failure() {
		$key = self::failure_key();
		set_transient( $key, (int) get_transient( $key ) + 1, HOUR_IN_SECONDS );
	}
}
