<?php
/**
 * Admin settings page (Settings → Pressed Hog), built on the WordPress Settings API.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_Settings {

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_notice_missing_key' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( PRESSED_HOG_FILE ),
			array( __CLASS__, 'action_links' )
		);
	}

	public static function action_links( $links ) {
		$settings_link = sprintf(
			'<a href="%s">%s</a>',
			esc_url( admin_url( 'options-general.php?page=pressed-hog' ) ),
			esc_html__( 'Settings', 'pressed-hog' )
		);
		array_unshift( $links, $settings_link );
		return $links;
	}

	public static function maybe_notice_missing_key() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = pressed_hog_get_options();
		$screen  = get_current_screen();
		if ( ! empty( $options['api_key'] ) || ( $screen && 'settings_page_pressed-hog' === $screen->id ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Pressed Hog is active but not tracking yet — add your PostHog project API key to get started.', 'pressed-hog' ),
			esc_url( admin_url( 'options-general.php?page=pressed-hog' ) ),
			esc_html__( 'Open settings', 'pressed-hog' )
		);
	}

	public static function add_menu() {
		add_options_page(
			__( 'Pressed Hog – PostHog', 'pressed-hog' ),
			__( 'Pressed Hog', 'pressed-hog' ),
			'manage_options',
			'pressed-hog',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function register() {
		register_setting(
			'pressed_hog',
			PRESSED_HOG_OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => pressed_hog_default_options(),
			)
		);

		// Connection.
		add_settings_section(
			'pressed_hog_connection',
			__( 'Connection', 'pressed-hog' ),
			function () {
				printf(
					'<p>%s</p>',
					esc_html__( 'Find your project API key in PostHog under Settings → Project. The key is public (it ships in page source), so it is safe to store here.', 'pressed-hog' )
				);
			},
			'pressed-hog'
		);
		self::add_field( 'api_key', __( 'Project API key', 'pressed-hog' ), 'pressed_hog_connection', 'render_api_key' );
		self::add_field( 'api_host', __( 'PostHog host', 'pressed-hog' ), 'pressed_hog_connection', 'render_api_host' );

		// Tracking.
		add_settings_section( 'pressed_hog_tracking', __( 'Tracking', 'pressed-hog' ), '__return_null', 'pressed-hog' );
		self::add_field( 'capture_pageviews', __( 'Capture pageviews', 'pressed-hog' ), 'pressed_hog_tracking', 'render_checkbox', array(
			'key'         => 'capture_pageviews',
			'description' => __( 'Send a $pageview event on every page load.', 'pressed-hog' ),
		) );
		self::add_field( 'autocapture', __( 'Autocapture', 'pressed-hog' ), 'pressed_hog_tracking', 'render_checkbox', array(
			'key'         => 'autocapture',
			'description' => __( 'Automatically capture clicks, form submissions, and other front-end interactions.', 'pressed-hog' ),
		) );
		self::add_field( 'session_recording', __( 'Session recording', 'pressed-hog' ), 'pressed_hog_tracking', 'render_checkbox', array(
			'key'         => 'session_recording',
			'description' => __( 'Enable PostHog session replay. Recording must also be enabled in your PostHog project settings.', 'pressed-hog' ),
		) );
		self::add_field( 'enable_surveys', __( 'Surveys', 'pressed-hog' ), 'pressed_hog_tracking', 'render_checkbox', array(
			'key'         => 'enable_surveys',
			'description' => __( 'Allow PostHog popover surveys to appear on your site.', 'pressed-hog' ),
		) );
		self::add_field( 'identify_users', __( 'Identify logged-in users', 'pressed-hog' ), 'pressed_hog_tracking', 'render_checkbox', array(
			'key'         => 'identify_users',
			'description' => __( 'Link events from logged-in visitors to their WordPress account (sends user ID, email, and display name to PostHog — personal data, so check your privacy policy).', 'pressed-hog' ),
		) );
		self::add_field( 'excluded_roles', __( 'Do not track these roles', 'pressed-hog' ), 'pressed_hog_tracking', 'render_excluded_roles' );

		// Privacy & consent.
		add_settings_section( 'pressed_hog_consent', __( 'Privacy & consent', 'pressed-hog' ), '__return_null', 'pressed-hog' );
		self::add_field( 'consent_mode', __( 'Consent mode', 'pressed-hog' ), 'pressed_hog_consent', 'render_consent_mode' );
		self::add_field( 'banner_text', __( 'Banner text', 'pressed-hog' ), 'pressed_hog_consent', 'render_banner_text' );
		self::add_field( 'banner_buttons', __( 'Banner buttons', 'pressed-hog' ), 'pressed_hog_consent', 'render_banner_buttons' );
		self::add_field( 'consent_cookie', __( 'External consent cookie', 'pressed-hog' ), 'pressed_hog_consent', 'render_consent_cookie' );

		// Integrations.
		add_settings_section( 'pressed_hog_integrations', __( 'Integrations', 'pressed-hog' ), '__return_null', 'pressed-hog' );
		self::add_field( 'woocommerce_events', __( 'WooCommerce events', 'pressed-hog' ), 'pressed_hog_integrations', 'render_woocommerce' );
	}

	private static function add_field( $id, $title, $section, $callback, $args = array() ) {
		add_settings_field( $id, $title, array( __CLASS__, $callback ), 'pressed-hog', $section, $args );
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Pressed Hog – PostHog Analytics', 'pressed-hog' ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'pressed_hog' );
				do_settings_sections( 'pressed-hog' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Field renderers
	 * ------------------------------------------------------------------- */

	public static function render_api_key() {
		$options = pressed_hog_get_options();
		printf(
			'<input type="text" class="regular-text code" name="%s[api_key]" value="%s" placeholder="phc_..." />',
			esc_attr( PRESSED_HOG_OPTION ),
			esc_attr( $options['api_key'] )
		);
	}

	public static function render_api_host() {
		$options = pressed_hog_get_options();
		$presets = array(
			'https://us.i.posthog.com' => __( 'PostHog Cloud US', 'pressed-hog' ),
			'https://eu.i.posthog.com' => __( 'PostHog Cloud EU', 'pressed-hog' ),
		);
		$is_custom = ! isset( $presets[ $options['api_host'] ] );
		?>
		<select id="pressed-hog-host-preset">
			<?php foreach ( $presets as $url => $label ) : ?>
				<option value="<?php echo esc_attr( $url ); ?>" <?php selected( ! $is_custom && $options['api_host'] === $url ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
			<option value="custom" <?php selected( $is_custom ); ?>><?php esc_html_e( 'Self-hosted / custom', 'pressed-hog' ); ?></option>
		</select>
		<input type="url" class="regular-text code" id="pressed-hog-host-input"
			name="<?php echo esc_attr( PRESSED_HOG_OPTION ); ?>[api_host]"
			value="<?php echo esc_attr( $options['api_host'] ); ?>"
			<?php echo $is_custom ? '' : 'readonly'; ?> />
		<p class="description"><?php esc_html_e( 'Where events are sent. Pick a PostHog Cloud region or enter your self-hosted instance URL.', 'pressed-hog' ); ?></p>
		<script>
		(function () {
			var preset = document.getElementById('pressed-hog-host-preset');
			var input = document.getElementById('pressed-hog-host-input');
			preset.addEventListener('change', function () {
				if (preset.value === 'custom') {
					input.readOnly = false;
					input.focus();
				} else {
					input.readOnly = true;
					input.value = preset.value;
				}
			});
		})();
		</script>
		<?php
	}

	public static function render_checkbox( $args ) {
		$options = pressed_hog_get_options();
		$key     = $args['key'];
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s /> %4$s</label>',
			esc_attr( PRESSED_HOG_OPTION ),
			esc_attr( $key ),
			checked( ! empty( $options[ $key ] ), true, false ),
			esc_html( $args['description'] )
		);
	}

	public static function render_excluded_roles() {
		$options  = pressed_hog_get_options();
		$excluded = (array) $options['excluded_roles'];
		$roles    = wp_roles()->get_names();
		echo '<fieldset>';
		foreach ( $roles as $slug => $name ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="%1$s[excluded_roles][]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( PRESSED_HOG_OPTION ),
				esc_attr( $slug ),
				checked( in_array( $slug, $excluded, true ), true, false ),
				esc_html( translate_user_role( $name ) )
			);
		}
		echo '</fieldset>';
		printf(
			'<p class="description">%s</p>',
			esc_html__( 'Logged-in users with a checked role never receive the tracking snippet, keeping your own activity out of your analytics.', 'pressed-hog' )
		);
	}

	public static function render_consent_mode() {
		$options = pressed_hog_get_options();
		$modes   = array(
			'none'     => __( 'No consent gate — start tracking immediately', 'pressed-hog' ),
			'banner'   => __( 'Built-in cookie banner — hold tracking until the visitor accepts', 'pressed-hog' ),
			'external' => __( 'External consent plugin — hold tracking until a consent cookie is set or the JavaScript API is called', 'pressed-hog' ),
		);
		echo '<fieldset>';
		foreach ( $modes as $value => $label ) {
			printf(
				'<label style="display:block;margin-bottom:4px;"><input type="radio" name="%1$s[consent_mode]" value="%2$s" %3$s /> %4$s</label>',
				esc_attr( PRESSED_HOG_OPTION ),
				esc_attr( $value ),
				checked( $options['consent_mode'], $value, false ),
				esc_html( $label )
			);
		}
		echo '</fieldset>';
		printf(
			'<p class="description">%s <code>window.pressedHog.grantConsent()</code> / <code>window.pressedHog.denyConsent()</code></p>',
			esc_html__( 'In external mode, your consent plugin can signal acceptance by calling:', 'pressed-hog' )
		);
	}

	public static function render_banner_text() {
		$options = pressed_hog_get_options();
		printf(
			'<textarea class="large-text" rows="2" name="%s[banner_text]">%s</textarea><p class="description">%s</p>',
			esc_attr( PRESSED_HOG_OPTION ),
			esc_textarea( $options['banner_text'] ),
			esc_html__( 'Shown in the built-in cookie banner (banner mode only).', 'pressed-hog' )
		);
	}

	public static function render_banner_buttons() {
		$options = pressed_hog_get_options();
		printf(
			'<input type="text" name="%1$s[banner_accept]" value="%2$s" placeholder="%3$s" /> <input type="text" name="%1$s[banner_decline]" value="%4$s" placeholder="%5$s" />',
			esc_attr( PRESSED_HOG_OPTION ),
			esc_attr( $options['banner_accept'] ),
			esc_attr__( 'Accept', 'pressed-hog' ),
			esc_attr( $options['banner_decline'] ),
			esc_attr__( 'Decline', 'pressed-hog' )
		);
	}

	public static function render_consent_cookie() {
		$options = pressed_hog_get_options();
		printf(
			'<input type="text" class="regular-text code" name="%1$s[consent_cookie_name]" value="%2$s" /> = <input type="text" class="code" name="%1$s[consent_cookie_value]" value="%3$s" /><p class="description">%4$s</p>',
			esc_attr( PRESSED_HOG_OPTION ),
			esc_attr( $options['consent_cookie_name'] ),
			esc_attr( $options['consent_cookie_value'] ),
			esc_html__( 'In external mode, tracking starts when this cookie equals this value. The built-in banner also stores its decision in this cookie.', 'pressed-hog' )
		);
	}

	public static function render_woocommerce() {
		$options    = pressed_hog_get_options();
		$has_woo    = class_exists( 'WooCommerce' );
		$suffix     = $has_woo ? '' : ' ' . __( '(WooCommerce is not active — this will take effect once it is.)', 'pressed-hog' );
		printf(
			'<label><input type="checkbox" name="%1$s[woocommerce_events]" value="1" %2$s /> %3$s</label>',
			esc_attr( PRESSED_HOG_OPTION ),
			checked( ! empty( $options['woocommerce_events'] ), true, false ),
			esc_html( __( 'Capture product_added_to_cart, checkout_started, and order_completed events.', 'pressed-hog' ) . $suffix )
		);
	}

	/* ---------------------------------------------------------------------
	 * Sanitization
	 * ------------------------------------------------------------------- */

	public static function sanitize( $input ) {
		$defaults = pressed_hog_default_options();
		$input    = is_array( $input ) ? $input : array();
		$clean    = array();

		$clean['api_key'] = sanitize_text_field( $input['api_key'] ?? '' );

		$host = esc_url_raw( trim( $input['api_host'] ?? '' ) );
		$clean['api_host'] = $host ? untrailingslashit( $host ) : $defaults['api_host'];

		foreach ( array( 'capture_pageviews', 'autocapture', 'session_recording', 'enable_surveys', 'identify_users', 'woocommerce_events' ) as $flag ) {
			$clean[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$valid_roles             = array_keys( wp_roles()->get_names() );
		$clean['excluded_roles'] = array_values( array_intersect( (array) ( $input['excluded_roles'] ?? array() ), $valid_roles ) );

		$clean['consent_mode'] = in_array( $input['consent_mode'] ?? 'none', array( 'none', 'banner', 'external' ), true )
			? $input['consent_mode']
			: 'none';

		$cookie_name                  = sanitize_key( str_replace( '-', '_', $input['consent_cookie_name'] ?? '' ) );
		$clean['consent_cookie_name'] = $cookie_name ? $cookie_name : $defaults['consent_cookie_name'];

		$cookie_value                  = sanitize_text_field( $input['consent_cookie_value'] ?? '' );
		$clean['consent_cookie_value'] = '' !== $cookie_value ? $cookie_value : $defaults['consent_cookie_value'];

		$clean['banner_text']    = sanitize_textarea_field( $input['banner_text'] ?? '' );
		$clean['banner_accept']  = sanitize_text_field( $input['banner_accept'] ?? '' );
		$clean['banner_decline'] = sanitize_text_field( $input['banner_decline'] ?? '' );
		if ( '' === $clean['banner_text'] ) {
			$clean['banner_text'] = $defaults['banner_text'];
		}
		if ( '' === $clean['banner_accept'] ) {
			$clean['banner_accept'] = $defaults['banner_accept'];
		}
		if ( '' === $clean['banner_decline'] ) {
			$clean['banner_decline'] = $defaults['banner_decline'];
		}

		return $clean;
	}
}
