<?php
/**
 * Puts the contract tag in <head>: on the public site always (when enabled), in wp-admin only
 * when "Also load in the admin area" is on, on the login page never.
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the loader so WordPress, caching plugins and other code see a normal script handle,
 * then swaps WordPress's printed tag for the contract's exact tag in script_loader_tag.
 */
final class Tag {

	/** Script handle. */
	const HANDLE = 'deployseal-widget';

	/**
	 * Hooks. wp_enqueue_scripts fires only on the public site (never in wp-admin, never on
	 * wp-login.php, which has its own login_enqueue_scripts hook this plugin does not use).
	 */
	public static function register() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ), 1 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'filter_tag' ), 10, 2 );
	}

	/**
	 * The tag for the current request, or '' when nothing should render.
	 *
	 * @return string
	 */
	public static function current() {
		// Resolved once per request (the marker may read the SHA file).
		static $tag = null;
		if ( null !== $tag ) {
			return $tag;
		}
		$options = Options::get();
		if ( empty( $options['enabled'] ) ) {
			$tag = '';
			return $tag;
		}
		$marker = Build_Marker::resolve( $options );
		$tag    = Snippet::build( $options['site_key'], Options::effective_environment_label( $options ), $marker['marker'] );
		return $tag;
	}

	/**
	 * Public site.
	 */
	public static function enqueue() {
		if ( '' === self::current() ) {
			return;
		}
		// The loader is served un-versioned on purpose: the contract fixes one URL and the CDN's
		// cache policy handles freshness, so no ?ver= query is added (the tag is replaced below anyway).
		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
		wp_enqueue_script( self::HANDLE, Contract::SCRIPT_URL, array(), null, self::head_args() );
	}

	/**
	 * Admin area, only when opted in.
	 */
	public static function enqueue_admin() {
		$options = Options::get();
		if ( empty( $options['load_in_admin'] ) ) {
			return;
		}
		self::enqueue();
	}

	/**
	 * Fifth wp_enqueue_script argument: the async strategy array on WordPress 6.3+, a plain
	 * "not in footer" before that (older versions read any array as true, i.e. footer).
	 *
	 * @return array|bool
	 */
	private static function head_args() {
		if ( version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) ) {
			return array(
				'strategy'  => 'async',
				'in_footer' => false,
			);
		}
		return false;
	}

	/**
	 * Replaces WordPress's printed tag for our handle with the contract's exact tag, so the page
	 * carries exactly one tag, with async, and no id/ver/data-wp-strategy additions. This is also
	 * the async fallback for WordPress before 6.3.
	 *
	 * @param string $tag    Printed tag.
	 * @param string $handle Script handle.
	 * @return string
	 */
	public static function filter_tag( $tag, $handle ) {
		if ( self::HANDLE !== $handle ) {
			return $tag;
		}
		$ours = self::current();
		return '' === $ours ? '' : $ours . "\n";
	}
}
