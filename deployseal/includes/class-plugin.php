<?php
/**
 * Wiring: hooks, WP-Cron, activation, WooCommerce compatibility.
 *
 * @package DeploySeal
 */

namespace DeploySeal\WP;

defined( 'ABSPATH' ) || exit;

/**
 * Entry point.
 */
final class Plugin {

	/**
	 * Registers every hook. Settings are per site (get_option), so network activation needs no
	 * network-level state: each site keeps its own key, label and schedule.
	 */
	public static function boot() {
		Tag::register();
		if ( is_admin() ) {
			Settings_Page::register();
		}

		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- six hours.
		add_action( Inventory::CRON_HOOK, array( Inventory::class, 'run_scheduled' ) );
		add_action( 'init', array( __CLASS__, 'ensure_schedule' ) );
		add_action( 'before_woocommerce_init', array( __CLASS__, 'declare_woocommerce_compatibility' ) );
	}

	/**
	 * The six-hourly schedule.
	 *
	 * @param array $schedules Schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$schedules[ Inventory::CRON_SCHEDULE ] = array(
			'interval' => Contract::INVENTORY_SEND_PERIOD_HOURS * HOUR_IN_SECONDS,
			'display'  => __( 'Every 6 hours (DeploySeal inventory)', 'deployseal' ),
		);
		return $schedules;
	}

	/**
	 * Keeps the event scheduled on every site the plugin runs on (also covers sites added to a
	 * network after a network activation). The handler itself checks "Send inventory on a schedule".
	 */
	public static function ensure_schedule() {
		if ( ! wp_next_scheduled( Inventory::CRON_HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, Inventory::CRON_SCHEDULE, Inventory::CRON_HOOK );
		}
	}

	/**
	 * WooCommerce: the plugin touches no orders or products, so it is compatible with High-Performance
	 * Order Storage and the cart/checkout blocks. Declared so the WooCommerce admin does not warn.
	 */
	public static function declare_woocommerce_compatibility() {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DEPLOYSEAL_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DEPLOYSEAL_FILE, true );
		}
	}

	/**
	 * Activation (single site or network-wide): schedule the inventory event where it can be done now.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ) {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected -- six hours.
		self::for_each_site( $network_wide, array( __CLASS__, 'ensure_schedule' ) );
	}

	/**
	 * Deactivation: remove the scheduled event (settings stay until the plugin is deleted).
	 *
	 * @param bool $network_wide Network deactivation.
	 */
	public static function deactivate( $network_wide = false ) {
		self::for_each_site(
			$network_wide,
			static function () {
				wp_clear_scheduled_hook( Inventory::CRON_HOOK );
			}
		);
	}

	/**
	 * Runs a callback on the current site, or on every site of the network.
	 *
	 * @param bool     $network_wide All sites.
	 * @param callable $callback     Callback.
	 */
	private static function for_each_site( $network_wide, $callback ) {
		if ( ! $network_wide || ! is_multisite() ) {
			call_user_func( $callback );
			return;
		}
		foreach ( get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		) as $site_id ) {
			switch_to_blog( $site_id );
			call_user_func( $callback );
			restore_current_blog();
		}
	}
}
