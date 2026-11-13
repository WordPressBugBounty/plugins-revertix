<?php
/**
 * Uninstall cleanup. Runs only when the user deletes the plugin.
 *
 * Removes EVERYTHING Revertix created so nothing is left behind on the site:
 *   - all Revertix options
 *   - the scheduled prune cron event
 *   - every staging site (its cloned <prefix>stg..._ tables AND its web-root dir)
 *   - the working storage directory (uploads/revertix)
 *
 * This file is intentionally self-contained — WordPress does not load the plugin
 * during uninstall, so it must not rely on Revertix's classes.
 *
 * @package Revertix
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- one-off uninstall cleanup that drops orphaned staging tables. Identifiers are bound with the %i placeholder and values with %s via $wpdb->prepare().
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- variables here are local to this template/scope (included within a method), not true globals.

global $wpdb;

/*
 * The scheduled prune cron is always cleared (harmless, and orphaned events are
 * pointless). Everything else only runs when the user opted in via
 * Settings → "Remove data on uninstall?" - which is OFF by default, so deleting
 * the plugin leaves staging sites, backups and settings untouched.
 */
wp_clear_scheduled_hook( 'revertix_prune_log' );

$revertix_settings = get_option( 'revertix_settings', array() );
if ( ! is_array( $revertix_settings ) || empty( $revertix_settings['remove_data_on_uninstall'] ) ) {
	return;
}

/**
 * Recursively delete a directory, making read-only files removable first.
 *
 * @param string $dir Absolute directory path.
 */
function revertix_uninstall_rmdir( $dir ) {
	if ( '' === $dir || ! is_dir( $dir ) ) {
		return;
	}

	try {
		$items = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $items as $item ) {
			$path = $item->getPathname();
			if ( $item->isDir() ) {
				@chmod( $path, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			} else {
				@chmod( $path, 0666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			}
		}
	} catch ( Exception $e ) {
		// Best-effort cleanup; ignore unreadable entries.
		unset( $e );
	}

	@chmod( $dir, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

/*
 * 1. Drop every staging table: <prefix>stg<slug>_*. The pattern is specific to
 *    Revertix, so ordinary site tables are never touched. This also catches any
 *    orphaned staging tables whose registry entry was lost.
 */
if ( is_object( $wpdb ) ) {
	$prefix = $wpdb->prefix;
	$like   = $wpdb->esc_like( $prefix . 'stg' ) . '%';
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	$tables = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $like ) );

	$pattern = '#^' . preg_quote( $prefix, '#' ) . 'stg[0-9a-z]+_#i';
	foreach ( (array) $tables as $table ) {
		if ( preg_match( $pattern, $table ) ) {
			$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $table ) );
		}
	}
}

/*
 * 2. Remove staging directories (from the registry), guarded to the web root so
 *    nothing outside the install is ever deleted.
 */
$web_root = rtrim( str_replace( '\\', '/', ABSPATH ), '/' );
$staging  = get_option( 'revertix_staging_sites', array() );

if ( is_array( $staging ) ) {
	foreach ( $staging as $site ) {
		if ( empty( $site['path'] ) ) {
			continue;
		}
		$real = realpath( $site['path'] );
		if ( ! $real ) {
			continue;
		}
		$real_norm = rtrim( str_replace( '\\', '/', $real ), '/' );

		// Must be strictly inside the web root.
		if ( $real_norm !== $web_root && 0 === strpos( $real_norm . '/', $web_root . '/' ) ) {
			revertix_uninstall_rmdir( $real );
		}
	}
}

/*
 * 3. Delete all Revertix options.
 */
$revertix_options = array(
	'revertix_version',
	'revertix_storage_token',
	'revertix_packages',
	'revertix_backups',
	'revertix_staging_sites',
	'revertix_log_settings',
	'revertix_settings',
	'revertix_rollback_snapshots',
	'revertix_storage_notice_dismissed',
	'revertix_shares',
	'revertix_import_running',
	'revertix_core_pin',
);
delete_transient( 'revertix_core_versions1' );
foreach ( $revertix_options as $revertix_option ) {
	delete_option( $revertix_option );
}

/*
 * 5. Remove the working storage directory (uploads/revertix) — packages, backups,
 *    logs, tmp scratch and the import staging area all live under it.
 */
$uploads = wp_upload_dir();
revertix_uninstall_rmdir( trailingslashit( $uploads['basedir'] ) . 'revertix' );
