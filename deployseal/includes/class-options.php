<?php
/**
 * The plugin's settings: one option per site (multisite: every site has its own).
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Defaults, reading, sanitising and the values derived from them.
 */
final class Options {

	/** The option that holds every setting. */
	const NAME = 'deployseal_settings';

	/** The settings API group. */
	const GROUP = 'deployseal';

	/**
	 * Defaults: only the site key and Enabled need a value; everything else is already sensible.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'           => false,
			'site_key'          => '',
			'environment_label' => '',
			'build_source'      => Build_Marker::SOURCE_AUTO,
			'git_sha_file'      => '',
			'manual_build'      => '',
			'load_in_admin'     => false,
			'api_base'          => Contract::DEFAULT_API_BASE,
			'api_key'           => '',
			'send_inventory'    => false,
		);
	}

	/**
	 * The saved settings merged over the defaults.
	 *
	 * @return array
	 */
	public static function get() {
		$saved = get_option( self::NAME, array() );
		return array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
	}

	/**
	 * The label the tag declares: the saved one, else the guess from the Site Address.
	 *
	 * @param array $options Options.
	 * @return string
	 */
	public static function effective_environment_label( array $options ) {
		$label = Environment_Label::slugify( $options['environment_label'] );
		return '' !== $label ? $label : self::derived_environment_label();
	}

	/**
	 * The label guessed from the Site Address.
	 *
	 * @return string
	 */
	public static function derived_environment_label() {
		return Environment_Label::derive_from_url( home_url( '/' ) );
	}

	/**
	 * The API base without a trailing slash, the default when blank.
	 *
	 * @param array $options Options.
	 * @return string
	 */
	public static function api_base( array $options ) {
		$base = trim( (string) $options['api_base'] );
		return '' === $base ? Contract::DEFAULT_API_BASE : untrailingslashit( $base );
	}

	/**
	 * A one-line warning when the key does not look like a DeploySeal environment key.
	 *
	 * @param string $key Site key.
	 * @return string
	 */
	public static function site_key_warning( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key || 0 === strpos( $key, 'ls_' ) || 0 === strpos( $key, 'ds_' ) ) {
			return '';
		}
		return __( 'DeploySeal keys start with "ls_" (legacy keys with "ds_"). Check that you copied the environment\'s public key, not something else.', 'deployseal' );
	}

	/**
	 * Settings API sanitize callback. Checkboxes absent from the form are off. The API key is a
	 * secret: an empty box keeps the stored key, "Remove the stored API key" deletes it.
	 *
	 * @param mixed $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$input   = is_array( $input ) ? wp_unslash( $input ) : array();
		$current = self::get();
		$out     = self::defaults();

		$out['enabled']           = ! empty( $input['enabled'] );
		$out['site_key']          = sanitize_text_field( isset( $input['site_key'] ) ? $input['site_key'] : '' );
		$out['site_key']          = (string) preg_replace( '/\s+/', '', $out['site_key'] );
		$out['environment_label'] = Environment_Label::slugify( isset( $input['environment_label'] ) ? $input['environment_label'] : '' );

		$source               = isset( $input['build_source'] ) ? sanitize_key( $input['build_source'] ) : Build_Marker::SOURCE_AUTO;
		$out['build_source']  = array_key_exists( $source, Build_Marker::sources() ) ? $source : Build_Marker::SOURCE_AUTO;
		$out['git_sha_file']  = sanitize_text_field( isset( $input['git_sha_file'] ) ? $input['git_sha_file'] : '' );
		$out['manual_build']  = Build_Marker::clamp( Build_Marker::sanitize( sanitize_text_field( isset( $input['manual_build'] ) ? $input['manual_build'] : '' ) ) );
		$out['load_in_admin'] = ! empty( $input['load_in_admin'] );

		$api_base        = esc_url_raw( trim( isset( $input['api_base'] ) ? (string) $input['api_base'] : '' ), array( 'http', 'https' ) );
		$out['api_base'] = '' === $api_base ? Contract::DEFAULT_API_BASE : untrailingslashit( $api_base );

		$api_key = trim( sanitize_text_field( isset( $input['api_key'] ) ? $input['api_key'] : '' ) );
		if ( ! empty( $input['clear_api_key'] ) ) {
			$out['api_key'] = '';
		} elseif ( '' !== $api_key ) {
			$out['api_key'] = $api_key;
		} else {
			$out['api_key'] = (string) $current['api_key'];
		}

		$out['send_inventory'] = ! empty( $input['send_inventory'] );
		return $out;
	}
}
