<?php
/**
 * Removes the plugin's settings and schedule from every site when the plugin is deleted.
 *
 * @package DeploySeal
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * One site's cleanup.
 */
function deployseal_uninstall_site() {
	delete_option( 'deployseal_settings' );
	delete_option( 'deployseal_inventory_last' );
	wp_clear_scheduled_hook( 'deployseal_send_inventory' );
}

if ( is_multisite() ) {
	foreach ( get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	) as $deployseal_site_id ) {
		switch_to_blog( $deployseal_site_id );
		deployseal_uninstall_site();
		restore_current_blog();
	}
} else {
	deployseal_uninstall_site();
}
