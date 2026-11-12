<?php
/**
 * Staging registry + helpers.
 *
 * A staging site is a 1:1 copy of the live site living in a browsable web-root
 * subdirectory named after the chosen slug (e.g. /stage/) with its own cloned
 * database tables (<live_prefix>stg<slug>_*) in the same database. The
 * subdirectory is a fully independent WordPress: it gets its own wp-config.php
 * with the staging table prefix, so it boots on its own at <home_url>/<slug>/.
 *
 * Staging directories no longer carry a fixed name prefix, so they are excluded
 * from scans (export + new staging) via the registry - see all_roots(), which
 * callers pass into the file scanner.
 *
 * This class owns the small persistent registry of staging sites (an option),
 * plus the path/prefix/URL conventions and teardown. The heavy creation work is
 * a resumable job, Revertix_Staging_Job.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB -- direct, uncached queries are inherent to a backup/migration/staging engine. Table/column identifiers are bound with the %i placeholder and all values with %s/%d via $wpdb->prepare().

/**
 * CRUD and conventions for staging environments.
 */
class Revertix_Staging {

	const OPTION = 'revertix_staging_sites';

	/**
	 * Filename of the must-use plugin dropped into every staging site to lock it
	 * behind login. Excluded from all sync scans (see Revertix_Filesystem::
	 * excluded_names) so it can never be pushed to live or removed by a pull.
	 */
	const PROTECT_MU = 'revertix-staging-protect.php';

	/**
	 * Turn a user-supplied name into a unique, dir/URL/table-safe slug.
	 *
	 * Lower-cased alphanumeric (so it is valid in a path, a URL and a SQL table
	 * prefix). Empty names default to "staging"; collisions get a numeric suffix -
	 * staging, staging1, staging2… - matching the requested default behaviour.
	 *
	 * @param string $name Raw name from the user (may be empty).
	 * @return string
	 */
	public static function make_slug( $name ) {
		$base = strtolower( preg_replace( '/[^a-z0-9]/i', '', (string) $name ) );
		if ( '' === $base ) {
			$base = 'staging';
		}

		$slug = $base;
		$n    = 1;
		while ( self::slug_taken( $slug ) ) {
			$slug = $base . $n;
			++$n;
		}

		return $slug;
	}

	/**
	 * Whether a slug is already used by a registry entry or an existing directory.
	 *
	 * @param string $slug Candidate slug.
	 * @return bool
	 */
	protected static function slug_taken( $slug ) {
		return ( null !== self::get( $slug ) ) || is_dir( self::dir_for( $slug ) );
	}

	/**
	 * Absolute path to a staging site's root (trailing slash).
	 *
	 * @param string $slug Staging slug.
	 * @return string
	 */
	public static function dir_for( $slug ) {
		return trailingslashit( Revertix_Filesystem::root() ) . $slug . '/';
	}

	/**
	 * Public URL of a staging site (trailing slash).
	 *
	 * @param string $slug Staging slug.
	 * @return string
	 */
	public static function url_for( $slug ) {
		return trailingslashit( home_url( '/' . $slug . '/' ) );
	}

	/**
	 * Normalised absolute paths of all registered staging sites, for exclusion
	 * from file scans (export + new staging copies). Passed to the scanner so it
	 * never recurses into or re-copies an existing staging site.
	 *
	 * @return string[]
	 */
	public static function all_roots() {
		$roots = array();
		foreach ( self::all() as $site ) {
			$path    = ! empty( $site['path'] ) ? $site['path'] : self::dir_for( $site['slug'] );
			$roots[] = Revertix_Filesystem::normalize( $path );
		}
		return $roots;
	}

	/**
	 * The database table prefix for a staging site.
	 *
	 * @param string $slug Staging slug.
	 * @return string
	 */
	public static function prefix_for( $slug ) {
		global $wpdb;
		return $wpdb->prefix . 'stg' . $slug . '_';
	}

	/* ---------------------------------------------------------------------
	 * Registry
	 * ------------------------------------------------------------------- */

