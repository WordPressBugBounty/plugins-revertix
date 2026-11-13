<?php
/**
 * Filesystem helpers: a *resumable* directory walker and exclusion rules.
 *
 * For large sites we cannot list every file in one request, so the scanner is
 * driven by a directory stack stored in job state. Each call processes entries
 * until a time budget runs out, appending discovered files to an index file and
 * returning the updated stack so the next request can continue.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.WP.AlternativeFunctions -- direct filesystem calls are required for resumable, chunked backup/restore/import; WP_Filesystem cannot stream work across time-budgeted ticks.

/**
 * Stateless filesystem utilities operating relative to the web root (ABSPATH).
 */
class Revertix_Filesystem {

	/**
	 * The root of the site we export/clone - the WordPress install root.
	 *
	 * The single source of truth for the install root across the plugin. ABSPATH
	 * is the constant WordPress itself provides for this location (there is no
	 * function equivalent for the install root); everything else derives its
	 * paths from here or from plugin_dir_path()/wp_upload_dir().
	 *
	 * @return string Trailing-slashed absolute path.
	 */
	public static function root() {
		return trailingslashit( ABSPATH );
	}

	/**
	 * The plugins directory (with trailing slash), derived from this plugin's
	 * own location via plugin_dir_path() - our plugin lives directly inside
	 * it - so no WordPress internal constants are needed.
	 *
	 * @return string
	 */
	public static function plugins_dir() {
		return trailingslashit( dirname( untrailingslashit( REVERTIX_PLUGIN_DIR ) ) );
	}

	/**
	 * The content directory (with trailing slash), derived the same way: the
	 * plugins directory lives directly inside it.
	 *
	 * @return string
	 */
	public static function content_dir() {
		return trailingslashit( dirname( untrailingslashit( self::plugins_dir() ) ) );
	}

	/**
	 * Absolute paths that must never be packaged or copied. Returning real,
	 * normalised prefixes (trailing slash) lets is_excluded() do a fast compare.
	 *
	 * @return string[]
	 */
	public static function excluded_prefixes() {
		$root = self::root();

		$excludes = array(
			// Revertix's own working storage (prevents packaging packages).
			trailingslashit( Revertix_Storage::base_dir() ),
			// Common throwaway dirs.
			$root . 'wp-content/cache/',
			$root . 'wp-content/upgrade/',
			$root . 'wp-content/uploads/cache/',
			$root . '.revertix-core-new/',
			$root . '.revertix-core-old/',
		);

		/**
		 * Filter the list of excluded absolute path prefixes.
		 *
		 * @param string[] $excludes Trailing-slashed absolute prefixes.
		 */
		return apply_filters( 'revertix_excluded_prefixes', $excludes );
	}

	/**
	 * Directory/file names skipped wherever they appear in the tree: version
	 * control metadata and dependency/build folders. These are dev artifacts -
	 * large, full of read-only files, and useless on a restored/migrated site.
	 *
	 * @return string[] Lower-cased names compared against each path segment.
	 */
	public static function excluded_names() {
		/**
		 * Filter the path-segment names excluded everywhere (export + restore).
		 *
		 * @param string[] $names e.g. '.git', 'node_modules'.
		 */
		return apply_filters(
			'revertix_excluded_names',
			array(
				'.git',
				'.svn',
				'.hg',
				'.bzr',
				'node_modules',
				'.DS_Store',
				// Staging's login-protection mu-plugin: never package, copy, compare
				// or sync it - it must stay local to the staging site so it can't lock
				// the live site and isn't wiped by a pull.
				Revertix_Staging::PROTECT_MU,
			)
		);
	}

