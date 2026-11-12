<?php
/**
 * Thin ZipArchive wrapper for incremental, memory-safe archiving.
 *
 * Adding thousands of files in a single open/close keeps them all buffered in
 * memory. Instead we open, add a batch, then close (which flushes to disk),
 * reopening for the next batch. This keeps memory flat on large sites.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Incremental zip writer/reader.
 */
class Revertix_Zip {

	/**
	 * Open a zip for appending (creating it if missing).
	 *
	 * @param string $path Absolute zip path.
	 * @return ZipArchive
	 * @throws RuntimeException If ZipArchive is missing or the file cannot open.
	 */
	public static function open_for_write( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( esc_html__( 'ZipArchive is not available on this server.', 'revertix' ) );
		}

		$zip  = new ZipArchive();
		$code = $zip->open( $path, ZipArchive::CREATE );

		if ( true !== $code ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %d: ZipArchive error code. */
						__( 'Could not open the zip archive for writing (error %d).', 'revertix' ),
						$code
					)
				)
			);
		}

		return $zip;
	}

	/**
	 * Add a single string as a file entry inside its own open/close cycle.
	 *
	 * @param string $path        Absolute zip path.
	 * @param string $entry_name  Name inside the archive.
	 * @param string $contents    File contents.
	 * @throws RuntimeException On failure.
	 */
	public static function add_string( $path, $entry_name, $contents ) {
		$zip = self::open_for_write( $path );
		$zip->addFromString( $entry_name, $contents );
		if ( ! $zip->close() ) {
			throw new RuntimeException(
				esc_html__( 'Could not write to the archive. The disk may be full or the storage directory may not be writable.', 'revertix' )
			);
		}
	}

	/**
	 * Add a batch of real files, each as files/<relative>, in one open/close.
	 *
	 * @param string                $path  Absolute zip path.
	 * @param array<int,array{abs:string,entry:string}> $items Files to add.
	 * @return int Number of entries actually added.
	 * @throws RuntimeException On failure.
	 */
	public static function add_files( $path, array $items ) {
		if ( empty( $items ) ) {
			return 0;
		}

		$zip   = self::open_for_write( $path );
		$added = 0;

		foreach ( $items as $item ) {
			if ( ! is_file( $item['abs'] ) || ! is_readable( $item['abs'] ) ) {
				continue;
			}

			if ( ! $zip->addFile( $item['abs'], $item['entry'] ) ) {
				$zip->close();
				throw new RuntimeException(
					esc_html(
						sprintf(
							/* translators: %s: file path. */
							__( 'Could not add %s to the archive.', 'revertix' ),
							$item['entry']
						)
					)
				);
			}

			// Storing already-compressed data (images, video, audio, fonts,
			// archives) costs a lot of CPU for no gain - measurably ~17x slower
			// for a byte-identical result - and that time is what makes a big
			// uploads folder overrun the per-request budget.
			if ( self::is_precompressed( $item['entry'] ) ) {
				$zip->setCompressionName( $item['entry'], ZipArchive::CM_STORE );
			}

			++$added;
		}

		// close() is where libzip actually writes. A false here means the archive
		// did not receive this batch - usually a full disk. Letting it pass would
		// silently produce a package missing files while reporting success.
		if ( ! $zip->close() ) {
			throw new RuntimeException(
				esc_html__( 'Could not write to the archive. The disk may be full or the storage directory may not be writable.', 'revertix' )
			);
		}

		return $added;
	}

	/**
	 * Whether a file is already compressed, so deflating it again is wasted work.
	 *
	 * @param string $name File name or archive entry.
	 * @return bool
	 */
	public static function is_precompressed( $name ) {
		$ext = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $ext ) {
			return false;
		}

		/**
		 * Filter the extensions stored without compression.
		 *
		 * @param string[] $extensions Lower-case extensions, no leading dot.
		 */
		$extensions = (array) apply_filters(
			'revertix_precompressed_extensions',
			array(
				// Images.
				'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'ico',
				// Video.
				'mp4', 'm4v', 'mov', 'avi', 'mkv', 'webm', 'ogv', 'wmv', 'flv',
				// Audio.
				'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'wma',
				// Archives and packages.
				'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst',
				// Fonts (woff/woff2 are already deflated/brotli internally).
				'woff', 'woff2',
			)
		);

		return in_array( $ext, $extensions, true );
	}

	/**
	 * Extract a batch of entries by index. Resumable: the caller passes the
	 * starting index and receives the next index + total entry count.
	 *
	 * Entries with path traversal ("..") or absolute paths are skipped for safety.
	 *
	 * @param string $path       Absolute zip path.
	 * @param string $dest       Destination directory.
	 * @param int    $from_index First entry index to extract.
	 * @param int    $count      Max entries to extract this batch.
	 * @return array{total:int,next:int}
	 * @throws RuntimeException On failure.
	 */
	public static function extract_batch( $path, $dest, $from_index, $count ) {
		$zip   = self::open_for_read( $path );
		$total = $zip->numFiles;

		$names = array();
		$end   = min( $total, $from_index + $count );

		for ( $i = $from_index; $i < $end; $i++ ) {
			$name = $zip->getNameIndex( $i );
			if ( false === $name ) {
				continue;
			}

			// Reject traversal / absolute / drive-letter / stream-wrapper entry
			// names. Validate a normalised copy, but extract the ORIGINAL name so
			// it still matches the real zip entry (incl. trailing-slash dir names).
			if ( false === Revertix_Filesystem::safe_relative( $name ) ) {
				continue;
			}

			$names[] = $name;
		}

		if ( ! empty( $names ) ) {
			$zip->extractTo( $dest, $names );
		}

		$zip->close();

		return array(
			'total' => $total,
			'next'  => $end,
		);
	}

	/**
	 * Whether a zip contains a Revertix manifest (quick validation of a package).
	 *
	 * @param string $path Absolute zip path.
	 * @return bool
	 */
	public static function has_manifest( $path ) {
		try {
			$zip = self::open_for_read( $path );
		} catch ( RuntimeException $e ) {
			return false;
		}

		$found = false !== $zip->locateName( 'manifest.json' );
		$zip->close();

		return $found;
	}

	/**
	 * Open a zip for reading.
	 *
	 * @param string $path Absolute zip path.
	 * @return ZipArchive
	 * @throws RuntimeException On failure.
	 */
	public static function open_for_read( $path ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			throw new RuntimeException( esc_html__( 'ZipArchive is not available on this server.', 'revertix' ) );
		}

		$zip  = new ZipArchive();
		$code = $zip->open( $path );

		if ( true !== $code ) {
			throw new RuntimeException(
				esc_html(
					sprintf(
						/* translators: %d: ZipArchive error code. */
						__( 'Could not open the package for reading (error %d).', 'revertix' ),
						$code
					)
				)
			);
		}

		return $zip;
	}
}
