<?php
/**
 * Renders the contract's script tag (§1) byte-exactly.
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * The tag, in the same attribute order as every DeploySeal install path:
 * <script src="https://cdn.deployseal.com/ds-widget.js" data-ds-site-key="…" data-ds-environment="…"
 * data-ds-build="…" data-ds-installer="wordpress-plugin/1.0.0" async></script>
 * Recommended attributes are omitted, never emitted empty.
 */
final class Snippet {

	/**
	 * The tag, or an empty string when there is no site key (the widget cannot work without one,
	 * so nothing is rendered rather than a broken tag).
	 *
	 * @param string      $site_key    Environment public key.
	 * @param string      $environment Declared environment label (already slugged).
	 * @param string      $build       Build marker (already resolved).
	 * @param string|null $version     Plugin version for data-ds-installer; null omits it (tests only).
	 * @return string
	 */
	public static function build( $site_key, $environment, $build, $version = DEPLOYSEAL_VERSION ) {
		$key = trim( (string) $site_key );
		if ( '' === $key ) {
			return '';
		}

		// The contract's own tag; WordPress prints it through the enqueued handle's script_loader_tag filter (see Tag).
		$tag  = '<script src="' . esc_attr( Contract::SCRIPT_URL ) . '"'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
		$tag .= ' data-ds-site-key="' . esc_attr( $key ) . '"';

		$env = trim( (string) $environment );
		if ( '' !== $env ) {
			$tag .= ' data-ds-environment="' . esc_attr( $env ) . '"';
		}

		$marker = trim( (string) $build );
		if ( '' !== $marker ) {
			$tag .= ' data-ds-build="' . esc_attr( $marker ) . '"';
		}

		if ( null !== $version && '' !== trim( (string) $version ) ) {
			$tag .= ' data-ds-installer="' . esc_attr( Contract::installer( $version ) ) . '"';
		}

		return $tag . ' async></script>';
	}
}
