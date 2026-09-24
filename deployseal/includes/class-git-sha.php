<?php
/**
 * Reads and validates a commit SHA written by a deploy pipeline (contract §4 rule 1).
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * First non-empty line, first token, lower-cased, 7 to 40 hex characters.
 */
final class Git_Sha {

	/**
	 * Normalises file content into a SHA, or null.
	 *
	 * @param string|null $content File content.
	 * @return string|null
	 */
	public static function parse( $content ) {
		foreach ( preg_split( '/\r?\n/', (string) $content ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$tokens = preg_split( '/\s+/', $line );
			$token  = strtolower( (string) $tokens[0] );
			return preg_match( '/^[0-9a-f]{7,40}$/', $token ) ? $token : null;
		}
		return null;
	}

	/**
	 * Absolute path of a configured SHA file: relative paths resolve from the WordPress root (ABSPATH).
	 *
	 * @param string|null $path Configured path.
	 * @return string Empty when no path is configured.
	 */
	public static function resolve_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}
		if ( path_is_absolute( $path ) ) {
			return wp_normalize_path( $path );
		}
		return wp_normalize_path( trailingslashit( ABSPATH ) . ltrim( $path, '/\\' ) );
	}

	/**
	 * Reads and parses a SHA file; null when the path is empty, missing or malformed. Never throws.
	 *
	 * @param string $absolute_path Absolute path.
	 * @return string|null
	 */
	public static function try_read_file( $absolute_path ) {
		if ( '' === $absolute_path || ! is_file( $absolute_path ) || ! is_readable( $absolute_path ) ) {
			return null;
		}
		// A small local file the site owner configured, never a URL.
		$content = file_get_contents( $absolute_path, false, null, 0, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return false === $content ? null : self::parse( $content );
	}

	/**
	 * The first 7 characters, the form used in "6.8.3+twentytwentyfive-1.3+a1b2c3d".
	 *
	 * @param string $sha SHA.
	 * @return string
	 */
	public static function short( $sha ) {
		return substr( $sha, 0, Contract::SHORT_SHA_LENGTH );
	}
}