	/**
	 * All staging sites, newest first.
	 *
	 * @return array<int,array>
	 */
	public static function all() {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['created'] <=> (int) $a['created'];
			}
		);

		return array_values( $items );
	}

	/**
	 * A single staging site by slug.
	 *
	 * @param string $slug Staging slug.
	 * @return array|null
	 */
	public static function get( $slug ) {
		$items = get_option( self::OPTION, array() );
		return ( is_array( $items ) && isset( $items[ $slug ] ) ) ? $items[ $slug ] : null;
	}

	/**
	 * Add or replace a staging entry.
	 *
	 * @param array $entry Staging metadata (must include 'slug').
	 */
	public static function save( array $entry ) {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}
		$items[ $entry['slug'] ] = $entry;
		update_option( self::OPTION, $items, false );
	}

	/**
	 * How many staging sites exist.
	 *
	 * @return int
	 */
	public static function count() {
		return count( self::all() );
	}

	/**
	 * Settings keys whose behaviour is read on the staging site itself (by the
	 * login-protection mu-plugin and the admin bar), so they must be pushed down
	 * to each staging copy when they change on live.
	 *
	 * @var string[]
	 */
	const STAGING_FACING_KEYS = array(
		'staging_disable_auth',
		'staging_bar_color',
		'staging_access_users',
		'staging_access_roles',
	);

	/**
	 * Push the staging-facing settings down to every existing staging site.
	 *
	 * Staging sites live in the same database under their own table prefix, and
	 * each one reads its OWN cloned `revertix_settings` option. Without this, a
	 * change made on live (e.g. "Disable admin authorization") would only affect
	 * staging sites created afterwards. This writes the relevant keys straight
	 * into each staging site's options table so the change takes effect at once.
	 *
	 * Only ever runs on the live site - never from within a staging copy, whose
	 * cloned registry would point back at live/sibling prefixes.
	 *
	 * @param array $settings The freshly-saved live settings.
	 */
	public static function propagate_settings( array $settings ) {
		// Guard: a staging site must not push settings anywhere.
		if ( class_exists( 'Revertix_Admin_Bar' ) && Revertix_Admin_Bar::is_staging() ) {
			return;
		}

		$subset = array();
		foreach ( self::STAGING_FACING_KEYS as $key ) {
			if ( array_key_exists( $key, $settings ) ) {
				$subset[ $key ] = $settings[ $key ];
			}
		}
		if ( empty( $subset ) ) {
			return;
		}

		global $wpdb;

		foreach ( self::all() as $site ) {
			if ( empty( $site['slug'] ) ) {
				continue;
			}

			$prefix = ! empty( $site['prefix'] ) ? $site['prefix'] : self::prefix_for( $site['slug'] );
			$table  = $prefix . 'options';

			$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $table, 'revertix_settings' ) );

			$current = ( null !== $raw ) ? maybe_unserialize( $raw ) : array();
			if ( ! is_array( $current ) ) {
				$current = array();
			}

			$value = maybe_serialize( array_merge( $current, $subset ) );

			if ( null === $raw ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO %i (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $table, 'revertix_settings', $value ) );
			} else {
				$wpdb->query( $wpdb->prepare( 'UPDATE %i SET option_value = %s WHERE option_name = %s', $table, $value, 'revertix_settings' ) );
			}
		}
	}

	/**
	 * The maximum number of staging sites allowed on this site: unlimited.
	 * Kept as a method (with the -1 sentinel) because the dashboard and the
	 * system-info report both describe the limit.
	 *
	 * @return int -1 means unlimited.
	 */
	public static function max_allowed() {
		return -1;
	}

	/**
	 * Whether another staging site may be created right now. Always true -
	 * there is no cap on staging sites.
	 *
	 * @return bool
	 */
	public static function can_create() {
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Teardown
	 * ------------------------------------------------------------------- */

	/**
	 * Delete a staging site: drop its tables, remove its directory, drop the entry.
	 *
	 * @param string $slug Staging slug.
	 * @return bool True if an entry was removed.
	 */
	public static function delete( $slug ) {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) || ! isset( $items[ $slug ] ) ) {
			return false;
		}

		$entry = $items[ $slug ];

		// Drop the cloned tables (guarded by the unique staging prefix).
		$prefix = ! empty( $entry['prefix'] ) ? $entry['prefix'] : self::prefix_for( $slug );
		$dropped = Revertix_Database::drop_tables_with_prefix( $prefix );

		// Remove the staging directory, guarded to inside the web root.
		$dir  = ! empty( $entry['path'] ) ? $entry['path'] : self::dir_for( $slug );
		Revertix_Filesystem::delete_tree( rtrim( $dir, '/' ), rtrim( Revertix_Filesystem::root(), '/' ) );

		unset( $items[ $slug ] );
		update_option( self::OPTION, $items, false );

		Revertix_Log::info(
			'Staging deleted.',
			array(
				'slug'           => $slug,
				'tables_dropped' => $dropped,
			)
		);

		return true;
	}
}
