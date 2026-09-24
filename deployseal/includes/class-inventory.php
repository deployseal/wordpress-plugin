<?php
/**
 * Platform inventory snapshot (contract §6): which plugins and themes, at which versions, were
 * installed when a release was tested.
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the platform, builds the canonical snapshot and posts it server to server to
 * POST {api base}/api/v1/sites/{site key}/inventory with Authorization: Bearer {API key}.
 */
final class Inventory {

	/** WP-Cron hook of the scheduled send. */
	const CRON_HOOK = 'deployseal_send_inventory';

	/** WP-Cron schedule name (every six hours). */
	const CRON_SCHEDULE = 'deployseal_six_hourly';

	/** Option holding the last send result, for the settings page. */
	const LAST_RESULT_OPTION = 'deployseal_inventory_last';

	/**
	 * Everything WordPress knows about, as inventory items: every installed plugin (enabled =
	 * active on this site or network-active), every must-use plugin, every installed theme
	 * (enabled = the active theme or its parent), WordPress itself and the PHP runtime.
	 * WooCommerce is one of the plugins when it is installed, with its version.
	 *
	 * @return array[] Items with systemName, name, version, enabled.
	 */
	public static function collect_items() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$items = array();
		foreach ( get_plugins() as $file => $data ) {
			$items[] = self::item( 'plugin:' . $file, $data['Name'], $data['Version'], is_plugin_active( $file ) );
		}
		foreach ( get_mu_plugins() as $file => $data ) {
			$items[] = self::item( 'mu-plugin:' . $file, $data['Name'], $data['Version'], true );
		}

