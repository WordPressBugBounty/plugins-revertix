<?php
/**
 * Version rollback for plugins and themes.
 *
 * Rolls a plugin/theme back to any released wordpress.org version: the public
 * directory exposes every version + a download zip, which we read (cached) and
 * re-install through the WP upgrader. A rollback is reversible by re-installing
 * a newer release. Snapshotting premium/custom items that are NOT on
 * wordpress.org (no public version history) is provided by the separate
 * Revertix PRO add-on.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin/theme rollback engine + data.
 */
class Revertix_Rollback {

	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	/* ---------------------------------------------------------------------
	 * Listing installed items
	 * ------------------------------------------------------------------- */

	/**
	 * Every installed plugin as a normalised item.
	 *
	 * @return array<int,array>
	 */
	public static function plugins() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = self::plugin_slug( $file );
			$out[] = array(
				'type'    => 'plugin',
				'id'      => $file,
				'slug'    => $slug,
				'name'    => $data['Name'],
				'author'  => wp_strip_all_tags( $data['Author'] ),
				'version' => $data['Version'],
				'icon'    => self::plugin_icon_url( $slug ),
				'active'  => is_plugin_active( $file ),
			);
		}

		self::sort_active_first( $out );

		return $out;
	}

	/**
	 * Every installed theme as a normalised item.
	 *
	 * @return array<int,array>
	 */
	public static function themes() {
		$current = wp_get_theme();
		$out     = array();

		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$screenshot = $theme->get_screenshot(); // Absolute URL or false.
			$out[] = array(
				'type'    => 'theme',
				'id'      => $stylesheet,
				'slug'    => $stylesheet,
				'name'    => $theme->get( 'Name' ),
				'author'  => wp_strip_all_tags( (string) $theme->get( 'Author' ) ),
				'version' => $theme->get( 'Version' ),
				'icon'    => $screenshot ? $screenshot : '',
				'active'  => ( $current->get_stylesheet() === $stylesheet ),
			);
		}

		self::sort_active_first( $out );

		return $out;
	}

	/**
	 * Sort items so active ones come first, then alphabetically by name.
	 *
	 * @param array $items Items (by reference).
	 */
	protected static function sort_active_first( array &$items ) {
		usort( $items, static function ( $a, $b ) {
			$aa = ! empty( $a['active'] ) ? 0 : 1;
			$bb = ! empty( $b['active'] ) ? 0 : 1;
			if ( $aa !== $bb ) {
				return $aa - $bb;
			}
			return strcasecmp( $a['name'], $b['name'] );
		} );
	}

	/**
	 * Slug used for wordpress.org lookups (the plugin's folder, or the bare file
	 * name for single-file plugins).
	 *
	 * @param string $file Plugin file (folder/file.php).
	 * @return string
	 */
	public static function plugin_slug( $file ) {
		$file = str_replace( '\\', '/', $file );
		if ( false !== strpos( $file, '/' ) ) {
			return dirname( $file );
		}
		return preg_replace( '/\.php$/', '', $file );
	}

	/**
	 * A best-effort wordpress.org icon URL for a plugin (validated client-side via
	 * an onerror fallback, so a 404 just shows the placeholder tile).
	 *
	 * @param string $slug Plugin slug.
	 * @return string
	 */
	public static function plugin_icon_url( $slug ) {
		return 'https://ps.w.org/' . rawurlencode( $slug ) . '/assets/icon-128x128.png';
	}

	/* ---------------------------------------------------------------------
	 * wordpress.org version history (cached)
	 * ------------------------------------------------------------------- */

	/**
	 * Cached wordpress.org info for an item: versions + changelog HTML, or null if
	 * the item is not hosted there (premium/custom).
	 *
	 * @param string $type 'plugin' | 'theme'.
	 * @param string $slug Item slug.
	 * @return array{versions:array<int,string>,changelog:string}|null
	 */
	public static function wporg_info( $type, $slug ) {
		// The version prefix busts stale cache entries when the data shape changes.
		$key    = 'revertix_rbinfo3_' . $type . '_' . md5( $slug );
		$cached = get_transient( $key );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : null;
		}

		$info = ( 'theme' === $type )
			? self::fetch_theme_info( $slug )
			: self::fetch_plugin_info( $slug );

		// Cache the negative result too (as 0) so we don't hammer the API.
		set_transient( $key, null === $info ? 0 : $info, self::CACHE_TTL );

		return $info;
	}

	/**
	 * Released versions for an item from wordpress.org, newest first, or null.
	 *
	 * @param string $type 'plugin' | 'theme'.
	 * @param string $slug Item slug.
	 * @return array<int,string>|null
	 */
	public static function wporg_versions( $type, $slug ) {
		$info = self::wporg_info( $type, $slug );
		return is_array( $info ) ? $info['versions'] : null;
	}

	/**
	 * The changelog HTML for an item from wordpress.org ('' if none/custom).
	 *
	 * @param string $type 'plugin' | 'theme'.
	 * @param string $slug Item slug.
	 * @return string
	 */
	public static function wporg_changelog( $type, $slug ) {
		$info = self::wporg_info( $type, $slug );
		return is_array( $info ) ? $info['changelog'] : '';
	}

	/**
	 * @param string $slug Plugin slug.
	 * @return array{versions:array<int,string>,changelog:string}|null
	 */
	protected static function fetch_plugin_info( $slug ) {
		if ( ! function_exists( 'plugins_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		}

		$info = plugins_api(
			'plugin_information',
			array( 'slug' => $slug, 'fields' => array( 'versions' => true, 'sections' => true ) )
		);
		if ( is_wp_error( $info ) || empty( $info->versions ) ) {
			return null;
		}

		// Real download URLs keyed by version (authoritative - don't guess them).
		$packages = array();
		foreach ( (array) $info->versions as $ver => $url ) {
			$packages[ (string) $ver ] = (string) $url;
		}
		// Ensure the current stable version is present even if the tagged
		// `versions` list lags behind it (so "latest" is always offered).
		if ( ! empty( $info->version ) && ! empty( $info->download_link ) ) {
			$packages[ (string) $info->version ] = (string) $info->download_link;
		}

		return array(
			'versions'  => self::sort_versions( array_keys( $packages ) ),
			'packages'  => $packages,
			'changelog' => self::clean_changelog( isset( $info->sections['changelog'] ) ? $info->sections['changelog'] : '' ),
		);
	}

	/**
	 * @param string $slug Theme slug.
	 * @return array{versions:array<int,string>,changelog:string}|null
	 */
	protected static function fetch_theme_info( $slug ) {
		if ( ! function_exists( 'themes_api' ) ) {
			require_once ABSPATH . 'wp-admin/includes/theme.php';
		}

		$info = themes_api(
			'theme_information',
			array( 'slug' => $slug, 'fields' => array( 'versions' => true, 'sections' => true ) )
		);
		if ( is_wp_error( $info ) || empty( $info->versions ) ) {
			return null;
		}

		$sections  = isset( $info->sections ) ? (array) $info->sections : array();
		$changelog = isset( $sections['changelog'] ) ? $sections['changelog'] : '';

		$packages = array();
		foreach ( (array) $info->versions as $ver => $url ) {
			$packages[ (string) $ver ] = (string) $url;
		}
		if ( ! empty( $info->version ) && ! empty( $info->download_link ) ) {
			$packages[ (string) $info->version ] = (string) $info->download_link;
		}

		return array(
			'versions'  => self::sort_versions( array_keys( $packages ) ),
			'packages'  => $packages,
			'changelog' => self::clean_changelog( $changelog ),
		);
	}

	/**
	 * Sanitise + bound a changelog HTML blob for safe display.
	 *
	 * @param string $html Raw changelog HTML.
	 * @return string
	 */
	protected static function clean_changelog( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}
		// Keep it reasonable in size.
		if ( strlen( $html ) > 30000 ) {
			$html = substr( $html, 0, 30000 ) . '…';
		}
		return wp_kses_post( $html );
	}

	/**
	 * Drop 'trunk', sort by version descending.
	 *
	 * @param string[] $versions Raw version strings.
	 * @return string[]
	 */
	protected static function sort_versions( array $versions ) {
		$versions = array_values( array_unique( array_filter( $versions, static function ( $v ) {
			return 'trunk' !== $v && '' !== $v;
		} ) ) );

		usort( $versions, static function ( $a, $b ) {
			return version_compare( $b, $a );
		} );

		return $versions;
	}

	/**
	 * The version immediately before $current within $versions (newest-first), or
	 * '' if there is none.
	 *
	 * @param string   $current  Current version.
	 * @param string[] $versions Sorted-desc version list.
	 * @return string
	 */
	public static function previous_version( $current, array $versions ) {
		foreach ( $versions as $v ) {
			if ( version_compare( $v, $current, '<' ) ) {
				return $v;
			}
		}
		return '';
	}

	/**
	 * The authoritative download URL for a specific version: the real URL that
	 * wordpress.org reported for it, falling back to the conventional URL.
	 *
	 * @param string $type    'plugin' | 'theme'.
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return string
	 */
	public static function package_for( $type, $slug, $version ) {
		$info = self::wporg_info( $type, $slug );
		if ( is_array( $info ) && ! empty( $info['packages'][ $version ] ) ) {
			return $info['packages'][ $version ];
		}
		return self::package_url( $type, $slug, $version );
	}

	/**
	 * The conventional wordpress.org download URL for a version (fallback).
	 *
	 * @param string $type    'plugin' | 'theme'.
	 * @param string $slug    Slug.
	 * @param string $version Version.
	 * @return string
	 */
	public static function package_url( $type, $slug, $version ) {
		$base = ( 'theme' === $type ) ? 'theme' : 'plugin';
		return 'https://downloads.wordpress.org/' . $base . '/' . rawurlencode( $slug ) . '.' . rawurlencode( $version ) . '.zip';
	}

	/* ---------------------------------------------------------------------
	 * Gating
	 * ------------------------------------------------------------------- */

	/**
	 * The newest version available in the repo, or '' if none.
	 *
	 * @param string[] $versions Sorted-desc version list.
	 * @return string
	 */
	public static function latest_version( array $versions ) {
		return ! empty( $versions ) ? (string) $versions[0] : '';
	}

	/**
	 * Whether this item may be switched to $version: any released version from
	 * the wordpress.org history, except the one already installed.
	 *
	 * @param string   $version  Target version.
	 * @param string   $current  Current (installed) version.
	 * @param string[] $versions Sorted-desc version list.
	 * @return bool
	 */
	public static function can_rollback_to( $version, $current, array $versions ) {
		return $version !== $current && in_array( $version, $versions, true );
	}

}
