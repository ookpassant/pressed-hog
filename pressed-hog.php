<?php
/**
 * Plugin Name:       Pressed Hog – PostHog Analytics
 * Plugin URI:        https://github.com/ookpassant/pressed-hog
 * Description:       Connect WordPress to PostHog: analytics snippet, user identification, WooCommerce events, feature flags, and cookie consent.
 * Version:           0.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            sea
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pressed-hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PRESSED_HOG_VERSION', '0.1.0' );
define( 'PRESSED_HOG_FILE', __FILE__ );
define( 'PRESSED_HOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSED_HOG_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSED_HOG_OPTION', 'pressed_hog_options' );

require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-settings.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-tracker.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-flags.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-woocommerce.php';

/**
 * Default options. Everything the plugin stores lives in one option array.
 *
 * @return array
 */
function pressed_hog_default_options() {
	return array(
		'api_key'              => '',
		'api_host'             => 'https://us.i.posthog.com',
		'capture_pageviews'    => 1,
		'autocapture'          => 1,
		'session_recording'    => 0,
		'enable_surveys'       => 1,
		'identify_users'       => 0,
		'excluded_roles'       => array( 'administrator', 'editor' ),
		'consent_mode'         => 'none', // none | banner | external
		'consent_cookie_name'  => 'pressed_hog_consent',
		'consent_cookie_value' => 'granted',
		'banner_text'          => __( 'We use cookies to understand how you use our site and to improve your experience.', 'pressed-hog' ),
		'banner_accept'        => __( 'Accept', 'pressed-hog' ),
		'banner_decline'       => __( 'Decline', 'pressed-hog' ),
		'woocommerce_events'   => 0,
	);
}

/**
 * Get the plugin options merged with defaults.
 *
 * @return array
 */
function pressed_hog_get_options() {
	$options = get_option( PRESSED_HOG_OPTION, array() );
	if ( ! is_array( $options ) ) {
		$options = array();
	}
	return wp_parse_args( $options, pressed_hog_default_options() );
}

register_activation_hook(
	__FILE__,
	function () {
		add_option( PRESSED_HOG_OPTION, pressed_hog_default_options() );
	}
);

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'pressed-hog', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		Pressed_Hog_Settings::init();
		Pressed_Hog_Tracker::init();
		Pressed_Hog_Flags::init();
		Pressed_Hog_WooCommerce::init();
	}
);
