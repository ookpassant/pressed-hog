<?php
/**
 * Server-side feature flag evaluation via PostHog's /decide endpoint,
 * plus a shortcode for gating content by flag.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_Flags {

	const CACHE_TTL = 60; // seconds

	public static function init() {
		add_shortcode( 'posthog_flag', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * Resolve a PostHog distinct ID for the current visitor.
	 *
	 * Logged-in users use their WordPress user ID (matching what the
	 * "identify logged-in users" option sends). Anonymous visitors are
	 * resolved from the posthog-js cookie when present.
	 *
	 * @return string|null
	 */
	public static function get_distinct_id() {
		if ( is_user_logged_in() ) {
			return (string) get_current_user_id();
		}

		$options     = pressed_hog_get_options();
		$cookie_name = 'ph_' . $options['api_key'] . '_posthog';
		if ( empty( $options['api_key'] ) || empty( $_COOKIE[ $cookie_name ] ) ) {
			return null;
		}

		$raw  = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
		$data = json_decode( rawurldecode( $raw ), true );
		if ( is_array( $data ) && ! empty( $data['distinct_id'] ) && is_string( $data['distinct_id'] ) ) {
			// Logged-in visitors are identified by their bare WP user ID, so an
			// anonymous visitor must not present a purely-numeric distinct_id —
			// that would let them evaluate flags as if they were that user.
			// Genuine posthog-js anonymous IDs are UUIDs, never plain integers.
			if ( ctype_digit( $data['distinct_id'] ) ) {
				return null;
			}
			return $data['distinct_id'];
		}
		return null;
	}

	/**
	 * Fetch all feature flags for a distinct ID (cached briefly).
	 *
	 * @param string $distinct_id PostHog distinct ID.
	 * @return array Map of flag key => value (bool or variant string).
	 */
	public static function get_flags( $distinct_id ) {
		$options = pressed_hog_get_options();
		if ( empty( $options['api_key'] ) || '' === $distinct_id ) {
			return array();
		}

		$cache_key = 'pressed_hog_flags_' . md5( $options['api_key'] . '|' . $distinct_id );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_post(
			$options['api_host'] . '/decide/?v=3',
			array(
				'timeout' => 3,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'api_key'     => $options['api_key'],
						'distinct_id' => $distinct_id,
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			// Cache the failure briefly so a down endpoint can't slow every page load.
			set_transient( $cache_key, array(), self::CACHE_TTL );
			return array();
		}

		$body  = json_decode( wp_remote_retrieve_body( $response ), true );
		$flags = ( is_array( $body ) && isset( $body['featureFlags'] ) && is_array( $body['featureFlags'] ) )
			? $body['featureFlags']
			: array();

		set_transient( $cache_key, $flags, self::CACHE_TTL );
		return $flags;
	}

	/**
	 * Check a feature flag for the current visitor (or an explicit distinct ID).
	 *
	 * @param string      $flag_key    Feature flag key.
	 * @param string|null $distinct_id Optional distinct ID; defaults to the current visitor.
	 * @return bool|string False when off/unknown, true when on, or the variant key.
	 */
	public static function get_flag( $flag_key, $distinct_id = null ) {
		if ( null === $distinct_id ) {
			$distinct_id = self::get_distinct_id();
		}
		if ( null === $distinct_id ) {
			return false;
		}
		$flags = self::get_flags( $distinct_id );
		return $flags[ $flag_key ] ?? false;
	}

	/**
	 * Shortcode: [posthog_flag key="my-flag"]shown when enabled[/posthog_flag]
	 * With a variant: [posthog_flag key="my-flag" variant="test"]...[/posthog_flag]
	 *
	 * @param array       $atts    Shortcode attributes.
	 * @param string|null $content Enclosed content.
	 * @return string
	 */
	public static function shortcode( $atts, $content = null ) {
		$atts = shortcode_atts(
			array(
				'key'     => '',
				'variant' => '',
			),
			$atts,
			'posthog_flag'
		);

		if ( '' === $atts['key'] || null === $content ) {
			return '';
		}

		$value = self::get_flag( $atts['key'] );

		$matches = ( '' !== $atts['variant'] )
			? ( $value === $atts['variant'] )
			: (bool) $value;

		return $matches ? do_shortcode( $content ) : '';
	}
}

/**
 * Template helper: is a PostHog feature flag enabled for the current visitor?
 *
 * @param string      $flag_key    Feature flag key.
 * @param string|null $distinct_id Optional explicit distinct ID.
 * @return bool
 */
function pressed_hog_is_feature_enabled( $flag_key, $distinct_id = null ) {
	return (bool) Pressed_Hog_Flags::get_flag( $flag_key, $distinct_id );
}

/**
 * Template helper: get a feature flag's value (bool or variant string).
 *
 * @param string      $flag_key    Feature flag key.
 * @param string|null $distinct_id Optional explicit distinct ID.
 * @return bool|string
 */
function pressed_hog_get_feature_flag( $flag_key, $distinct_id = null ) {
	return Pressed_Hog_Flags::get_flag( $flag_key, $distinct_id );
}
