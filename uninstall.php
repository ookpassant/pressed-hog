<?php
/**
 * Uninstall cleanup: remove the plugin's option and cached flag transients.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'pressed_hog_options' );
delete_option( 'pressed_hog_flush_rewrite' );

global $wpdb;
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_pressed\_hog\_%'
	    OR option_name LIKE '\_transient\_timeout\_pressed\_hog\_%'"
); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- one-time uninstall cleanup of transients.
