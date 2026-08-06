<?php
/**
 * Reverse proxy: serves PostHog through the site's own domain so tracking
 * isn't blocked by ad-blockers that filter PostHog's domains.
 *
 * A rewrite rule maps /{slug}/... to a handler that forwards the request
 * server-side to the configured PostHog host (or its asset host for
 * /static/...), passing the visitor's IP along in X-Forwarded-For.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_Proxy {

	const QUERY_VAR  = 'pressed_hog_proxy_path';
	const FLUSH_FLAG = 'pressed_hog_flush_rewrite';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_rewrite' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'maybe_handle' ) );
	}

	public static function is_enabled() {
		$options = pressed_hog_get_options();
		return ! empty( $options['proxy_enabled'] ) && ! empty( $options['api_key'] );
	}

	public static function slug() {
		$options = pressed_hog_get_options();
		return $options['proxy_slug'] ? $options['proxy_slug'] : 'phog';
	}

	/**
	 * The local base URL posthog-js should use as api_host.
	 *
	 * @return string
	 */
	public static function base_url() {
		return untrailingslashit( home_url( '/' . self::slug() ) );
	}

	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	public static function register_rewrite() {
		$options = pressed_hog_get_options();
		if ( ! empty( $options['proxy_enabled'] ) ) {
			add_rewrite_rule(
				'^' . self::slug() . '/(.+)$',
				'index.php?' . self::QUERY_VAR . '=$matches[1]',
				'top'
			);
		}
		if ( get_option( self::FLUSH_FLAG ) ) {
			delete_option( self::FLUSH_FLAG );
			flush_rewrite_rules();
		}
	}

	public static function maybe_handle( $wp ) {
		if ( empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			return;
		}
		if ( ! self::is_enabled() ) {
			status_header( 404 );
			exit;
		}
		self::forward( (string) $wp->query_vars[ self::QUERY_VAR ] );
	}

	/**
	 * Forward the current request to PostHog and stream the response back.
	 *
	 * The target host is always the configured PostHog host (or its asset
	 * domain for /static/), so this cannot be used as an open proxy.
	 *
	 * @param string $path Path after the proxy slug.
	 */
	private static function forward( $path ) {
		$options = pressed_hog_get_options();
		$path    = ltrim( $path, '/' );

		$target_host = 0 === strpos( $path, 'static/' )
			? str_replace( '.i.posthog.com', '-assets.i.posthog.com', $options['api_host'] )
			: $options['api_host'];

		$url    = $target_host . '/' . $path;
		$params = wp_unslash( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- passthrough of PostHog client params.
		unset( $params[ self::QUERY_VAR ] );
		if ( $params ) {
			$url = add_query_arg( $params, $url );
		}

		$headers = array();
		if ( ! empty( $_SERVER['CONTENT_TYPE'] ) ) {
			$headers['Content-Type'] = sanitize_text_field( wp_unslash( $_SERVER['CONTENT_TYPE'] ) );
		}
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$headers['User-Agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}
		if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
			$headers['X-Forwarded-For'] = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$args   = array(
			'method'      => $method,
			'timeout'     => 10,
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = file_get_contents( 'php://input' );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			status_header( 502 );
			header( 'Content-Type: application/json' );
			echo '{"status":0}';
			exit;
		}

		status_header( wp_remote_retrieve_response_code( $response ) );
		foreach ( array( 'content-type', 'cache-control' ) as $name ) {
			$value = wp_remote_retrieve_header( $response, $name );
			if ( is_array( $value ) ) {
				$value = reset( $value );
			}
			if ( $value ) {
				header( ucwords( $name, '-' ) . ': ' . $value );
			}
		}
		echo wp_remote_retrieve_body( $response ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw passthrough of the PostHog response (JS/JSON), not HTML.
		exit;
	}
}
