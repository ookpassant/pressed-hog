<?php
/**
 * In-dashboard analytics: a "PostHog" admin page and a WP dashboard widget,
 * powered by PostHog's Query API (HogQL) with a personal API key.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_Analytics {

	const CACHE_TTL = 300; // seconds

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'add_widget' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function is_configured() {
		$options = pressed_hog_get_options();
		return ! empty( $options['personal_api_key'] ) && ! empty( $options['project_id'] );
	}

	public static function add_menu() {
		add_menu_page(
			__( 'PostHog Analytics', 'pressed-hog' ),
			__( 'PostHog', 'pressed-hog' ),
			'manage_options',
			'pressed-hog-analytics',
			array( __CLASS__, 'render_page' ),
			'dashicons-chart-area',
			3
		);
	}

	public static function enqueue( $hook ) {
		if ( 'toplevel_page_pressed-hog-analytics' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pressed-hog-analytics', PRESSED_HOG_URL . 'assets/css/analytics.css', array(), PRESSED_HOG_VERSION );
		wp_enqueue_script( 'pressed-hog-analytics', PRESSED_HOG_URL . 'assets/js/analytics.js', array(), PRESSED_HOG_VERSION, true );
	}

	/* ---------------------------------------------------------------------
	 * Query API client
	 * ------------------------------------------------------------------- */

	/**
	 * Run a HogQL query against the PostHog Query API (cached).
	 *
	 * @param string $hogql HogQL statement.
	 * @return array|WP_Error Result rows (arrays of columns).
	 */
	private static function query( $hogql ) {
		$options   = pressed_hog_get_options();
		$cache_key = 'pressed_hog_q_' . md5( $options['project_id'] . '|' . $hogql );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$response = wp_remote_post(
			pressed_hog_app_host() . '/api/projects/' . absint( $options['project_id'] ) . '/query/',
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $options['personal_api_key'],
				),
				'body'    => wp_json_encode(
					array(
						'query' => array(
							'kind'    => 'HogQLQuery',
							'query'   => $hogql,
							'filters' => array( 'filterTestAccounts' => true ),
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$detail = is_array( $body ) && ! empty( $body['detail'] ) ? $body['detail'] : (string) $code;
			return new WP_Error( 'pressed_hog_query_failed', $detail );
		}

		$rows = ( is_array( $body ) && isset( $body['results'] ) && is_array( $body['results'] ) ) ? $body['results'] : array();
		set_transient( $cache_key, $rows, self::CACHE_TTL );
		return $rows;
	}

	/**
	 * Summary numbers for a period plus the period before it (one query).
	 *
	 * @param int $days Period length in days.
	 * @return array|WP_Error
	 */
	public static function get_summary( $days ) {
		$days   = absint( $days );
		$double = $days * 2;
		$rows   = self::query(
			'SELECT ' .
			"countIf(timestamp >= now() - INTERVAL {$days} DAY) AS pageviews, " .
			"uniqIf(person_id, timestamp >= now() - INTERVAL {$days} DAY) AS visitors, " .
			"countIf(timestamp < now() - INTERVAL {$days} DAY) AS prev_pageviews, " .
			"uniqIf(person_id, timestamp < now() - INTERVAL {$days} DAY) AS prev_visitors " .
			"FROM events WHERE event = '\$pageview' AND timestamp >= now() - INTERVAL {$double} DAY"
		);
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		$row = isset( $rows[0] ) ? $rows[0] : array( 0, 0, 0, 0 );
		return array(
			'pageviews'      => (int) $row[0],
			'visitors'       => (int) $row[1],
			'prev_pageviews' => (int) $row[2],
			'prev_visitors'  => (int) $row[3],
		);
	}

	/**
	 * All data the analytics page needs for a period.
	 *
	 * @param int $days Period length in days.
	 * @return array|WP_Error
	 */
	public static function get_stats( $days ) {
		$days    = absint( $days );
		$summary = self::get_summary( $days );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$base_where = "event = '\$pageview' AND timestamp >= now() - INTERVAL {$days} DAY";

		$series = self::query(
			'SELECT toDate(timestamp) AS day, count() AS pageviews, uniq(person_id) AS visitors ' .
			"FROM events WHERE {$base_where} GROUP BY day ORDER BY day"
		);
		$pages = self::query(
			'SELECT properties.$pathname AS path, count() AS views, uniq(person_id) AS visitors ' .
			"FROM events WHERE {$base_where} GROUP BY path ORDER BY views DESC LIMIT 10"
		);
		$referrers = self::query(
			'SELECT properties.$referring_domain AS ref, count() AS views ' .
			"FROM events WHERE {$base_where} AND properties.\$referring_domain IS NOT NULL AND properties.\$referring_domain != '\$direct' " .
			'GROUP BY ref ORDER BY views DESC LIMIT 10'
		);
		$devices = self::query(
			'SELECT properties.$device_type AS device, uniq(person_id) AS visitors ' .
			"FROM events WHERE {$base_where} AND properties.\$device_type IS NOT NULL " .
			'GROUP BY device ORDER BY visitors DESC LIMIT 6'
		);

		return array(
			'summary'   => $summary,
			'series'    => is_wp_error( $series ) ? array() : $series,
			'pages'     => is_wp_error( $pages ) ? array() : $pages,
			'referrers' => is_wp_error( $referrers ) ? array() : $referrers,
			'devices'   => is_wp_error( $devices ) ? array() : $devices,
		);
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------- */

	private static function render_delta( $current, $previous ) {
		if ( $previous <= 0 ) {
			return '<span class="ph-delta ph-delta--none">—</span>';
		}
		$pct = round( ( $current - $previous ) / $previous * 100 );
		if ( 0 === $pct ) {
			return '<span class="ph-delta ph-delta--none">±0%</span>';
		}
		$up = $pct > 0;
		return sprintf(
			'<span class="ph-delta %s"><span aria-hidden="true">%s</span> %s%% <span class="screen-reader-text">%s</span></span>',
			$up ? 'ph-delta--up' : 'ph-delta--down',
			$up ? '▲' : '▼',
			esc_html( abs( $pct ) ),
			$up ? esc_html__( 'up vs previous period', 'pressed-hog' ) : esc_html__( 'down vs previous period', 'pressed-hog' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options  = pressed_hog_get_options();
		$app_host = pressed_hog_app_host();

		echo '<div class="wrap pressed-hog-analytics">';
		printf(
			'<h1>%s <a class="page-title-action" href="%s" target="_blank" rel="noopener noreferrer">%s</a></h1>',
			esc_html__( 'PostHog Analytics', 'pressed-hog' ),
			esc_url( $app_host ),
			esc_html__( 'Open PostHog ↗', 'pressed-hog' )
		);

		if ( ! self::is_configured() ) {
			self::render_setup_prompt( $app_host );
			echo '</div>';
			return;
		}

		$range = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 30; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch.
		if ( ! in_array( $range, array( 7, 30, 90 ), true ) ) {
			$range = 30;
		}

		$stats = self::get_stats( $range );
		if ( is_wp_error( $stats ) ) {
			printf(
				'<div class="notice notice-error"><p>%s <code>%s</code></p><p>%s</p></div></div>',
				esc_html__( 'Could not fetch data from PostHog:', 'pressed-hog' ),
				esc_html( $stats->get_error_message() ),
				esc_html__( 'Check the personal API key and project ID on the Pressed Hog settings page, and that the key has Query read access.', 'pressed-hog' )
			);
			return;
		}

		// Range switcher.
		echo '<ul class="subsubsub ph-range">';
		foreach ( array( 7, 30, 90 ) as $option_range ) {
			printf(
				'<li><a href="%s" class="%s">%s</a></li>',
				esc_url( add_query_arg( 'range', $option_range, admin_url( 'admin.php?page=pressed-hog-analytics' ) ) ),
				$option_range === $range ? 'current' : '',
				/* translators: %d: number of days. */
				esc_html( sprintf( __( 'Last %d days', 'pressed-hog' ), $option_range ) )
			);
		}
		echo '</ul><div class="clear"></div>';

		$summary = $stats['summary'];

		// Stat tiles.
		echo '<div class="ph-tiles">';
		printf(
			'<div class="ph-tile"><span class="ph-tile__label">%s</span><span class="ph-tile__value">%s</span>%s</div>',
			esc_html__( 'Pageviews', 'pressed-hog' ),
			esc_html( number_format_i18n( $summary['pageviews'] ) ),
			self::render_delta( $summary['pageviews'], $summary['prev_pageviews'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
		printf(
			'<div class="ph-tile"><span class="ph-tile__label">%s</span><span class="ph-tile__value">%s</span>%s</div>',
			esc_html__( 'Unique visitors', 'pressed-hog' ),
			esc_html( number_format_i18n( $summary['visitors'] ) ),
			self::render_delta( $summary['visitors'], $summary['prev_visitors'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
		printf(
			'<div class="ph-tile"><span class="ph-tile__label">%s</span><span class="ph-tile__value">%s</span></div>',
			esc_html__( 'Views per visitor', 'pressed-hog' ),
			esc_html( $summary['visitors'] > 0 ? number_format_i18n( $summary['pageviews'] / $summary['visitors'], 1 ) : '—' )
		);
		echo '</div>';

		// Time series chart.
		$series_data = array();
		foreach ( $stats['series'] as $row ) {
			$series_data[] = array(
				'day'       => (string) $row[0],
				'pageviews' => (int) $row[1],
				'visitors'  => (int) $row[2],
			);
		}
		echo '<div class="ph-card">';
		printf( '<h2 class="ph-card__title">%s</h2>', esc_html__( 'Traffic over time', 'pressed-hog' ) );
		echo '<div class="ph-legend">';
		printf( '<span class="ph-legend__item"><span class="ph-chip ph-chip--pageviews" aria-hidden="true"></span>%s</span>', esc_html__( 'Pageviews', 'pressed-hog' ) );
		printf( '<span class="ph-legend__item"><span class="ph-chip ph-chip--visitors" aria-hidden="true"></span>%s</span>', esc_html__( 'Unique visitors', 'pressed-hog' ) );
		echo '</div>';
		printf(
			'<script type="application/json" id="pressed-hog-chart-data">%s</script>',
			wp_json_encode( $series_data ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON in a non-executed script block.
		);
		printf(
			'<div id="pressed-hog-chart" data-empty-text="%s"></div>',
			esc_attr__( 'No pageview data for this period yet.', 'pressed-hog' )
		);
		// Table view of the chart data (accessibility fallback).
		printf( '<details class="ph-table-view"><summary>%s</summary>', esc_html__( 'View data as table', 'pressed-hog' ) );
		printf(
			'<table class="widefat striped"><thead><tr><th>%s</th><th>%s</th><th>%s</th></tr></thead><tbody>',
			esc_html__( 'Day', 'pressed-hog' ),
			esc_html__( 'Pageviews', 'pressed-hog' ),
			esc_html__( 'Unique visitors', 'pressed-hog' )
		);
		foreach ( $series_data as $point ) {
			printf(
				'<tr><td>%s</td><td class="ph-num">%s</td><td class="ph-num">%s</td></tr>',
				esc_html( $point['day'] ),
				esc_html( number_format_i18n( $point['pageviews'] ) ),
				esc_html( number_format_i18n( $point['visitors'] ) )
			);
		}
		echo '</tbody></table></details></div>';

		// Breakdown tables.
		echo '<div class="ph-columns">';
		self::render_table(
			__( 'Top pages', 'pressed-hog' ),
			array( __( 'Path', 'pressed-hog' ), __( 'Views', 'pressed-hog' ), __( 'Visitors', 'pressed-hog' ) ),
			$stats['pages']
		);
		self::render_table(
			__( 'Top referrers', 'pressed-hog' ),
			array( __( 'Domain', 'pressed-hog' ), __( 'Views', 'pressed-hog' ) ),
			$stats['referrers']
		);
		self::render_table(
			__( 'Devices', 'pressed-hog' ),
			array( __( 'Device', 'pressed-hog' ), __( 'Visitors', 'pressed-hog' ) ),
			$stats['devices']
		);
		echo '</div>';

		// Optional embedded shared dashboard.
		if ( ! empty( $options['embed_url'] ) ) {
			$embed = str_replace( '/shared/', '/embedded/', $options['embed_url'] );
			echo '<div class="ph-card">';
			printf( '<h2 class="ph-card__title">%s</h2>', esc_html__( 'Embedded PostHog dashboard', 'pressed-hog' ) );
			printf(
				'<iframe class="ph-embed" src="%s" loading="lazy" title="%s"></iframe>',
				esc_url( $embed ),
				esc_attr__( 'PostHog dashboard', 'pressed-hog' )
			);
			echo '</div>';
		}

		echo '</div>';
	}

	private static function render_table( $title, $headers, $rows ) {
		echo '<div class="ph-card">';
		printf( '<h2 class="ph-card__title">%s</h2>', esc_html( $title ) );
		if ( empty( $rows ) ) {
			printf( '<p class="ph-empty">%s</p>', esc_html__( 'No data for this period.', 'pressed-hog' ) );
			echo '</div>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( $headers as $index => $header ) {
			printf( '<th class="%s">%s</th>', 0 === $index ? '' : 'ph-num', esc_html( $header ) );
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr>';
			foreach ( array_values( (array) $row ) as $index => $cell ) {
				if ( count( $headers ) <= $index ) {
					break;
				}
				printf(
					'<td class="%s">%s</td>',
					0 === $index ? 'ph-label-cell' : 'ph-num',
					esc_html( 0 === $index ? (string) $cell : number_format_i18n( (float) $cell ) )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table></div>';
	}

	private static function render_setup_prompt( $app_host ) {
		echo '<div class="ph-card ph-card--setup">';
		printf( '<h2>%s</h2>', esc_html__( 'Connect the dashboard to PostHog', 'pressed-hog' ) );
		printf( '<p>%s</p>', esc_html__( 'The analytics page reads data via PostHog’s Query API, which needs two extra values (both on the Pressed Hog settings page):', 'pressed-hog' ) );
		echo '<ol>';
		printf(
			'<li>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a> %s</li>',
			esc_html__( 'A personal API key — create one at', 'pressed-hog' ),
			esc_url( $app_host . '/settings/user-api-keys' ),
			esc_html__( 'PostHog → Settings → Personal API keys ↗', 'pressed-hog' ),
			esc_html__( '(read-only "Query" scope is enough).', 'pressed-hog' )
		);
		printf(
			'<li>%s <a href="%s" target="_blank" rel="noopener noreferrer">%s</a></li>',
			esc_html__( 'Your numeric project ID — shown at', 'pressed-hog' ),
			esc_url( $app_host . '/settings/project' ),
			esc_html__( 'PostHog → Settings → Project ↗', 'pressed-hog' )
		);
		echo '</ol>';
		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p>',
			esc_url( admin_url( 'options-general.php?page=pressed-hog' ) ),
			esc_html__( 'Open Pressed Hog settings', 'pressed-hog' )
		);
		echo '</div>';
	}

	/* ---------------------------------------------------------------------
	 * Dashboard widget
	 * ------------------------------------------------------------------- */

	public static function add_widget() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_add_dashboard_widget(
			'pressed_hog_summary',
			__( 'PostHog — last 7 days', 'pressed-hog' ),
			array( __CLASS__, 'render_widget' )
		);
	}

	public static function render_widget() {
		if ( ! self::is_configured() ) {
			printf(
				'<p>%s <a href="%s">%s</a></p>',
				esc_html__( 'Connect a personal API key to see stats here.', 'pressed-hog' ),
				esc_url( admin_url( 'admin.php?page=pressed-hog-analytics' ) ),
				esc_html__( 'Set up', 'pressed-hog' )
			);
			return;
		}

		$summary = self::get_summary( 7 );
		if ( is_wp_error( $summary ) ) {
			printf( '<p>%s</p>', esc_html__( 'Could not fetch data from PostHog right now.', 'pressed-hog' ) );
			return;
		}

		echo '<div class="ph-widget-tiles">';
		printf(
			'<div class="ph-widget-tile"><span class="ph-widget-tile__value">%s</span><span class="ph-widget-tile__label">%s</span>%s</div>',
			esc_html( number_format_i18n( $summary['pageviews'] ) ),
			esc_html__( 'Pageviews', 'pressed-hog' ),
			self::render_delta( $summary['pageviews'], $summary['prev_pageviews'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
		printf(
			'<div class="ph-widget-tile"><span class="ph-widget-tile__value">%s</span><span class="ph-widget-tile__label">%s</span>%s</div>',
			esc_html( number_format_i18n( $summary['visitors'] ) ),
			esc_html__( 'Unique visitors', 'pressed-hog' ),
			self::render_delta( $summary['visitors'], $summary['prev_visitors'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		);
		echo '</div>';
		printf(
			'<p><a href="%s">%s</a></p>',
			esc_url( admin_url( 'admin.php?page=pressed-hog-analytics' ) ),
			esc_html__( 'View full analytics →', 'pressed-hog' )
		);

		// Minimal styling so the widget doesn't need the full stylesheet.
		echo '<style>.ph-widget-tiles{display:flex;gap:24px;margin:4px 0 8px}.ph-widget-tile__value{display:block;font-size:22px;font-weight:600;line-height:1.2}.ph-widget-tile__label{display:block;color:#646970}.ph-delta--up{color:#006300}.ph-delta--down{color:#d03b3b}.ph-delta--none{color:#898781}</style>';
	}
}
