<?php
/**
 * Persistent registry of backups.
 *
 * A backup is a full Revertix package stored in the protected backups/ folder.
 * Any backup can be restored or downloaded; restore reuses the import engine
 * and is preceded by an automatic safety backup. The most recent backup is
 * kept as a safety net (it becomes deletable once a newer backup exists).
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRUD + restore-gating for backups.
 */
class Revertix_Backup {

	const OPTION = 'revertix_backups';

	/**
	 * All backups, newest first.
	 *
	 * Reconciles the registry option with the actual files on disk: entries whose
	 * file vanished are dropped, and backup zips found on disk but missing from
	 * the option are added back (with inferred metadata). This is what keeps the
	 * safety backup visible/restorable even after a restore reverts wp_options —
	 * the list always reflects what is really on disk.
	 *
	 * @return array<int,array>
	 */
	public static function all() {
		$items = get_option( self::OPTION, array() );
		if ( ! is_array( $items ) ) {
			$items = array();
		}

		// Keep only registry entries whose file still exists, keyed by id.
		$valid = array();
		foreach ( $items as $id => $entry ) {
			if ( ! empty( $entry['path'] ) && file_exists( $entry['path'] ) ) {
				$entry['id']   = isset( $entry['id'] ) ? $entry['id'] : $id;
				$valid[ $entry['id'] ] = $entry;
			}
		}

		// Add any backup zips on disk that are not in the registry.
		foreach ( self::scan_disk() as $id => $entry ) {
			if ( ! isset( $valid[ $id ] ) ) {
				$valid[ $id ] = $entry;
			}
		}

		$list = array_values( $valid );
		usort(
			$list,
			static function ( $a, $b ) {
				return (int) $b['created'] <=> (int) $a['created'];
			}
		);

		return $list;
	}

	/**
	 * Discover backup zips on disk: backups/<id>/<file>.zip.
	 *
	 * @return array<string,array> Keyed by id, with inferred metadata.
	 */
	protected static function scan_disk() {
		$base  = Revertix_Storage::sub_dir( 'backups' );
		$found = array();

		if ( ! is_dir( $base ) ) {
			return $found;
		}

		$dirs = glob( $base . '*', GLOB_ONLYDIR );
		if ( ! is_array( $dirs ) ) {
			return $found;
		}

		foreach ( $dirs as $dir ) {
			$id   = basename( $dir );
			$zips = glob( trailingslashit( $dir ) . '*.zip' );
			if ( empty( $zips ) ) {
				continue;
			}
			$path = $zips[0];

			$found[ $id ] = array(
				'id'      => $id,
				'name'    => Revertix_Storage::untokenize( basename( $path ) ),
				'file'    => basename( $path ),
				'path'    => $path,
				'size'    => (int) filesize( $path ),
				'created' => (int) filemtime( $path ),
				'safety'  => false,
				'parts'   => array(
					'database' => true,
					'files'    => true,
				),
			);
		}

		return $found;
	}

	/**
	 * A single backup by id (reconciled with disk).
	 *
	 * @param string $id Backup id.
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( self::all() as $entry ) {
			if ( $entry['id'] === $id ) {
				return $entry;
			}
		}
		return null;
	}

	/**
	 * Add (or replace) a backup entry.
	 *
	 * @param array $entry Backup metadata (must include 'id').
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
	 * Delete a backup: remove its files (containment-guarded) and its entry.
	 *
	 * @param string $id Backup id.
	 * @return bool True if an entry was removed.
	 */
	public static function delete( $id ) {
		$id = preg_replace( '/[^a-zA-Z0-9\-]/', '', (string) $id );
		if ( '' === $id ) {
			return false;
		}

		// Remove the on-disk backup directory (containment-guarded).
		$base = Revertix_Storage::sub_dir( 'backups' );
		Revertix_Filesystem::delete_tree( $base . $id, $base );

		// Drop the registry entry if present (may be disk-only after a restore).
		$items = get_option( self::OPTION, array() );
		if ( is_array( $items ) && isset( $items[ $id ] ) ) {
			unset( $items[ $id ] );
			update_option( self::OPTION, $items, false );
		}

		return true;
	}

	/**
	 * Prune safety backups beyond the configured "keep last N" limit, oldest
	 * first. Only safety backups are considered/deleted - manual backups are never
	 * touched. Called right after a new safety backup is registered.
	 *
	 * @return int Number of safety backups deleted.
	 */
	public static function prune_safety() {
		$keep = Revertix_Settings::safety_keep();

		// Safety backups only, newest first (all() is already sorted that way).
		$safety = array_values(
			array_filter(
				self::all(),
				static function ( $entry ) {
					return ! empty( $entry['safety'] );
				}
			)
		);

		$deleted = 0;
		if ( count( $safety ) <= $keep ) {
			return $deleted;
		}

		foreach ( array_slice( $safety, $keep ) as $old ) {
			if ( self::delete( $old['id'] ) ) {
				++$deleted;
				Revertix_Log::info( 'Pruned old safety backup (retention).', array( 'backup' => $old['id'] ) );
			}
		}

		return $deleted;
	}

	/**
	 * The id of the most recent backup, or '' if there are none.
	 *
	 * @return string
	 */
	public static function latest_id() {
		$all = self::all();
		return empty( $all ) ? '' : (string) $all[0]['id'];
	}

	/**
	 * Whether a given backup may be restored on this install: any backup that
	 * still exists on disk.
	 *
	 * @param string $id Backup id.
	 * @return bool
	 */
	public static function can_restore( $id ) {
		return '' !== $id && null !== self::get( $id );
	}

	/**
	 * Whether a given backup may be downloaded on this install: any backup
	 * that still exists on disk.
	 *
	 * @param string $id Backup id.
	 * @return bool
	 */
	public static function can_download( $id ) {
		return '' !== $id && null !== self::get( $id );
	}

	/**
	 * Whether a given backup may be deleted on this install.
	 *
	 * Every backup EXCEPT the most recent one may be deleted, so the site
	 * always keeps at least one backup as a safety net. This is a safety rule
	 * that applies to everyone alike: to delete the newest backup, create a
	 * newer one first (or it becomes deletable once a newer backup exists).
	 *
	 * @param string $id Backup id.
	 * @return bool
	 */
	public static function can_delete( $id ) {
		return '' !== $id && $id !== self::latest_id();
	}

	/**
	 * A signed download URL for a backup.
	 *
	 * @param string $id Backup id.
	 * @return string
	 */
	public static function download_url( $id ) {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'revertix_download_backup',
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			'revertix_download_backup'
		);
	}
}
