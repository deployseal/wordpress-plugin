<?php
/**
 * Constants fixed by the DeploySeal install contract (docs/INSTALL_CONTRACT.md, v1.2).
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Every install path shares these numbers; the contract changes first.
 */
final class Contract {

	/** The only supported loader URL (§1). */
	const SCRIPT_URL = 'https://cdn.deployseal.com/ds-widget.js';

	/** The DeploySeal API host the inventory is posted to (§6). Advanced setting. */
	const DEFAULT_API_BASE = 'https://api.deployseal.com';

	/** The install path this plugin declares in data-ds-installer (§2): wordpress-plugin/{version}. */
	const INSTALLER_PREFIX = 'wordpress-plugin/';

	/** Installer value: at most 48 chars of [a-z0-9.-/] (§2). */
	const INSTALLER_MAX_LENGTH = 48;

	/** Environment label: at most 32 chars, [a-z0-9-] (§2). */
	const ENVIRONMENT_LABEL_MAX_LENGTH = 32;

	/** Build marker: single token, no whitespace, at most 64 chars (§2, §4). */
	const BUILD_MARKER_MAX_LENGTH = 64;

	/** The app snippet's placeholder; never emitted as a marker (§2). */
	const BUILD_PLACEHOLDER = 'REPLACE-WITH-BUILD-MARKER';

	/** Length of the short SHA appended to a version marker ("6.8.3+a1b2c3d"). */
	const SHORT_SHA_LENGTH = 7;

	/** Inventory: items per snapshot (§6). */
	const INVENTORY_MAX_ITEMS = 500;

	/** Inventory: systemName length (§6). */
	const INVENTORY_SYSTEM_NAME_MAX = 128;

	/** Inventory: name length (§6). */
	const INVENTORY_NAME_MAX = 200;

	/** Inventory: version length (§6). */
	const INVENTORY_VERSION_MAX = 32;

	/** Inventory: platformVersion length (§6). */
	const PLATFORM_VERSION_MAX_LENGTH = 32;

	/** Platform identifier every WordPress install reports (§6). */
	const PLATFORM = 'wordpress';

	/** Period of the scheduled inventory send, in hours. */
	const INVENTORY_SEND_PERIOD_HOURS = 6;

	/** Where the installer creates environments and reads the Live / Stale / Not seen pill (§7.5). */
	const APP_URL = 'https://deployseal.com/sites';

	/** Public docs for this plugin. */
	const DOCS_URL = 'https://deployseal.com/docs/wordpress';

	/**
	 * The data-ds-installer value for this plugin version: wordpress-plugin/1.0.0.
	 *
	 * @param string $version Plugin version.
	 * @return string
	 */
	public static function installer( $version = DEPLOYSEAL_VERSION ) {
		$value = strtolower( self::INSTALLER_PREFIX . trim( (string) $version ) );
		return substr( $value, 0, self::INSTALLER_MAX_LENGTH );
	}
}
