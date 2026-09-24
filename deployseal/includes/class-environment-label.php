<?php
/**
 * The declared environment label (contract §2).
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Lower-case, [a-z0-9-], at most 32 characters.
 */
final class Environment_Label {

	/** Host-name fragments that mark a non-production site. */
	const NON_PRODUCTION_HINTS = array( 'staging', 'uat', 'test', 'dev', 'qa', 'sandbox', 'preprod', 'localhost' );

	/**
	 * Normalises any text into a contract-valid label: accents stripped, lower-cased, every run of
	 * other characters becomes one hyphen, no leading or trailing hyphen, cut at 32. Empty when
	 * nothing survives.
	 *
	 * @param string|null $value Raw text.
	 * @return string
	 */
	public static function slugify( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$value = strtolower( remove_accents( $value ) );
		$value = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', $value ), '-' );
		if ( strlen( $value ) > Contract::ENVIRONMENT_LABEL_MAX_LENGTH ) {
			$value = rtrim( substr( $value, 0, Contract::ENVIRONMENT_LABEL_MAX_LENGTH ), '-' );
		}
		return $value;
	}

	/**
	 * Default label for a site, guessed from its URL host: "staging" when the host carries a
	 * non-production hint, else "production". The installer is shown the value and can change it.
	 *
	 * @param string|null $url Site URL.
	 * @return string
	 */
	public static function derive_from_url( $url ) {
		$host = wp_parse_url( (string) $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return 'production';
		}
		$host = strtolower( $host );
		foreach ( self::NON_PRODUCTION_HINTS as $hint ) {
			if ( false !== strpos( $host, $hint ) ) {
				return 'staging';
			}
		}
		return 'production';
	}
}
