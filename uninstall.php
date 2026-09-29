<?php
/**
 * Uninstall cleanup: remove the plugin's options, cached transients, and the
 * WooCommerce order meta used to deduplicate order_completed events — on
 * every site of a multisite network.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove Pressed Hog data from the current site.
 *
 * @return void
 */
function pressed_hog_uninstall_site() {
	global $wpdb;

	delete_option( 'pressed_hog_options' );
	delete_option( 'pressed_hog_personal_api_key' );
	delete_option( 'pressed_hog_flush_rewrite' );
	delete_option( 'pressed_hog_autoload_fixed' );
	delete_option( 'pressed_hog_personal_api_key_migrated' );
	delete_option( 'pressed_hog_links' );

	// phpcs:disable WordPress.DB.DirectDatabaseQuery -- one-time uninstall cleanup.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_pressed\_hog\_%'
		    OR option_name LIKE '\_transient\_timeout\_pressed\_hog\_%'"
	);

	// Order meta flag, in both the legacy posts table and WooCommerce HPOS.
	$wpdb->delete( $wpdb->postmeta, array( 'meta_key' => '_pressed_hog_tracked' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup.
	$hpos_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $hpos_meta ) ) ) === $hpos_meta ) {
		$wpdb->delete( $hpos_meta, array( 'meta_key' => '_pressed_hog_tracked' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-time uninstall cleanup.
	}
	// phpcs:enable WordPress.DB.DirectDatabaseQuery
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pressed_hog_site_id ) {
		switch_to_blog( $pressed_hog_site_id );
		pressed_hog_uninstall_site();
		restore_current_blog();
	}
} else {
	pressed_hog_uninstall_site();
}
