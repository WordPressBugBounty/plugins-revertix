<?php
/**
 * Persistent registry of finished export packages.
 *
 * The export job stores its package in a protected folder, but the *list* of
 * available packages must survive page reloads - so we keep a small index in an
 * option. Each entry records enough to list, download and delete the package.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD for export package metadata.
 */
class Revertix_Package_Registry {

	const OPTION = 'revertix_packages';

	/**
	 * All packages, newest first.
	 *
	 * @return array<int,array>
	 */
	public static function all() {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		// Drop entries whose files have vanished, then sort by created desc.
		$items = array_filter(
			$items,
			static function ( $entry ) {
				return ! empty( $entry['path'] ) && file_exists( $entry['path'] );
			}
		);

		usort(
			$items,
			static function ( $a, $b ) {
				return (int) $b['created'] <=> (int) $a['created'];
			}
		);

		return array_values( $items );
	}

	/**
	 * Look up a single package by id.
	 *
	 * @param string $id Package id.
	 * @return array|null
	 */
	public static function get( $id ) {
		$items = get_option( self::OPTION, array() );
		return ( is_array( $items ) && isset( $items[ $id ] ) ) ? $items[ $id ] : null;
	}

	/**
	 * Add (or replace) a package entry.
	 *
	 * @param array $entry {
	 *     @type string $id      Package id.
	 *     @type string $name    Filename.
	 *     @type string $path    Absolute path.
	 *     @type int    $size    Bytes.
	 *     @type int    $created Unix timestamp.
	 *     @type array  $parts   What the package contains.
	 * }
	 */
	public static function add( array $entry ) {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		$items[ $entry['id'] ] = $entry;
		update_option( self::OPTION, $items, false );
	}

	/**
	 * Delete a package: remove its files (containment-guarded) and its entry.
	 *
	 * @param string $id Package id.
	 * @return bool True if an entry was removed.
	 */
	public static function delete( $id ) {
		// A link must never outlive the file it points at.
		if ( class_exists( 'Revertix_Share' ) ) {
			Revertix_Share::revoke( $id );
		}

		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) || ! isset( $items[ $id ] ) ) {
			return false;
		}

		// Remove the per-package directory under packages/, never anything else.
		$packages_base = Revertix_Storage::sub_dir( 'packages' );
		$dir           = $packages_base . $id;
		Revertix_Filesystem::delete_tree( $dir, $packages_base );

		unset( $items[ $id ] );
		update_option( self::OPTION, $items, false );

		return true;
	}

	/**
	 * A signed download URL for a package, safe for HTML output.
	 *
	 * @param string $id Package id.
	 * @return string
	 */
	public static function download_url( $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'revertix_download',
					'job'    => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'revertix_download'
		);
	}
}
