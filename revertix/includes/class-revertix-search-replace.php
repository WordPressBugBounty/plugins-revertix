<?php
/**
 * Serialize-safe recursive search & replace.
 *
 * Naively running str_replace over a database breaks PHP serialized data,
 * because serialized strings embed their own byte length (e.g. s:5:"hello").
 * Change "hello" to "hi" and the length prefix is wrong, corrupting the value.
 *
 * This class walks values recursively: when it meets a serialized string it
 * unserializes, replaces inside the live structure, then re-serializes - so the
 * length prefixes are always recomputed correctly. Arrays and objects are
 * traversed; plain strings get a normal replace.
 *
 * @package Revertix
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recursive, serialization-aware replacer.
 */
class Revertix_Search_Replace {

	/**
	 * Replace across a value, preserving serialized structures.
	 *
	 * @param mixed    $data       The value (string, array, object, scalar).
	 * @param string[] $search     Search strings.
	 * @param string[] $replace    Replacement strings (parallel to $search).
	 * @param bool     $serialised Internal: whether $data came from unserialize().
	 * @return mixed The replaced value (re-serialized if $serialised).
	 */
	public static function apply( $data, array $search, array $replace, $serialised = false ) {
		try {
			if ( is_string( $data ) && '' !== $data && is_serialized( $data ) ) {
				// Re-serialize after replacing inside the live structure.
				$unserialized = @unserialize( $data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				// is_serialized() already vetted this; guard the rare false case.
				if ( false !== $unserialized || 'b:0;' === trim( $data ) ) {
					$data = self::apply( $unserialized, $search, $replace, true );
				} else {
					$data = str_replace( $search, $replace, $data );
				}
			} elseif ( is_array( $data ) ) {
				$result = array();
				foreach ( $data as $key => $value ) {
					// Keys can contain the search term too (rare, but correct).
					$new_key            = is_string( $key ) ? str_replace( $search, $replace, $key ) : $key;
					$result[ $new_key ] = self::apply( $value, $search, $replace, false );
				}
				$data = $result;
			} elseif ( is_object( $data ) ) {
				if ( $data instanceof __PHP_Incomplete_Class ) {
					// Unknown class - don't touch; re-serialize as-is below.
					return $serialised ? serialize( $data ) : $data;
				}
				$clone = clone $data;
				foreach ( get_object_vars( $clone ) as $key => $value ) {
					$clone->$key = self::apply( $value, $search, $replace, false );
				}
				$data = $clone;
			} elseif ( is_string( $data ) ) {
				$data = str_replace( $search, $replace, $data );
			}

			if ( $serialised ) {
				return serialize( $data );
			}
		} catch ( Exception $e ) {
			// On any failure, leave the value untouched to avoid corruption.
			return $serialised ? serialize( $data ) : $data;
		}

		return $data;
	}

	/**
	 * Build a from→to replacement pair list and sort by descending length so
	 * longer, more specific strings are replaced before shorter ones (e.g. a
	 * full site_url before a bare host).
	 *
	 * @param array<string,string> $map Associative from => to.
	 * @return array{0:string[],1:string[]} [search[], replace[]].
	 */
	public static function prepare_map( array $map ) {
		// Drop no-op and empty entries.
		$pairs = array();
		foreach ( $map as $from => $to ) {
			$from = (string) $from;
			$to   = (string) $to;
			if ( '' === $from || $from === $to ) {
				continue;
			}
			$pairs[ $from ] = $to;
		}

		// Longest search strings first.
		uksort(
			$pairs,
			static function ( $a, $b ) {
				return strlen( $b ) <=> strlen( $a );
			}
		);

		return array( array_keys( $pairs ), array_values( $pairs ) );
	}
}
