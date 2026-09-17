<?php
/**
 * Plugin Name:       Pressed Hog – PostHog Analytics
 * Plugin URI:        https://github.com/ookpassant/pressed-hog
 * Description:       Connect WordPress to PostHog: analytics snippet, user identification, WooCommerce events, feature flags, and cookie consent.
 * Version:           0.3.0
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

define( 'PRESSED_HOG_VERSION', '0.3.0' );
define( 'PRESSED_HOG_FILE', __FILE__ );
define( 'PRESSED_HOG_DIR', plugin_dir_path( __FILE__ ) );
define( 'PRESSED_HOG_URL', plugin_dir_url( __FILE__ ) );
define( 'PRESSED_HOG_OPTION', 'pressed_hog_options' );
define( 'PRESSED_HOG_PERSONAL_API_KEY_OPTION', 'pressed_hog_personal_api_key' );

require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-settings.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-wizard.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-tracker.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-flags.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-woocommerce.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-proxy.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-analytics.php';
require_once PRESSED_HOG_DIR . 'includes/class-pressed-hog-links.php';

/**
 * Default front-end and non-secret options.
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
		'proxy_enabled'        => 0,
		'proxy_slug'           => 'phog',
		'project_id'           => 0,
		'embed_url'            => '',
	);
}

/**
 * The PostHog app host (where the UI and private API live), derived from
 * the ingestion host: us.i.posthog.com → us.posthog.com; custom hosts are
 * used as-is.
 *
 * @return string
 */
function pressed_hog_app_host() {
	$options = pressed_hog_get_options();
	return str_replace( '.i.posthog.com', '.posthog.com', $options['api_host'] );
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

/**
 * Get the personal API key used by the admin-only Query API client.
 *
 * Keeping this separate prevents public requests that load the regular plugin
 * settings from retrieving the credential too.
 *
 * @return string
 */
function pressed_hog_get_personal_api_key() {
	$value = get_option( PRESSED_HOG_PERSONAL_API_KEY_OPTION, '' );
	return is_string( $value ) ? $value : '';
}

/**
 * Store the personal API key without autoloading it.
 *
 * @param string $value Personal API key.
 * @return void
 */
function pressed_hog_set_personal_api_key( $value ) {
	$value = sanitize_text_field( $value );
	if ( null === get_option( PRESSED_HOG_PERSONAL_API_KEY_OPTION, null ) ) {
		add_option( PRESSED_HOG_PERSONAL_API_KEY_OPTION, $value, '', false );
		return;
	}
	update_option( PRESSED_HOG_PERSONAL_API_KEY_OPTION, $value, false );
}

register_activation_hook(
	__FILE__,
	function () {
		add_option( PRESSED_HOG_OPTION, pressed_hog_default_options(), '', false );
		add_option( PRESSED_HOG_PERSONAL_API_KEY_OPTION, '', '', false );
		set_transient( Pressed_Hog_Wizard::REDIRECT_TRANSIENT, 1, 60 );
		update_option( Pressed_Hog_Proxy::FLUSH_FLAG, 1 );
	}
);

register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/**
 * One-time upgrade: ensure the main option is not autoloaded on installs
 * created before autoload was disabled.
 */
function pressed_hog_maybe_migrate_autoload() {
	if ( get_option( 'pressed_hog_autoload_fixed' ) ) {
		return;
	}
	$value = get_option( PRESSED_HOG_OPTION );
	if ( false !== $value ) {
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( PRESSED_HOG_OPTION, false );
		} else {
			delete_option( PRESSED_HOG_OPTION );
			add_option( PRESSED_HOG_OPTION, $value, '', false );
		}
	}
	add_option( 'pressed_hog_autoload_fixed', 1, '', false );
}

/**
 * One-time upgrade: move the personal API key out of the settings array.
 *
 * @return void
 */
function pressed_hog_maybe_migrate_personal_api_key() {
	if ( get_option( 'pressed_hog_personal_api_key_migrated' ) ) {
		return;
	}

	$options = get_option( PRESSED_HOG_OPTION, array() );
	if ( is_array( $options ) && array_key_exists( 'personal_api_key', $options ) ) {
		$current_key = get_option( PRESSED_HOG_PERSONAL_API_KEY_OPTION, null );
		if ( null === $current_key || ( '' === $current_key && ! empty( $options['personal_api_key'] ) ) ) {
			pressed_hog_set_personal_api_key( $options['personal_api_key'] );
		}
		unset( $options['personal_api_key'] );
		update_option( PRESSED_HOG_OPTION, $options, false );
	}

	add_option( 'pressed_hog_personal_api_key_migrated', 1, '', false );
}

add_action(
	'plugins_loaded',
	function () {
		load_plugin_textdomain( 'pressed-hog', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		if ( is_admin() ) {
			pressed_hog_maybe_migrate_autoload();
			pressed_hog_maybe_migrate_personal_api_key();
		}

		Pressed_Hog_Settings::init();
		Pressed_Hog_Wizard::init();
		Pressed_Hog_Tracker::init();
		Pressed_Hog_Flags::init();
		Pressed_Hog_WooCommerce::init();
		Pressed_Hog_Proxy::init();
		Pressed_Hog_Analytics::init();
		Pressed_Hog_Links::init();
	}
);