	/**
	 * Whether any segment of a relative path matches an excluded name.
	 *
	 * @param string $rel Relative path (forward slashes).
	 * @return bool
	 */
	public static function has_excluded_segment( $rel ) {
		$names = self::excluded_names();
		foreach ( explode( '/', trim( str_replace( '\\', '/', $rel ), '/' ) ) as $segment ) {
			if ( '' !== $segment && in_array( $segment, $names, true ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a path should be skipped.
	 *
	 * Skips Revertix storage, declared excludes, and any staging copy directory
	 * (revertix-staging-*) so a staging site is never packaged into itself.
	 *
	 * @param string   $abs_path Absolute filesystem path.
	 * @param string[] $extra    Additional absolute prefixes to exclude (e.g. file
	 *                           areas the user de-selected in Advanced export).
	 * @return bool
	 */
	public static function is_excluded( $abs_path, array $extra = array() ) {
		$abs_path = self::normalize( $abs_path );

		// Any Revertix staging directory at the web root, e.g. revertix-staging-ab12.
		$root = self::normalize( self::root() );
		if ( 0 === strpos( $abs_path, $root ) ) {
			$relative = ltrim( substr( $abs_path, strlen( $root ) ), '/' );
			if ( preg_match( '#^revertix-staging-[^/]+#', $relative ) ) {
				return true;
			}
		}

		$prefixes = array_merge( self::excluded_prefixes(), $extra );

		foreach ( $prefixes as $prefix ) {
			$prefix = self::normalize( $prefix );
			if ( '' === $prefix ) {
				continue;
			}
			if ( $abs_path === $prefix || 0 === strpos( $abs_path . '/', $prefix . '/' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalise a path to forward slashes without a trailing slash (except root).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	public static function normalize( $path ) {
		$path = str_replace( '\\', '/', $path );
		return rtrim( $path, '/' );
	}

	/**
	 * Translate a path that lives under $from_root into the same relative spot
	 * under $to_root.
	 *
	 * Used to turn the live-side scan roots into staging-side ones (and back).
	 * The $root === $from_root case matters: the PRO whole-root area resolves to
	 * ABSPATH itself, which a plain "starts with <from_root>/" test rejects and
	 * so silently yields an empty set of scan roots.
	 *
	 * @param string $root      Absolute path to translate.
	 * @param string $from_root Absolute root it currently lives under.
	 * @param string $to_root   Absolute root to move it to.
	 * @return string Rebased absolute path (no trailing slash), or '' if $root
	 *                is not inside $from_root at all.
	 */
	public static function rebase_root( $root, $from_root, $to_root ) {
		$norm = self::normalize( $root );
		$from = self::normalize( $from_root );
		$to   = self::normalize( $to_root );

		if ( '' === $norm || '' === $from ) {
			return '';
		}

		if ( $norm === $from ) {
			return $to;
		}

		if ( 0 === strpos( $norm, $from . '/' ) ) {
			return self::normalize( $to . '/' . ltrim( substr( $norm, strlen( $from ) ), '/' ) );
		}

		return '';
	}

	/**
	 * Process one chunk of a directory scan.
	 *
	 * @param array    $stack     Directory stack (absolute paths) yet to visit.
	 * @param resource $index_fh  Open file handle to append "relpath\tsize\n" lines.
	 * @param callable $time_left A callback returning remaining seconds (float).
	 * @param string[]      $extra     Additional absolute prefixes to exclude.
	 * @param string[]|null $names     Path-segment names to skip (null = defaults).
	 * @return array{stack:string[],files:int,bytes:int,done:bool}
	 *               Updated stack and counters for this chunk.
	 */
	public static function scan_step( array $stack, $index_fh, callable $time_left, array $extra = array(), $names = null ) {
		$root   = self::root();
		$files  = 0;
		$bytes  = 0;
		$names  = ( null === $names ) ? self::excluded_names() : $names;

		while ( ! empty( $stack ) && call_user_func( $time_left ) > 1.0 ) {
			$dir = array_pop( $stack );

			$handle = @opendir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( false === $handle ) {
				continue;
			}

			while ( false !== ( $entry = readdir( $handle ) ) ) {
				if ( '.' === $entry || '..' === $entry ) {
					continue;
				}

				// Skip VCS/dependency folders (.git, node_modules, …) by name.
				if ( in_array( $entry, $names, true ) ) {
					continue;
				}

				$abs = trailingslashit( $dir ) . $entry;

				if ( self::is_excluded( $abs, $extra ) ) {
					continue;
				}

				if ( is_link( $abs ) ) {
					// Skip symlinks to avoid escaping the tree / loops.
					continue;
				}

				if ( is_dir( $abs ) ) {
					$stack[] = $abs;
					continue;
				}

				if ( is_file( $abs ) && is_readable( $abs ) ) {
					$relative = ltrim( substr( self::normalize( $abs ), strlen( self::normalize( $root ) ) ), '/' );

					// wp-config.php belongs to the environment, never to the site:
					// it is never restored, copied or synced, so indexing it would
					// only put database credentials inside a package for nothing.
					if ( self::is_protected_relative( $relative ) ) {
						continue;
					}

					$size     = (int) filesize( $abs );
					fwrite( $index_fh, $relative . "\t" . $size . "\n" );
					++$files;
					$bytes += $size;
				}
			}

			closedir( $handle );
		}

		return array(
			'stack' => array_values( $stack ),
			'files' => $files,
			'bytes' => $bytes,
			'done'  => empty( $stack ),
		);
	}

	/**
	 * The selectable file "areas": key => [label, dir].
	 *
	 * The five wp-content areas are always available. The whole-root area (WP
	 * core, index.php, wp-load.php and anything else beside wp-content) depends
	 * on what the list is FOR:
	 *
	 *   - 'export' : always offered. A migration package that cannot carry the
	 *                WordPress files is only restorable into an install that
	 *                already has them, which is not what people expect from an
	 *                export.
	 *   - 'backup' : offered by the Revertix PRO add-on, which advertises
	 *                whole-root BACKUPS as one of its features.
	 *
	 * Backups and exports run through the same job, so before the context
	 * existed, gating the area for backups silently removed it from exports too.
	 *
	 * @param string $context 'export' or 'backup'.
	 * @return array<string,array{label:string,dir:string}>
	 */
	public static function file_areas( $context = 'backup' ) {
		$content = Revertix_Filesystem::content_dir();
		$uploads = wp_upload_dir();

		$areas = array(
			'uploads'    => array(
				'label' => __( 'Media library (uploads)', 'revertix' ),
				'dir'   => self::normalize( $uploads['basedir'] ),
			),
			'themes'     => array(
				'label' => __( 'Themes', 'revertix' ),
				'dir'   => self::normalize( $content . 'themes' ),
			),
			'mu-plugins' => array(
				'label' => __( 'Must-use plugins', 'revertix' ),
				'dir'   => self::normalize( $content . 'mu-plugins' ),
			),
			'plugins'    => array(
				'label' => __( 'Plugins', 'revertix' ),
				'dir'   => self::normalize( $content . 'plugins' ),
			),
			'wpcontent'  => array(
				'label' => __( 'Other files in wp-content', 'revertix' ),
				'dir'   => self::normalize( $content ),
			),
		);

		// WP core and everything else beside wp-content.
		if ( 'export' === $context ) {
			$areas['root'] = array(
				'label' => __( 'WordPress core and other root files', 'revertix' ),
				'dir'   => self::normalize( self::root() ),
			);
		}

		/**
		 * Filter the selectable file areas.
		 *
		 * @param array  $areas   key => array{label:string,dir:string}.
		 * @param string $context 'export' or 'backup'.
		 */
		return (array) apply_filters( 'revertix_backup_file_areas', $areas, $context );
	}

	/**
	 * Resolve selected area keys into concrete scan roots + extra exclusions.
	 *
	 * @param string[] $selected Area keys the user wants included.
	 * @return array{roots:string[],excludes:string[]}
	 */
	public static function resolve_areas( array $selected ) {
		$areas    = self::file_areas( 'export' );
		$selected = array_values( array_intersect( array_keys( $areas ), $selected ) );

		$roots    = array();
		$excludes = array();

		// The four discrete directories that have their own area.
		$subareas = array( 'uploads', 'themes', 'mu-plugins', 'plugins' );
		$content  = self::normalize( self::content_dir() );

		if ( in_array( 'root', $selected, true ) ) {
			$roots[] = self::normalize( self::root() );

			foreach ( $subareas as $key ) {
				if ( ! in_array( $key, $selected, true ) && isset( $areas[ $key ]['dir'] ) && is_dir( $areas[ $key ]['dir'] ) ) {
					$excludes[] = $areas[ $key ]['dir'];
				}
			}
			if ( ! in_array( 'wpcontent', $selected, true ) && is_dir( $content ) ) {
				$keep = array();
				foreach ( $subareas as $key ) {
					if ( in_array( $key, $selected, true ) && isset( $areas[ $key ]['dir'] ) ) {
						$keep[] = self::normalize( $areas[ $key ]['dir'] );
					}
				}

				foreach ( (array) glob( trailingslashit( $content ) . '*' ) as $child ) {
					$child = self::normalize( $child );
					if ( '' !== $child && ! in_array( $child, $keep, true ) ) {
						$excludes[] = $child;
					}
				}
			}
		} elseif ( in_array( 'wpcontent', $selected, true ) ) {
			// All of wp-content, minus any discrete sub-area not selected.
			$roots[] = $content;
			foreach ( $subareas as $key ) {
				if ( ! in_array( $key, $selected, true ) && is_dir( $areas[ $key ]['dir'] ) ) {
					$excludes[] = $areas[ $key ]['dir'];
				}
			}
		} else {
			// Scan only the selected discrete sub-areas as their own roots.
			foreach ( $subareas as $key ) {
				if ( in_array( $key, $selected, true ) && is_dir( $areas[ $key ]['dir'] ) ) {
					$roots[] = $areas[ $key ]['dir'];
				}
			}
		}

		$result = array(
			'roots'    => array_values( array_unique( $roots ) ),
			'excludes' => array_values( array_unique( $excludes ) ),
		);

		/**
		 * Filter the resolved scan roots/excludes. Add-ons that register extra
		 * areas via revertix_backup_file_areas resolve them here (e.g. the
		 * Revertix PRO add-on resolves its whole-root area).
		 *
		 * @param array    $result   array{roots:string[],excludes:string[]}.
		 * @param string[] $selected Selected area keys.
		 * @param array    $areas    All known areas.
		 */
		return (array) apply_filters( 'revertix_resolve_areas', $result, $selected, $areas );
	}

	/**
	 * Base directory of an item-pickable area ('plugins'|'themes'|'mu-plugins').
	 *
	 * @param string $area_key Area key.
	 * @return string Absolute path (no trailing slash), or '' if not pickable.
	 */
	public static function area_base_dir( $area_key ) {
		$content = Revertix_Filesystem::content_dir();

		switch ( $area_key ) {
			case 'plugins':
				return self::normalize( $content . 'plugins' );
			case 'themes':
				return self::normalize( $content . 'themes' );
			case 'mu-plugins':
				return self::normalize( $content . 'mu-plugins' );
		}

		return '';
	}

	/**
	 * List the individual items (plugins/themes) inside a pickable area.
	 *
	 * Plugins are top-level directories *and* single-file plugins (e.g. hello.php);
	 * themes are top-level directories. Returns slug => human label, sorted by
	 * label. Labels fall back to the slug when no metadata is available.
	 *
	 * @param string $area_key 'plugins' or 'themes'.
	 * @return array<string,string>
	 */
	public static function list_area_items( $area_key ) {
		$base = self::area_base_dir( $area_key );
		if ( '' === $base || ! is_dir( $base ) ) {
			return array();
		}

		$names = self::excluded_names();
		$items = array();

		$dh = @opendir( $base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $dh ) {
			return array();
		}

		while ( false !== ( $entry = readdir( $dh ) ) ) {
			if ( '.' === $entry || '..' === $entry || in_array( $entry, $names, true ) ) {
				continue;
			}

			$abs = $base . '/' . $entry;

			if ( 'plugins' === $area_key ) {
				if ( is_dir( $abs ) || preg_match( '/\.php$/i', $entry ) ) {
					$items[ $entry ] = $entry;
				}
			} elseif ( is_dir( $abs ) ) {
				$items[ $entry ] = $entry;
			}
		}
		closedir( $dh );

		$items = self::label_area_items( $area_key, $items );

		asort( $items, SORT_NATURAL | SORT_FLAG_CASE );
		return $items;
	}

	/**
	 * Replace item slugs with friendly names where WordPress can provide them.
	 *
	 * @param string                $area_key 'plugins' or 'themes'.
	 * @param array<string,string> $items    slug => slug.
	 * @return array<string,string> slug => label.
	 */
	protected static function label_area_items( $area_key, array $items ) {
		if ( 'themes' === $area_key && function_exists( 'wp_get_themes' ) ) {
			foreach ( wp_get_themes() as $slug => $theme ) {
				if ( isset( $items[ $slug ] ) ) {
					$items[ $slug ] = $theme->get( 'Name' ) ? $theme->get( 'Name' ) : $slug;
				}
			}
			return $items;
		}

		if ( 'plugins' === $area_key ) {
			if ( ! function_exists( 'get_plugins' ) && file_exists( ABSPATH . 'wp-admin/includes/plugin.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
			}
			if ( function_exists( 'get_plugins' ) ) {
				foreach ( get_plugins() as $file => $data ) {
					$top = explode( '/', $file )[0];
					if ( isset( $items[ $top ] ) && ! empty( $data['Name'] ) ) {
						$items[ $top ] = $data['Name'];
					}
				}
			}
		}

		return $items;
	}

	/**
	 * Absolute exclude prefixes for the items of an area that were NOT selected.
	 *
	 * @param string   $area_key 'plugins' or 'themes'.
	 * @param string[] $selected Slugs the user chose to keep.
	 * @return string[] Normalised absolute paths to exclude.
	 */
	public static function area_item_excludes( $area_key, array $selected ) {
		$base = self::area_base_dir( $area_key );
		if ( '' === $base ) {
			return array();
		}

		$excludes = array();
		foreach ( array_keys( self::list_area_items( $area_key ) ) as $slug ) {
			if ( ! in_array( $slug, $selected, true ) ) {
				$excludes[] = self::normalize( $base . '/' . $slug );
			}
		}

		return $excludes;
	}

	/**
	 * Whether a relative path should be skipped when restoring files on import.
	 *
	 * Protects the things that must not be overwritten from another site:
	 * wp-config.php (DB credentials), the running Revertix plugins, and Revertix's
	 * own working storage.
	 *
	 * @param string $rel Relative path (from the site root).
	 * @return bool
	 */
	/**
	 * Normalise a package-relative path to a safe, traversal-free form.
	 *
	 * Collapses "." and empty segments and REJECTS anything that tries to climb
	 * out with "..", or uses an absolute path, a Windows drive letter, a stream
	 * wrapper, or a null byte. Package metadata (files-index.txt) and archive
	 * entry names are attacker-controlled, so every path derived from a package
	 * must pass through here before it is turned into a real path on disk.
	 *
	 * @param string $rel Raw relative path from an untrusted package.
	 * @return string|false Clean relative path, or false when unsafe.
	 */
	public static function safe_relative( $rel ) {
		$rel = str_replace( '\\', '/', (string) $rel );

		if ( '' === $rel
			|| false !== strpos( $rel, "\0" )
			|| false !== strpos( $rel, '://' )
			|| preg_match( '#^[a-zA-Z]:#', $rel )
		) {
			return false;
		}

		$out = array();
		foreach ( explode( '/', ltrim( $rel, '/' ) ) as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				return false; // Any parent-directory climb is rejected outright.
			}
			$out[] = $segment;
		}

		return empty( $out ) ? false : implode( '/', $out );
	}

	public static function is_restore_excluded( $rel ) {
		// Unsafe / traversal paths are never restored (defense in depth: callers
		// should already have run the path through safe_relative()).
		$safe = self::safe_relative( $rel );
		if ( false === $safe ) {
			return true;
		}
		$rel = $safe;

		// VCS/dependency folders (.git, node_modules, …) - never restore them,
		// even if an older package still contains them.
		if ( self::has_excluded_segment( $rel ) ) {
			return true;
		}

		/**
		 * Filter the paths skipped during an import restore. Extends the shared
		 * protected list (which already covers wp-config.php) with the plugin's
		 * own directories, which a restore must not overwrite from under itself.
		 *
		 * @param string[] $skip Files (exact) and directory prefixes (trailing /).
		 */
		$skip = apply_filters(
			'revertix_restore_excludes',
			array_merge(
				self::protected_relatives(),
				array(
					'wp-content/plugins/revertix/',
					'wp-content/plugins/revertix-pro/',
					'wp-content/uploads/revertix/',
				)
			)
		);

		foreach ( $skip as $pattern ) {
			if ( '/' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $rel . '/', $pattern ) ) {
					return true;
				}
			} elseif ( $rel === $pattern ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Files that belong to the environment rather than to the site, and that
	 * Revertix therefore never moves between environments or restores.
	 *
	 * wp-config.php is the important one: each environment keeps its own DB
	 * credentials and - critically for staging - its own table prefix, so moving
	 * it would point one site at the other's tables.
	 *
	 * @return string[] Root-relative paths (forward slashes, no leading slash).
	 */
	public static function protected_relatives() {
		/**
		 * Filter the files Revertix never syncs or restores.
		 *
		 * @param string[] $paths Root-relative paths.
		 */
		return (array) apply_filters(
			'revertix_protected_relatives',
			array(
				'wp-config.php',
			)
		);
	}

	/**
	 * The WordPress core files: everything the release ships outside wp-content.
	 *
	 * @return string[] Root-relative paths; directories end with a slash.
	 */
	public static function core_relatives() {
		/**
		 * Filter the WordPress core paths left alone during a restore.
		 *
		 * @param string[] $paths Root-relative paths.
		 */
		return (array) apply_filters(
			'revertix_core_relatives',
			array(
				'wp-admin/',
				'wp-includes/',
				'index.php',
				'wp-activate.php',
				'wp-blog-header.php',
				'wp-comments-post.php',
				'wp-config-sample.php',
				'wp-cron.php',
				'wp-links-opml.php',
				'wp-load.php',
				'wp-login.php',
				'wp-mail.php',
				'wp-settings.php',
				'wp-signup.php',
				'wp-trackback.php',
				'xmlrpc.php',
				'license.txt',
				'readme.html',
			)
		);
	}

	/**
	 * Whether a root-relative path belongs to WordPress itself.
	 *
	 * @param string $rel Root-relative path (forward slashes).
	 * @return bool
	 */
	public static function is_core_relative( $rel ) {
		$rel = strtolower( ltrim( str_replace( '\\', '/', (string) $rel ), '/' ) );

		if ( '' === $rel ) {
			return false;
		}

		foreach ( self::core_relatives() as $pattern ) {
			$pattern = strtolower( $pattern );
			if ( '/' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $rel . '/', $pattern ) ) {
					return true;
				}
			} elseif ( $rel === $pattern ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a root-relative path is one Revertix never moves.
	 *
	 * @param string $rel Root-relative path (forward slashes).
	 * @return bool
	 */
	public static function is_protected_relative( $rel ) {
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' === $rel ) {
			return true;
		}

		// Compare case-insensitively: macOS and Windows resolve "WP-Config.php"
		// to the same file, so an exact match would let a differently-cased entry
		// through and overwrite the real wp-config.php.
		$rel = strtolower( $rel );

		foreach ( self::protected_relatives() as $pattern ) {
			$pattern = strtolower( $pattern );
			if ( '/' === substr( $pattern, -1 ) ) {
				if ( 0 === strpos( $rel . '/', $pattern ) ) {
					return true;
				}
			} elseif ( $rel === $pattern ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Strip the environment-specific secrets out of a wp-config.php so a copy of
	 * it can safely travel inside a downloadable package.
	 *
	 * @param string $config wp-config.php contents.
	 * @return string
	 */
	public static function sanitize_wp_config( $config ) {
		$config = (string) $config;

		$placeholders = array(
			'DB_NAME'     => 'database_name_here',
			'DB_USER'     => 'username_here',
			'DB_PASSWORD' => 'password_here',
			'DB_HOST'     => 'localhost',
		);

		foreach ( $placeholders as $const => $value ) {
			$config = self::replace_config_literal( $config, $const, $value );
		}

		// Fresh salts: a package must not carry the live site's session secrets.
		$keys = array(
			'AUTH_KEY',
			'SECURE_AUTH_KEY',
			'LOGGED_IN_KEY',
			'NONCE_KEY',
			'AUTH_SALT',
			'SECURE_AUTH_SALT',
			'LOGGED_IN_SALT',
			'NONCE_SALT',
			// Not part of core's generated block, but plugins set it and it is
			// still a secret.
			'WP_CACHE_KEY_SALT',
		);

		foreach ( $keys as $key ) {
			$config = self::replace_config_literal( $config, $key, wp_generate_password( 64, true, true ) );
		}

		$note = "<?php\n// [revertix] Reference copy. Database credentials and security keys were\n"
			. "// removed for safety - fill in the values for THIS environment (site\n"
			. "// setup tools such as Local do it for you). Nothing else was changed.\n";

		$out = preg_replace( '/^<\?php/', str_replace( '$', '\\$', $note ), $config, 1 );

		return ( null === $out ) ? $config : $out;
	}

	/**
	 * Replace the literal string value of one define() in a wp-config.php.
	 *
	 * @param string $config wp-config.php contents.
	 * @param string $const  Constant name.
	 * @param string $value  Replacement value.
	 * @return string
	 */
	protected static function replace_config_literal( $config, $const, $value ) {
		$pattern = '/^([ \t]*(?i:define)\s*\(\s*([\'"])' . preg_quote( $const, '/' ) . '\2\s*,\s*)'
			. '([\'"])(?:\\\\.|(?!\3).)*\3(\s*\)\s*;)/m';

		$replacement = "'" . addcslashes( $value, "'\\" ) . "'";

		$out = preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $replacement ) {
				return $m[1] . $replacement . $m[4];
			},
			$config,
			1
		);

		return ( null === $out ) ? $config : $out;
	}

	/**
	 * Send a file to the browser as a download.
	 *
	 * @param string $path     Absolute path to an existing, readable file.
	 * @param string $filename Name to offer it under.
	 */
	public static function stream_download( $path, $filename ) {
		$size = (int) filesize( $path );

		$start = 0;
		$end   = $size - 1;

		$range = isset( $_SERVER['HTTP_RANGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_RANGE'] ) ) : '';
		if ( '' !== $range && preg_match( '/^bytes=(\d*)-(\d*)$/', $range, $m ) ) {
			$from = ( '' !== $m[1] ) ? (int) $m[1] : null;
			$to   = ( '' !== $m[2] ) ? (int) $m[2] : null;

			if ( null === $from && null !== $to ) {
				// "last N bytes"
				$start = max( 0, $size - $to );
			} elseif ( null !== $from ) {
				$start = $from;
				if ( null !== $to ) {
					$end = min( $to, $size - 1 );
				}
			}

			if ( $start > $end || $start >= $size ) {
				status_header( 416 );
				header( 'Content-Range: bytes */' . $size );
				exit;
			}

			status_header( 206 );
			header( 'Content-Range: bytes ' . $start . '-' . $end . '/' . $size );
		}

		$filename = preg_replace( '/[^A-Za-z0-9._-]/', '-', (string) $filename );
		if ( '' === $filename ) {
			$filename = 'download.zip';
		}

		nocache_headers();
		header( 'Content-Type: application/zip' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Accept-Ranges: bytes' );
		header( 'Content-Length: ' . ( $end - $start + 1 ) );

		// Buffers hold the whole file in memory otherwise.
		while ( ob_get_level() ) {
			ob_end_clean();
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		$handle = fopen( $path, 'rb' );
		if ( false === $handle ) {
			status_header( 500 );
			exit;
		}

		fseek( $handle, $start );
		$remaining = $end - $start + 1;

		while ( $remaining > 0 && ! feof( $handle ) ) {
			$chunk = fread( $handle, (int) min( 1048576, $remaining ) );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary file body.
			flush();
			$remaining -= strlen( $chunk );
		}

		fclose( $handle );
		exit;
	}

	/**
	 * Permissions for a folder Revertix creates inside the site.
	 *
	 * wp_mkdir_p() gives a new folder the permissions of its parent. On cPanel the
	 * site root is often 0750 with the web server's group, which a new folder does
	 * not inherit - so a fresh wp-admin at 0750 answers every request with a 403.
	 * WordPress avoids this by never going below 0755 (FS_CHMOD_DIR in
	 * wp-admin/includes/file.php), and so does this.
	 *
	 * @return int
	 */
	public static function dir_perms() {
		static $perms = null;

		if ( null === $perms ) {
			if ( defined( 'FS_CHMOD_DIR' ) ) {
				$perms = (int) FS_CHMOD_DIR;
			} else {
				$base  = @fileperms( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$perms = ( false === $base ) ? 0755 : ( ( $base & 0777 ) | 0755 );
			}
		}

		return $perms;
	}

	/**
	 * Permissions for a file Revertix writes inside the site (FS_CHMOD_FILE rule).
	 *
	 * @return int
	 */
	public static function file_perms() {
		static $perms = null;

		if ( null === $perms ) {
			if ( defined( 'FS_CHMOD_FILE' ) ) {
				$perms = (int) FS_CHMOD_FILE;
			} else {
				$base  = @fileperms( ABSPATH . 'index.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				$perms = ( false === $base ) ? 0644 : ( ( $base & 0777 ) | 0644 );
			}
		}

		return $perms;
	}

	/**
	 * Create a folder and any missing parents, with dir_perms() on each new one.
	 *
	 * Only the folders this call creates are chmod-ed; folders that already
	 * existed keep whatever permissions their owner gave them.
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	public static function mkdir_p( $dir ) {
		$dir = rtrim( $dir, '/\\' );

		if ( '' === $dir || is_dir( $dir ) ) {
			return '' !== $dir;
		}

		// Walk up to the first folder that exists, remembering what is missing.
		$missing = array();
		$path    = $dir;
		while ( '' !== $path && ! is_dir( $path ) ) {
			$missing[] = $path;
			$parent    = dirname( $path );
			if ( $parent === $path ) {
				break;
			}
			$path = $parent;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}

		$perms = self::dir_perms();
		foreach ( array_reverse( $missing ) as $created ) {
			@chmod( $created, $perms ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
		}

		return true;
	}

	/**
	 * Copy a single file, creating its parent directory. Overwrites the target.
	 *
	 * New folders get dir_perms() and the file gets file_perms(), as core's
	 * copy_dir() does, so whatever Revertix puts in the site is reachable by the
	 * web server regardless of how strict the site root is.
	 *
	 * @param string $source Absolute source path.
	 * @param string $dest   Absolute destination path.
	 * @return bool
	 */
	public static function copy_file( $source, $dest ) {
		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			self::mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( @copy( $source, $dest ) ) {
			@chmod( $dest, self::file_perms() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
			return true;
		}

		// The target may already exist and be read-only (e.g. an existing file or
		// a leftover .git object at 0444). Make it writable and retry.
		if ( file_exists( $dest ) ) {
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			@chmod( $dest, self::file_perms() );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( @copy( $source, $dest ) ) {
				return true;
			}
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $dest );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors
			if ( @copy( $source, $dest ) ) {
				@chmod( $dest, self::file_perms() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
				return true;
			}
		}

		return false;
	}

	/**
	 * Recursively delete a directory. Guarded so it refuses to operate outside a
	 * required base path - callers must pass the base the target must live under.
	 *
	 * @param string $target    Absolute path to delete.
	 * @param string $must_be_in Absolute base path the target must be contained in.
	 * @return bool True on success.
	 */
	public static function delete_tree( $target, $must_be_in ) {
		$target_real = realpath( $target );
		$base_real   = realpath( $must_be_in );

		if ( ! $target_real || ! $base_real ) {
			return false;
		}

		$target_real = self::normalize( $target_real );
		$base_real   = self::normalize( $base_real );

		// Containment guard: target must be strictly inside the base.
		if ( 0 !== strpos( $target_real . '/', $base_real . '/' ) || $target_real === $base_real ) {
			return false;
		}

		try {
			$items = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $target_real, FilesystemIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::CHILD_FIRST
			);

			foreach ( $items as $item ) {
				$path = $item->getPathname();
				if ( $item->isDir() ) {
					@chmod( $path, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				} else {
					// Make read-only files (e.g. cloned .git objects) deletable.
					@chmod( $path, 0666 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
					@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		} catch ( Exception $e ) {
			Revertix_Log::warning(
				'Could not fully remove a directory.',
				array(
					'dir'    => $target_real,
					'reason' => $e->getMessage(),
				)
			);
		}

		@chmod( $target_real, 0777 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return @rmdir( $target_real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
}
