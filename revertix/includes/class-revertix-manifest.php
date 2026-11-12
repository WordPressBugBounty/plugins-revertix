<?php
/**
 * Builds and reads the package manifest.json.
 *
 * The manifest is the portable description of a package: where it came from,
 * how its files/DB are laid out, and the versions involved. The import engine
 * relies on it to perform the correct serialize-safe search-replace.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manifest factory/reader.
 */
class Revertix_Manifest {

	/**
	 * Build the manifest array describing the current site.
	 *
	 * @param string[] $tables Tables included in the dump.
	 * @return array
	 */
	public static function build( array $tables ) {
		global $wpdb;

		$uploads = wp_upload_dir();

		return array(
			'generator'      => 'Revertix',
			'format'         => REVERTIX_PACKAGE_FORMAT,
			'created'        => gmdate( 'c' ),
			'site'           => array(
				'home_url'    => home_url(),
				'site_url'    => site_url(),
				'abspath'     => Revertix_Filesystem::normalize( Revertix_Filesystem::root() ),
				'content_dir' => Revertix_Filesystem::normalize( Revertix_Filesystem::content_dir() ),
				'uploads_dir' => Revertix_Filesystem::normalize( $uploads['basedir'] ),
				'uploads_url' => $uploads['baseurl'],
			),
			'database'       => array(
				'table_prefix' => $wpdb->prefix,
				'charset'      => $wpdb->charset,
				'tables'       => array_values( $tables ),
				'dump_file'    => 'database.sql',
			),
			'files'          => array(
				'root'  => 'files/',
				'index' => 'files-index.txt',
			),
			'versions'       => array(
				'wp'      => get_bloginfo( 'version' ),
				'php'     => PHP_VERSION,
				'revertix' => REVERTIX_VERSION,
			),
			'active_plugins' => (array) get_option( 'active_plugins', array() ),
			'active_theme'   => (string) get_option( 'stylesheet' ),
			'is_multisite'   => is_multisite(),
		);
	}

	/**
	 * Encode a manifest array to pretty JSON.
	 *
	 * @param array $manifest Manifest data.
	 * @return string
	 */
	public static function to_json( array $manifest ) {
		return wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Decode a manifest JSON string into an array.
	 *
	 * @param string $json JSON.
	 * @return array|null Null if invalid.
	 */
	public static function from_json( $json ) {
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}
}