		$active = wp_get_theme();
		$in_use = array( $active->get_stylesheet(), $active->get_template() );
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$items[] = self::item( 'theme:' . $stylesheet, (string) $theme->get( 'Name' ), (string) $theme->get( 'Version' ), in_array( $stylesheet, $in_use, true ) );
		}

		$items[] = self::item( 'core:' . Contract::PLATFORM, 'WordPress', (string) get_bloginfo( 'version' ), true );
		$items[] = self::item( 'runtime:php', 'PHP', PHP_VERSION, true );

		return $items;
	}

	/**
	 * One item in the contract's key order.
	 *
	 * @param string $system_name Stable identifier.
	 * @param string $name        Display name.
	 * @param string $version     Version.
	 * @param bool   $enabled     Installed and active.
	 * @return array
	 */
	private static function item( $system_name, $name, $version, $enabled ) {
		return array(
			'systemName' => (string) $system_name,
			'name'       => (string) $name,
			'version'    => (string) $version,
			'enabled'    => (bool) $enabled,
		);
	}

	/**
	 * Trims and clamps every field to the contract's limits, drops items without a system name,
	 * keeps the first of any duplicate, sorts by systemName then version (byte order) and caps
	 * the list at 500.
	 *
	 * @param array[] $items Raw items.
	 * @return array[]
	 */
	public static function canonicalize( array $items ) {
		$seen    = array();
		$cleaned = array();
		foreach ( $items as $item ) {
			$system_name = substr( trim( (string) $item['systemName'] ), 0, Contract::INVENTORY_SYSTEM_NAME_MAX );
			if ( '' === $system_name || isset( $seen[ $system_name ] ) ) {
				continue;
			}
			$seen[ $system_name ] = true;

			$name = substr( trim( wp_strip_all_tags( (string) $item['name'] ) ), 0, Contract::INVENTORY_NAME_MAX );
			if ( '' === $name ) {
				$name = $system_name;
			}
			$version = substr( trim( (string) $item['version'] ), 0, Contract::INVENTORY_VERSION_MAX );
			if ( '' === $version ) {
				$version = '0';
			}
			$cleaned[] = self::item( $system_name, $name, $version, ! empty( $item['enabled'] ) );
		}

		usort(
			$cleaned,
			static function ( $a, $b ) {
				$by_name = strcmp( $a['systemName'], $b['systemName'] );
				return 0 !== $by_name ? $by_name : strcmp( $a['version'], $b['version'] );
			}
		);

		return array_slice( $cleaned, 0, Contract::INVENTORY_MAX_ITEMS );
	}

	/**
	 * Compact JSON with slashes and Unicode unescaped (the canonical form's shape).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function json( $value ) {
		return (string) wp_json_encode( $value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * The snapshot the next send would carry.
	 *
	 * @param array $options Options.
	 * @return array{body:array,items_json:string,sha256:string,raw_count:int}
	 */
	public static function snapshot( array $options ) {
		$raw        = self::collect_items();
		$items      = self::canonicalize( $raw );
		$items_json = self::json( $items );
		$marker     = Build_Marker::resolve( $options );
		$version    = substr( Build_Marker::sanitize( get_bloginfo( 'version' ) ), 0, Contract::PLATFORM_VERSION_MAX_LENGTH );
		$now        = microtime( true );

		$body = array(
			'platform'        => Contract::PLATFORM,
			'platformVersion' => '' === $version ? 'unknown' : $version,
			'buildMarker'     => '' === $marker['marker'] ? null : $marker['marker'],
			'capturedAt'      => gmdate( 'Y-m-d\TH:i:s', (int) $now ) . sprintf( '.%03dZ', (int) ( ( $now - floor( $now ) ) * 1000 ) ),
			'items'           => $items,
		);

		return array(
			'body'       => $body,
			'items_json' => $items_json,
			'sha256'     => hash( 'sha256', $items_json ),
			'raw_count'  => count( $raw ),
		);
	}

	/**
	 * The endpoint for a site key.
	 *
	 * @param array $options Options.
	 * @return string
	 */
	public static function endpoint( array $options ) {
		return Options::api_base( $options ) . '/api/v1/sites/' . rawurlencode( trim( (string) $options['site_key'] ) ) . '/inventory';
	}

	/**
	 * True when both keys are present.
	 *
	 * @param array $options Options.
	 * @return bool
	 */
	public static function can_send( array $options ) {
		return '' !== trim( (string) $options['site_key'] ) && '' !== trim( (string) $options['api_key'] );
	}

	/**
	 * Sends the snapshot. Never throws; one retry on a transport failure, none on an HTTP error.
	 * The result is stored for the settings page and returned.
	 *
	 * @param array $options Options.
	 * @return array{ok:bool,created:bool,status:int|null,id:string,item_count:int|null,message:string,at:int}
	 */
	public static function send( array $options ) {
		$url = self::endpoint( $options );
		if ( ! self::can_send( $options ) ) {
			return self::remember( self::failure( __( 'Enter the site key and a DeploySeal API key (Write scope) and save before sending.', 'deployseal' ) ) );
		}
		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$host   = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || empty( $host ) ) {
			return self::remember( self::failure( __( 'The API base is not an absolute http(s) URL.', 'deployseal' ) ) );
		}

		$snapshot = self::snapshot( $options );
		$args     = array(
			'method'      => 'POST',
			'timeout'     => 15,
			'redirection' => 0,
			'headers'     => array(
				'Authorization' => 'Bearer ' . trim( (string) $options['api_key'] ),
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json; charset=utf-8',
			),
			'body'        => self::json( $snapshot['body'] ),
			'data_format' => 'body',
		);

		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			// Transport failure (refused, DNS, TLS, timeout): one more try, then report.
			$response = wp_remote_post( $url, $args );
		}
		if ( is_wp_error( $response ) ) {
			/* translators: 1: endpoint URL, 2: transport error. */
			return self::remember( self::failure( sprintf( __( 'Could not reach %1$s: %2$s', 'deployseal' ), $url, $response->get_error_message() ) ) );
		}

		return self::remember( self::interpret( (int) wp_remote_retrieve_response_code( $response ), (string) wp_remote_retrieve_body( $response ) ) );
	}

	/**
	 * Turns the API's answer into a result.
	 *
	 * @param int    $code HTTP status.
	 * @param string $body Response body.
	 * @return array
	 */
	public static function interpret( $code, $body ) {
		$data = json_decode( $body, true );
		if ( 200 === $code || 201 === $code ) {
			$id    = is_array( $data ) && isset( $data['id'] ) ? (string) $data['id'] : '';
			$count = is_array( $data ) && isset( $data['itemCount'] ) ? (int) $data['itemCount'] : null;
			if ( 201 === $code ) {
				/* translators: 1: HTTP status, 2: item count, 3: snapshot id. */
				$message = sprintf( __( 'Inventory sent: HTTP %1$d — %2$s items recorded as snapshot %3$s (a new observation).', 'deployseal' ), $code, null === $count ? '?' : (string) $count, $id );
			} else {
				/* translators: 1: HTTP status, 2: item count, 3: snapshot id. */
				$message = sprintf( __( 'Inventory sent: HTTP %1$d — %2$s items, already on record as snapshot %3$s.', 'deployseal' ), $code, null === $count ? '?' : (string) $count, $id );
			}
			return array(
				'ok'         => true,
				'created'    => 201 === $code,
				'status'     => $code,
				'id'         => $id,
				'item_count' => $count,
				'message'    => $message,
				'at'         => time(),
			);
		}

		switch ( $code ) {
			case 401:
				$detail = __( 'the API key was not accepted (unknown or revoked). Paste a current key.', 'deployseal' );
				break;
			case 403:
				$detail = __( 'the API key does not carry the Write scope this call needs.', 'deployseal' );
				break;
			case 404:
				$detail = __( 'no environment of the key\'s organisation has this site key. Check that the site key and the API key belong to the same DeploySeal organisation.', 'deployseal' );
				break;
			case 429:
				$detail = __( 'rate limited; the scheduled send will try again later.', 'deployseal' );
				break;
			default:
				$detail = '';
				if ( is_array( $data ) ) {
					$detail = isset( $data['detail'] ) ? (string) $data['detail'] : ( isset( $data['title'] ) ? (string) $data['title'] : '' );
					if ( isset( $data['errors'] ) && is_array( $data['errors'] ) ) {
						foreach ( $data['errors'] as $field => $errors ) {
							$detail .= ' ' . $field . ': ' . ( is_array( $errors ) ? (string) reset( $errors ) : (string) $errors );
							break;
						}
					}
				}
				$detail = '' === trim( $detail ) ? substr( wp_strip_all_tags( $body ), 0, 300 ) : trim( $detail );
		}

		$result           = self::failure( 'HTTP ' . $code . ( '' === $detail ? '.' : ': ' . $detail ) );
		$result['status'] = $code;
		return $result;
	}

	/**
	 * A failed result.
	 *
	 * @param string $error What went wrong.
	 * @return array
	 */
	private static function failure( $error ) {
		return array(
			'ok'         => false,
			'created'    => false,
			'status'     => null,
			'id'         => '',
			'item_count' => null,
			/* translators: %s: what went wrong. */
			'message'    => sprintf( __( 'Inventory not sent: %s', 'deployseal' ), $error ),
			'at'         => time(),
		);
	}

	/**
	 * Stores the result for the settings page.
	 *
	 * @param array $result Result.
	 * @return array
	 */
	private static function remember( array $result ) {
		update_option( self::LAST_RESULT_OPTION, $result, false );
		return $result;
	}

	/**
	 * WP-Cron handler: sends when "Send inventory on a schedule" is on and both keys are set.
	 */
	public static function run_scheduled() {
		$options = Options::get();
		if ( empty( $options['send_inventory'] ) || ! self::can_send( $options ) ) {
			return;
		}
		self::send( $options );
	}
}
