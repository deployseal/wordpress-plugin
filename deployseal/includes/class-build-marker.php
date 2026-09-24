<?php
/**
 * Composes the build marker (contract §4).
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Where the "which code is running" marker comes from, and what it resolves to right now.
 *
 * Sources:
 * - auto (default): the DEPLOYSEAL_BUILD constant when wp-config.php defines one; otherwise the
 *   WordPress version plus the active theme and its version, plus the short git SHA when the SHA
 *   file can be read ("6.8.3+twentytwentyfive-1.3+a1b2c3d").
 * - platform: WordPress version plus the active theme and its version, never a SHA.
 * - git_sha: the SHA alone (contract rule 1, "prefer the commit"); nothing when unreadable.
 * - manual: a marker typed on the settings page.
 */
final class Build_Marker {

	const SOURCE_AUTO     = 'auto';
	const SOURCE_PLATFORM = 'platform';
	const SOURCE_GIT_SHA  = 'git_sha';
	const SOURCE_MANUAL   = 'manual';

	/**
	 * The sources, with their labels, in the order the settings page lists them.
	 *
	 * @return array<string,string>
	 */
	public static function sources() {
		return array(
			self::SOURCE_AUTO     => __( 'Automatic: DEPLOYSEAL_BUILD constant if defined, else WordPress + theme version + git SHA when available (e.g. 6.8.3+twentytwentyfive-1.3+a1b2c3d)', 'deployseal' ),
			self::SOURCE_PLATFORM => __( 'WordPress + theme version only (e.g. 6.8.3+twentytwentyfive-1.3)', 'deployseal' ),
			self::SOURCE_GIT_SHA  => __( 'Git SHA only (from the file below)', 'deployseal' ),
			self::SOURCE_MANUAL   => __( 'Manual (typed below)', 'deployseal' ),
		);
	}

	/**
	 * Contract §4 rule 3: single token, no whitespace. Whitespace and control characters are
	 * removed, not replaced; the app snippet's placeholder counts as no marker.
	 *
	 * @param string|null $value Raw value.
	 * @return string
	 */
	public static function sanitize( $value ) {
		$value = (string) preg_replace( '/[\s\x00-\x1F\x7F]+/u', '', (string) $value );
		if ( 0 === strcasecmp( $value, Contract::BUILD_PLACEHOLDER ) ) {
			return '';
		}
		return $value;
	}

	/**
	 * Contract §2/§4: at most 64 characters.
	 *
	 * @param string $marker Marker.
	 * @return string
	 */
	public static function clamp( $marker ) {
		return substr( $marker, 0, Contract::BUILD_MARKER_MAX_LENGTH );
	}

	/**
	 * The platform part: "6.8.3+twentytwentyfive-1.3" (WordPress version, active theme's
	 * directory name and its version).
	 *
	 * @return string
	 */
	public static function platform_marker() {
		$marker = self::sanitize( get_bloginfo( 'version' ) );
		$theme  = wp_get_theme();
		$slug   = strtolower( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', (string) $theme->get_stylesheet() ) );
		$ver    = self::sanitize( (string) $theme->get( 'Version' ) );
		if ( '' !== $slug ) {
			$marker .= '+' . $slug . ( '' !== $ver ? '-' . $ver : '' );
		}
		return $marker;
	}

	/**
	 * Resolves the marker from the saved options.
	 *
	 * @param array $options Plugin options (Options::get()).
	 * @return array{marker:string,source:string,description:string,warning:string,sha_path:string,sha_found:bool}
	 */
	public static function resolve( array $options ) {
		$source   = isset( $options['build_source'] ) && array_key_exists( $options['build_source'], self::sources() ) ? $options['build_source'] : self::SOURCE_AUTO;
		$sha_path = Git_Sha::resolve_path( isset( $options['git_sha_file'] ) ? $options['git_sha_file'] : '' );
		$sha      = '' === $sha_path ? null : Git_Sha::try_read_file( $sha_path );
		$result   = array(
			'marker'      => '',
			'source'      => $source,
			'description' => '',
			'warning'     => '',
			'sha_path'    => $sha_path,
			'sha_found'   => null !== $sha,
		);

		switch ( $source ) {
			case self::SOURCE_PLATFORM:
				$result['marker']      = self::platform_marker();
				$result['description'] = __( 'WordPress version + active theme and its version', 'deployseal' );
				break;

			case self::SOURCE_GIT_SHA:
				if ( null === $sha ) {
					$result['description'] = __( 'git SHA (not readable, so no marker is emitted)', 'deployseal' );
					$result['warning']     = __( 'The build marker source is "Git SHA only" but no SHA could be read. The data-ds-build attribute is omitted until the file exists and holds a 7 to 40 character hex SHA.', 'deployseal' );
				} else {
					$result['marker']      = $sha;
					$result['description'] = __( 'git SHA', 'deployseal' );
				}
				break;

			case self::SOURCE_MANUAL:
				$manual = self::sanitize( isset( $options['manual_build'] ) ? $options['manual_build'] : '' );
				if ( '' === $manual ) {
					$result['description'] = __( 'manual (empty, so no marker is emitted)', 'deployseal' );
					$result['warning']     = __( 'The build marker source is "Manual" but the manual marker is empty. The data-ds-build attribute is omitted.', 'deployseal' );
				} else {
					$result['marker']      = $manual;
					$result['description'] = __( 'manual', 'deployseal' );
				}
				break;

			default:
				$constant = defined( 'DEPLOYSEAL_BUILD' ) ? self::sanitize( (string) constant( 'DEPLOYSEAL_BUILD' ) ) : '';
				if ( '' !== $constant ) {
					$result['marker']      = $constant;
					$result['description'] = __( 'the DEPLOYSEAL_BUILD constant in wp-config.php', 'deployseal' );
				} elseif ( null !== $sha ) {
					$result['marker']      = self::platform_marker() . '+' . Git_Sha::short( $sha );
					$result['description'] = __( 'WordPress version + active theme and its version + short git SHA', 'deployseal' );
				} else {
					$result['marker']      = self::platform_marker();
					$result['description'] = __( 'WordPress version + active theme and its version (no git SHA could be read, so there is no "+sha" suffix)', 'deployseal' );
					$result['warning']     = __( 'No git SHA could be read and DEPLOYSEAL_BUILD is not defined. Point "Git SHA file path" at a file your deploy writes the commit into, or define DEPLOYSEAL_BUILD in wp-config.php; until then the marker will not change between deployments of the same WordPress and theme version.', 'deployseal' );
				}
		}

		$result['marker'] = self::clamp( $result['marker'] );
		return $result;
	}
}
