<?php
/**
 * The exact Origin values a browser will send for this site (contract §3).
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Exact match: scheme://host[:port], lower-cased, default port dropped, no path.
 */
final class Origins {

	/**
	 * Formats one URL as an origin, or null when it is not an absolute http(s) URL.
	 *
	 * @param string|null $url URL.
	 * @return string|null
	 */
	public static function from_url( $url ) {
		$parts = wp_parse_url( trim( (string) $url ) );
		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}
		$host = strtolower( $parts['host'] );
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = null;
		}
		return $scheme . '://' . $host . ( null === $port ? '' : ':' . $port );
	}

	/**
	 * Every origin this site answers on, in the order to read them: the Site Address (home_url)
	 * first, then the WordPress Address (site_url) when it differs. Distinct, nulls dropped.
	 *
	 * @return string[]
	 */
	public static function for_site() {
		$origins = array();
		foreach ( array( home_url( '/' ), site_url( '/' ) ) as $url ) {
			$origin = self::from_url( $url );
			if ( null !== $origin && ! in_array( $origin, $origins, true ) ) {
				$origins[] = $origin;
			}
		}
		return $origins;
	}
}
