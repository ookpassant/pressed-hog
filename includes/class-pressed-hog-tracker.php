<?php
/**
 * Front-end output: the posthog-js snippet, user identification, and consent handling.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_Tracker {

	/**
	 * The official posthog-js loader snippet. The real library is fetched
	 * asynchronously from the configured api_host's asset domain.
	 */
	const SNIPPET = '!function(t,e){var o,n,p,r;e.__SV||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="init capture register register_once register_for_session unregister unregister_for_session getFeatureFlag getFeatureFlagPayload isFeatureEnabled reloadFeatureFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSessionId getSurveys getActiveMatchingSurveys renderSurvey canRenderSurvey identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException loadToolbar get_property getSessionProperty createPersonProfile opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing clear_opt_in_out_capturing debug".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);';

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output_snippet' ), 1 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_consent_assets' ) );
	}

	/**
	 * Whether tracking should be emitted at all for the current request.
	 *
	 * @return bool
	 */
	public static function is_active() {
		$options = pressed_hog_get_options();
		if ( empty( $options['api_key'] ) || is_admin() ) {
			return false;
		}

		if ( is_user_logged_in() ) {
			$user     = wp_get_current_user();
			$excluded = (array) $options['excluded_roles'];
			if ( array_intersect( $user->roles, $excluded ) ) {
				return false;
			}
		}

		/**
		 * Filter whether the PostHog snippet is output for the current request.
		 *
		 * @param bool $active
		 */
		return (bool) apply_filters( 'pressed_hog_is_active', true );
	}

	public static function output_snippet() {
		if ( ! self::is_active() ) {
			return;
		}

		$options        = pressed_hog_get_options();
		$consent_gated  = 'none' !== $options['consent_mode'];

		$api_host = $options['api_host'];
		$ui_host  = null;
		if ( Pressed_Hog_Proxy::is_enabled() ) {
			$api_host = Pressed_Hog_Proxy::base_url();
			$ui_host  = pressed_hog_app_host();
		}

		$config = array(
			'api_host'                  => $api_host,
			'capture_pageview'          => (bool) $options['capture_pageviews'],
			'autocapture'               => (bool) $options['autocapture'],
			'disable_session_recording' => empty( $options['session_recording'] ),
			'disable_surveys'           => empty( $options['enable_surveys'] ),
			'person_profiles'           => 'identified_only',
		);
		if ( $ui_host ) {
			$config['ui_host'] = $ui_host;
		}
		if ( $consent_gated ) {
			$config['opt_out_capturing_by_default'] = true;
		}

		/**
		 * Filter the posthog.init() config object before output.
		 *
		 * @param array $config
		 */
		$config = apply_filters( 'pressed_hog_init_config', $config );

		$identify_js = '';
		if ( ! empty( $options['identify_users'] ) && is_user_logged_in() ) {
			$user        = wp_get_current_user();
			$identify_js = sprintf(
				'window.pressedHogIdentify=function(){posthog.identify(%s,%s);};%s',
				wp_json_encode( (string) $user->ID ),
				wp_json_encode(
					array(
						'email' => $user->user_email,
						'name'  => $user->display_name,
					)
				),
				$consent_gated ? '' : 'window.pressedHogIdentify();'
			);
		}

		printf(
			"<script>\n%s\nposthog.init(%s,%s);%s\n</script>\n",
			self::SNIPPET, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static, trusted JS snippet.
			wp_json_encode( $options['api_key'] ),
			wp_json_encode( $config ),
			$identify_js // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from wp_json_encode() above.
		);
	}

	public static function enqueue_consent_assets() {
		if ( ! self::is_active() ) {
			return;
		}

		$options = pressed_hog_get_options();
		if ( 'none' === $options['consent_mode'] ) {
			return;
		}

		wp_enqueue_script(
			'pressed-hog-consent',
			PRESSED_HOG_URL . 'assets/js/consent.js',
			array(),
			PRESSED_HOG_VERSION,
			array( 'in_footer' => true )
		);
		wp_localize_script(
			'pressed-hog-consent',
			'pressedHogConsent',
			array(
				'mode'        => $options['consent_mode'],
				'cookieName'  => $options['consent_cookie_name'],
				'cookieValue' => $options['consent_cookie_value'],
				'text'        => $options['banner_text'],
				'accept'      => $options['banner_accept'],
				'decline'     => $options['banner_decline'],
			)
		);

		if ( 'banner' === $options['consent_mode'] ) {
			wp_enqueue_style(
				'pressed-hog-consent',
				PRESSED_HOG_URL . 'assets/css/consent.css',
				array(),
				PRESSED_HOG_VERSION
			);
		}
	}
}
