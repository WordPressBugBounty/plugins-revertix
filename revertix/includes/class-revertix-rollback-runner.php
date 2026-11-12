<?php
/**
 * Executes plugin/theme rollbacks.
 *
 * Rollback runs through the standard WordPress upgrader (Plugin_Upgrader /
 * Theme_Upgrader) exactly like a normal update, but pointed at an older package
 * (a wordpress.org version zip, or - via the separate Revertix PRO add-on - a
 * local snapshot zip). We inject a fake "update available" entry for the one
 * item and call upgrade(), so WP handles deactivation, maintenance mode,
 * replacing files and re-activation safely.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rollback file operations + item-path helpers.
 */
class Revertix_Rollback_Runner {

	/* ---------------------------------------------------------------------
	 * Item folder resolution
	 * ------------------------------------------------------------------- */

	/**
	 * Absolute folder of an installed item (or '' if it is a single-file plugin /
	 * cannot be resolved to a folder).
	 *
	 * @param string $type 'plugin' | 'theme'.
	 * @param string $id   Plugin file or theme stylesheet.
	 * @return string
	 */
	public static function item_dir( $type, $id ) {
		if ( 'theme' === $type ) {
			$root = get_theme_root( $id );
			return trailingslashit( $root ) . $id;
		}

		$id = str_replace( '\\', '/', $id );
		if ( false === strpos( $id, '/' ) ) {
			return ''; // Single-file plugin: no folder to snapshot/rollback by folder.
		}
		return Revertix_Filesystem::plugins_dir() . dirname( $id );
	}

	/**
	 * The folder name the item lives in (the zip's expected top-level directory).
	 *
	 * @param string $type 'plugin' | 'theme'.
	 * @param string $id   Item id.
	 * @return string
	 */
	public static function item_folder_name( $type, $id ) {
		if ( 'theme' === $type ) {
			return $id;
		}
		$id = str_replace( '\\', '/', $id );
		return ( false !== strpos( $id, '/' ) ) ? dirname( $id ) : $id;
	}

	/* ---------------------------------------------------------------------
	 * Rollback (via the WP upgrader)
	 * ------------------------------------------------------------------- */

	/**
	 * Roll an item back to a package (a wordpress.org version zip URL or a local
	 * snapshot zip path).
	 *
	 * @param string $type    'plugin' | 'theme'.
	 * @param string $id      Item id.
	 * @param string $slug    Item slug.
	 * @param string $version Target version label.
	 * @param string $package Package URL or local zip path.
	 * @return array{ok:bool,message:string}
	 */
	public static function rollback( $type, $id, $slug, $version, $package ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Remember whether an active plugin was active, so we can re-activate it if
		// the rollback leaves it deactivated.
		$was_active = ( 'plugin' === $type ) && is_plugin_active( $id );

// Roll back by running the WordPress upgrader directly against our chosen
			// package (a wordpress.org version zip or a local snapshot). We do NOT touch
			// the update transient - this is a rollback of an item to a package we already
			// have, not a self-update mechanism. Both package types carry the item's
			// correct folder name at the archive root, so clear_destination replaces
			// exactly this item's folder. An active plugin is deactivated first and
			// restored afterwards (below), mirroring a normal update.
			unset( $slug ); // Not needed for the direct-package path.

			if ( $was_active ) {
				deactivate_plugins( $id, true );
			}

			$skin = new Automatic_Upgrader_Skin();

			if ( 'theme' === $type ) {
				$upgrader = new Theme_Upgrader( $skin );
				$result   = $upgrader->run(
					array(
						'package'                     => $package,
						'destination'                 => get_theme_root( $id ),
						'clear_destination'           => true,
						'clear_working'               => true,
						'abort_if_destination_exists' => false,
						'hook_extra'                  => array(
							'theme'  => $id,
							'type'   => 'theme',
							'action' => 'update',
						),
					)
				);
			} else {
				$upgrader = new Plugin_Upgrader( $skin );
				$result   = $upgrader->run(
					array(
						'package'                     => $package,
						'destination'                 => untrailingslashit( Revertix_Filesystem::plugins_dir() ),
						'clear_destination'           => true,
						'clear_working'               => true,
						'abort_if_destination_exists' => false,
						'hook_extra'                  => array(
							'plugin' => $id,
							'type'   => 'plugin',
							'action' => 'update',
						),
					)
				);
			}

		// Refresh caches so the new version shows immediately.
		wp_clean_plugins_cache();
		wp_clean_themes_cache();

		$messages = method_exists( $skin, 'get_upgrade_messages' ) ? (array) $skin->get_upgrade_messages() : array();

		// Collect the most specific failure detail available.
		$error = '';
		if ( is_wp_error( $result ) ) {
			$error = $result->get_error_message();
		} elseif ( isset( $upgrader->result ) && is_wp_error( $upgrader->result ) ) {
			$error = $upgrader->result->get_error_message();
		} elseif ( false === $result && ! empty( $messages ) ) {
			$error = (string) end( $messages );
		}

		if ( is_wp_error( $result ) || false === $result || ( isset( $upgrader->result ) && is_wp_error( $upgrader->result ) ) ) {
			Revertix_Log::error(
				'Rollback failed.',
				array(
					'type'     => $type,
					'item'     => $id,
					'version'  => $version,
					'package'  => $package,
					'detail'   => $error,
					'messages' => $messages,
				)
			);
			return array(
				'ok'      => false,
				'message' => '' !== $error ? wp_strip_all_tags( $error ) : __( 'The rollback could not be completed. See the Logs tab for details.', 'revertix' ),
			);
		}

		// Re-activate the plugin if it was active before but the rollback left it
		// off (some plugins/upgrade paths drop the active state).
		if ( $was_active && ! is_plugin_active( $id ) ) {
			$activated = activate_plugin( $id );
			if ( is_wp_error( $activated ) ) {
				Revertix_Log::warning(
					'Rollback: could not re-activate plugin.',
					array( 'item' => $id, 'error' => $activated->get_error_message() )
				);
			}
		}

		Revertix_Log::info(
			'Rollback applied.',
			array( 'type' => $type, 'item' => $id, 'version' => $version )
		);

		return array(
			'ok'      => true,
			'message' => sprintf(
				/* translators: %s: version rolled back to. */
				__( 'Rolled back to version %s.', 'revertix' ),
				$version
			),
		);
	}
}
