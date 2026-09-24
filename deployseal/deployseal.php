<?php
/**
 * Plugin Name:       DeploySeal
 * Plugin URI:        https://deployseal.com/docs/wordpress
 * Description:       Loads the DeploySeal tester widget on your site so testers can pin issues on the real pages, and declares which environment and which build they were on so the readiness report can prove what was tested.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            DeploySeal
 * Author URI:        https://deployseal.com
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       deployseal
 *
 * WC requires at least: 7.0
 *
 * @package DeploySeal
 */

defined( 'ABSPATH' ) || exit;

/** Plugin version. build/bump.ps1 keeps it in step with the header, readme.txt and the installer string. */
define( 'DEPLOYSEAL_VERSION', '1.0.0' );
define( 'DEPLOYSEAL_FILE', __FILE__ );
define( 'DEPLOYSEAL_DIR', plugin_dir_path( __FILE__ ) );

require_once DEPLOYSEAL_DIR . 'includes/class-contract.php';
require_once DEPLOYSEAL_DIR . 'includes/class-environment-label.php';
require_once DEPLOYSEAL_DIR . 'includes/class-origins.php';
require_once DEPLOYSEAL_DIR . 'includes/class-git-sha.php';
require_once DEPLOYSEAL_DIR . 'includes/class-build-marker.php';
require_once DEPLOYSEAL_DIR . 'includes/class-snippet.php';
require_once DEPLOYSEAL_DIR . 'includes/class-options.php';
require_once DEPLOYSEAL_DIR . 'includes/class-inventory.php';
require_once DEPLOYSEAL_DIR . 'includes/class-tag.php';
require_once DEPLOYSEAL_DIR . 'includes/class-settings-page.php';
require_once DEPLOYSEAL_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'DeploySeal\WP\Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DeploySeal\WP\Plugin', 'deactivate' ) );

DeploySeal\WP\Plugin::boot();
