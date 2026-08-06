<?php
/**
 * Setup wizard: a guided onboarding screen shown after activation.
 *
 * Steps: 1) Connect (host + API key with live validation), 2) Tracking,
 * 3) Consent, 4) Finish (settings saved + send a test event).
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_Wizard {

	const PAGE_SLUG          = 'pressed-hog-setup';
	const REDIRECT_TRANSIENT = 'pressed_hog_activation_redirect';
	const NONCE_ACTION       = 'pressed_hog_wizard';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 20 );
		add_action( 'admin_init', array( __CLASS__, 'maybe_redirect' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_ajax_pressed_hog_validate_key', array( __CLASS__, 'ajax_validate_key' ) );
		add_action( 'wp_ajax_pressed_hog_save_wizard', array( __CLASS__, 'ajax_save_wizard' ) );
		add_action( 'wp_ajax_pressed_hog_test_event', array( __CLASS__, 'ajax_test_event' ) );
	}

	public static function url() {
		return admin_url( 'options-general.php?page=' . self::PAGE_SLUG );
	}

	/**
	 * Register the wizard as a Settings subpage, then hide it from the menu
	 * (it stays reachable by URL).
	 */
	public static function register_page() {
		add_submenu_page(
			'options-general.php',
			__( 'Pressed Hog Setup', 'pressed-hog' ),
			__( 'Pressed Hog Setup', 'pressed-hog' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
		remove_submenu_page( 'options-general.php', self::PAGE_SLUG );
	}

	/**
	 * After single-plugin activation, send admins to the wizard — but only
	 * when no API key is configured yet.
	 */
	public static function maybe_redirect() {
		if ( ! get_transient( self::REDIRECT_TRANSIENT ) ) {
			return;
		}
		delete_transient( self::REDIRECT_TRANSIENT );

		if (
			isset( $_GET['activate-multi'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			|| wp_doing_ajax()
			|| is_network_admin()
			|| ! current_user_can( 'manage_options' )
		) {
			return;
		}

		$options = pressed_hog_get_options();
		if ( ! empty( $options['api_key'] ) ) {
			return;
		}

		wp_safe_redirect( self::url() );
		exit;
	}

	public static function enqueue( $hook ) {
		if ( 'settings_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		wp_enqueue_style( 'pressed-hog-wizard', PRESSED_HOG_URL . 'assets/css/wizard.css', array(), PRESSED_HOG_VERSION );
		wp_enqueue_script( 'pressed-hog-wizard', PRESSED_HOG_URL . 'assets/js/wizard.js', array(), PRESSED_HOG_VERSION, true );

		$options = pressed_hog_get_options();
		wp_localize_script(
			'pressed-hog-wizard',
			'pressedHogWizard',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
				'options'     => $options,
				'settingsUrl' => admin_url( 'options-general.php?page=pressed-hog' ),
				'siteUrl'     => home_url( '/' ),
				'i18n'        => array(
					'validating'    => __( 'Checking your key…', 'pressed-hog' ),
					'valid'         => __( 'Connected! Your key works on this host.', 'pressed-hog' ),
					'invalid'       => __( 'That key was rejected by this host. Double-check the key and the region.', 'pressed-hog' ),
					'unreachable'   => __( 'Could not reach the PostHog host from your server. You can continue anyway — browser tracking may still work.', 'pressed-hog' ),
					'keyMissing'    => __( 'Enter your project API key first.', 'pressed-hog' ),
					'saving'        => __( 'Saving…', 'pressed-hog' ),
					'saveFailed'    => __( 'Saving failed. Please try again.', 'pressed-hog' ),
					'sendingTest'   => __( 'Sending test event…', 'pressed-hog' ),
					'testSent'      => __( 'Test event sent! Look for "pressed_hog_test_event" in your PostHog activity feed.', 'pressed-hog' ),
					'testFailed'    => __( 'The test event was not accepted. Check your key and host on the settings page.', 'pressed-hog' ),
					'continueAnyway' => __( 'Continue anyway', 'pressed-hog' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Page markup
	 * ------------------------------------------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$options = pressed_hog_get_options();
		?>
		<div class="wrap pressed-hog-wizard-wrap">
			<div class="pressed-hog-wizard" id="pressed-hog-wizard">
				<h1 class="pressed-hog-wizard__title"><?php esc_html_e( 'Set up PostHog', 'pressed-hog' ); ?></h1>

				<ol class="pressed-hog-wizard__steps">
					<li data-step-label="1" class="is-current"><?php esc_html_e( 'Connect', 'pressed-hog' ); ?></li>
					<li data-step-label="2"><?php esc_html_e( 'Tracking', 'pressed-hog' ); ?></li>
					<li data-step-label="3"><?php esc_html_e( 'Consent', 'pressed-hog' ); ?></li>
					<li data-step-label="4"><?php esc_html_e( 'Done', 'pressed-hog' ); ?></li>
				</ol>

				<noscript>
					<p>
						<?php esc_html_e( 'The setup wizard needs JavaScript.', 'pressed-hog' ); ?>
						<a href="<?php echo esc_url( admin_url( 'options-general.php?page=pressed-hog' ) ); ?>"><?php esc_html_e( 'Use the settings page instead.', 'pressed-hog' ); ?></a>
					</p>
				</noscript>

				<!-- Step 1: Connect -->
				<section class="pressed-hog-wizard__step is-active" data-step="1">
					<h2><?php esc_html_e( 'Connect to PostHog', 'pressed-hog' ); ?></h2>
					<p><?php esc_html_e( 'Choose where your PostHog project lives and paste its API key.', 'pressed-hog' ); ?></p>

					<label class="pressed-hog-field">
						<span><?php esc_html_e( 'Region', 'pressed-hog' ); ?></span>
						<select id="phw-region">
							<option value="https://us.i.posthog.com"><?php esc_html_e( 'PostHog Cloud US', 'pressed-hog' ); ?></option>
							<option value="https://eu.i.posthog.com"><?php esc_html_e( 'PostHog Cloud EU', 'pressed-hog' ); ?></option>
							<option value="custom"><?php esc_html_e( 'Self-hosted / custom', 'pressed-hog' ); ?></option>
						</select>
					</label>

					<label class="pressed-hog-field" id="phw-host-field" hidden>
						<span><?php esc_html_e( 'Instance URL', 'pressed-hog' ); ?></span>
						<input type="url" id="phw-host" class="regular-text code" placeholder="https://posthog.example.com" />
					</label>

					<label class="pressed-hog-field">
						<span><?php esc_html_e( 'Project API key', 'pressed-hog' ); ?></span>
						<input type="text" id="phw-key" class="regular-text code" placeholder="phc_…" value="<?php echo esc_attr( $options['api_key'] ); ?>" />
					</label>

					<p class="description">
						<a id="phw-key-link" href="https://us.posthog.com/settings/project" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Find your key in PostHog → Settings → Project ↗', 'pressed-hog' ); ?>
						</a>
					</p>

					<div class="pressed-hog-wizard__status" id="phw-connect-status" role="status"></div>

					<div class="pressed-hog-wizard__nav">
						<span></span>
						<button type="button" class="button button-primary button-hero" id="phw-validate">
							<?php esc_html_e( 'Validate & continue', 'pressed-hog' ); ?>
						</button>
					</div>
				</section>

				<!-- Step 2: Tracking -->
				<section class="pressed-hog-wizard__step" data-step="2">
					<h2><?php esc_html_e( 'What should be tracked?', 'pressed-hog' ); ?></h2>

					<label class="pressed-hog-toggle"><input type="checkbox" id="phw-pageviews" <?php checked( $options['capture_pageviews'] ); ?> /> <strong><?php esc_html_e( 'Pageviews', 'pressed-hog' ); ?></strong> — <?php esc_html_e( 'send a $pageview event on every page load.', 'pressed-hog' ); ?></label>
					<label class="pressed-hog-toggle"><input type="checkbox" id="phw-autocapture" <?php checked( $options['autocapture'] ); ?> /> <strong><?php esc_html_e( 'Autocapture', 'pressed-hog' ); ?></strong> — <?php esc_html_e( 'clicks, form submissions, and other interactions.', 'pressed-hog' ); ?></label>
					<label class="pressed-hog-toggle"><input type="checkbox" id="phw-recording" <?php checked( $options['session_recording'] ); ?> /> <strong><?php esc_html_e( 'Session replay', 'pressed-hog' ); ?></strong> — <?php esc_html_e( 'record visitor sessions (also needs to be enabled in PostHog).', 'pressed-hog' ); ?></label>
					<label class="pressed-hog-toggle"><input type="checkbox" id="phw-surveys" <?php checked( $options['enable_surveys'] ); ?> /> <strong><?php esc_html_e( 'Surveys', 'pressed-hog' ); ?></strong> — <?php esc_html_e( 'allow PostHog popover surveys on your site.', 'pressed-hog' ); ?></label>
					<label class="pressed-hog-toggle"><input type="checkbox" id="phw-identify" <?php checked( $options['identify_users'] ); ?> /> <strong><?php esc_html_e( 'Identify logged-in users', 'pressed-hog' ); ?></strong> — <?php esc_html_e( 'link events to WordPress accounts (sends user ID, email, and name — personal data).', 'pressed-hog' ); ?></label>

					<p class="description"><?php esc_html_e( 'Administrators and editors are excluded from tracking by default. You can adjust roles later on the settings page.', 'pressed-hog' ); ?></p>

					<div class="pressed-hog-wizard__nav">
						<button type="button" class="button" data-back><?php esc_html_e( 'Back', 'pressed-hog' ); ?></button>
						<button type="button" class="button button-primary button-hero" data-next><?php esc_html_e( 'Continue', 'pressed-hog' ); ?></button>
					</div>
				</section>

				<!-- Step 3: Consent -->
				<section class="pressed-hog-wizard__step" data-step="3">
					<h2><?php esc_html_e( 'Cookie consent', 'pressed-hog' ); ?></h2>
					<p><?php esc_html_e( 'How should tracking behave for new visitors?', 'pressed-hog' ); ?></p>

					<label class="pressed-hog-choice"><input type="radio" name="phw-consent" value="none" <?php checked( $options['consent_mode'], 'none' ); ?> /> <strong><?php esc_html_e( 'Track immediately', 'pressed-hog' ); ?></strong><span><?php esc_html_e( 'No consent gate. Fine if your audience or configuration does not require one.', 'pressed-hog' ); ?></span></label>
					<label class="pressed-hog-choice"><input type="radio" name="phw-consent" value="banner" <?php checked( $options['consent_mode'], 'banner' ); ?> /> <strong><?php esc_html_e( 'Built-in cookie banner', 'pressed-hog' ); ?></strong><span><?php esc_html_e( 'Show a small accept/decline banner. Nothing is tracked until the visitor accepts.', 'pressed-hog' ); ?></span></label>
					<label class="pressed-hog-choice"><input type="radio" name="phw-consent" value="external" <?php checked( $options['consent_mode'], 'external' ); ?> /> <strong><?php esc_html_e( 'I already use a consent plugin', 'pressed-hog' ); ?></strong><span><?php esc_html_e( 'Hold tracking until your consent plugin sets a cookie or calls the JavaScript API.', 'pressed-hog' ); ?></span></label>

					<div id="phw-banner-fields" hidden>
						<label class="pressed-hog-field">
							<span><?php esc_html_e( 'Banner text', 'pressed-hog' ); ?></span>
							<textarea id="phw-banner-text" rows="2" class="large-text"><?php echo esc_textarea( $options['banner_text'] ); ?></textarea>
						</label>
						<label class="pressed-hog-field pressed-hog-field--inline">
							<span><?php esc_html_e( 'Buttons', 'pressed-hog' ); ?></span>
							<input type="text" id="phw-banner-accept" value="<?php echo esc_attr( $options['banner_accept'] ); ?>" />
							<input type="text" id="phw-banner-decline" value="<?php echo esc_attr( $options['banner_decline'] ); ?>" />
						</label>
					</div>

					<div id="phw-external-fields" hidden>
						<label class="pressed-hog-field pressed-hog-field--inline">
							<span><?php esc_html_e( 'Consent cookie', 'pressed-hog' ); ?></span>
							<input type="text" id="phw-cookie-name" class="code" value="<?php echo esc_attr( $options['consent_cookie_name'] ); ?>" />
							=
							<input type="text" id="phw-cookie-value" class="code" value="<?php echo esc_attr( $options['consent_cookie_value'] ); ?>" />
						</label>
						<p class="description"><?php esc_html_e( 'Tracking starts when this cookie equals this value, or when your plugin calls window.pressedHog.grantConsent().', 'pressed-hog' ); ?></p>
					</div>

					<div class="pressed-hog-wizard__status" id="phw-save-status" role="status"></div>

					<div class="pressed-hog-wizard__nav">
						<button type="button" class="button" data-back><?php esc_html_e( 'Back', 'pressed-hog' ); ?></button>
						<button type="button" class="button button-primary button-hero" id="phw-finish"><?php esc_html_e( 'Save & finish', 'pressed-hog' ); ?></button>
					</div>
				</section>

				<!-- Step 4: Done -->
				<section class="pressed-hog-wizard__step" data-step="4">
					<h2><?php esc_html_e( 'You’re all set 🦔', 'pressed-hog' ); ?></h2>
					<p><?php esc_html_e( 'Settings saved. PostHog is now live on your site for visitors who match your tracking and consent rules.', 'pressed-hog' ); ?></p>

					<p>
						<button type="button" class="button button-primary" id="phw-test-event"><?php esc_html_e( 'Send a test event', 'pressed-hog' ); ?></button>
					</p>
					<div class="pressed-hog-wizard__status" id="phw-test-status" role="status"></div>

					<div class="pressed-hog-wizard__done-links">
						<a class="button" id="phw-open-posthog" href="https://us.posthog.com" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open PostHog ↗', 'pressed-hog' ); ?></a>
						<a class="button" href="<?php echo esc_url( admin_url( 'options-general.php?page=pressed-hog' ) ); ?>"><?php esc_html_e( 'All settings', 'pressed-hog' ); ?></a>
						<a class="button" href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Visit your site', 'pressed-hog' ); ?></a>
					</div>
				</section>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * AJAX handlers
	 * ------------------------------------------------------------------- */

	private static function check_ajax_request() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'code' => 'forbidden' ), 403 );
		}
	}

	/**
	 * Validate an API key against a host by calling /decide server-side.
	 */
	public static function ajax_validate_key() {
		self::check_ajax_request();

		$host = untrailingslashit( esc_url_raw( trim( wp_unslash( $_POST['host'] ?? '' ) ) ) );
		$key  = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( '' === $host || '' === $key ) {
			wp_send_json_error( array( 'code' => 'missing' ) );
		}

		$response = wp_remote_post(
			$host . '/decide/?v=3',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'api_key'     => $key,
						'distinct_id' => 'pressed-hog-setup-wizard',
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( array( 'code' => 'unreachable' ) );
		}

		$status = wp_remote_retrieve_response_code( $response );
		if ( 200 === $status ) {
			wp_send_json_success();
		}
		if ( in_array( $status, array( 401, 403 ), true ) ) {
			wp_send_json_error( array( 'code' => 'invalid_key' ) );
		}
		wp_send_json_error(
			array(
				'code'   => 'unexpected',
				'status' => $status,
			)
		);
	}

	/**
	 * Save the wizard's collected settings on top of the existing options,
	 * through the same sanitizer the settings page uses.
	 */
	public static function ajax_save_wizard() {
		self::check_ajax_request();

		$raw = json_decode( wp_unslash( $_POST['settings'] ?? '' ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized field-by-field below.
		if ( ! is_array( $raw ) ) {
			wp_send_json_error( array( 'code' => 'bad_payload' ) );
		}

		$allowed = array(
			'api_key',
			'api_host',
			'capture_pageviews',
			'autocapture',
			'session_recording',
			'enable_surveys',
			'identify_users',
			'consent_mode',
			'consent_cookie_name',
			'consent_cookie_value',
			'banner_text',
			'banner_accept',
			'banner_decline',
		);

		$input = pressed_hog_get_options();
		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $raw ) ) {
				$input[ $field ] = $raw[ $field ];
			}
		}

		update_option( PRESSED_HOG_OPTION, Pressed_Hog_Settings::sanitize( $input ) );
		wp_send_json_success();
	}

	/**
	 * Send a server-side test event using the saved settings.
	 *
	 * PostHog's ingestion endpoint returns 200 even for an invalid key
	 * (bad events are dropped asynchronously), so the key is authenticated
	 * against /decide first — otherwise this would always report success.
	 */
	public static function ajax_test_event() {
		self::check_ajax_request();

		$options = pressed_hog_get_options();
		if ( empty( $options['api_key'] ) ) {
			wp_send_json_error( array( 'code' => 'missing_key' ) );
		}

		$auth = wp_remote_post(
			$options['api_host'] . '/decide/?v=3',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'api_key'     => $options['api_key'],
						'distinct_id' => 'pressed-hog-setup-wizard',
					)
				),
			)
		);
		if ( is_wp_error( $auth ) || 200 !== wp_remote_retrieve_response_code( $auth ) ) {
			wp_send_json_error( array( 'code' => 'rejected' ) );
		}

		$response = wp_remote_post(
			$options['api_host'] . '/capture/',
			array(
				'timeout' => 8,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode(
					array(
						'api_key'     => $options['api_key'],
						'event'       => 'pressed_hog_test_event',
						'distinct_id' => (string) get_current_user_id(),
						'properties'  => array(
							'$lib'    => 'pressed-hog-wordpress',
							'source'  => 'setup_wizard',
							'site'    => home_url( '/' ),
							'version' => PRESSED_HOG_VERSION,
						),
					)
				),
			)
		);

		if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wp_send_json_error( array( 'code' => 'rejected' ) );
		}
		wp_send_json_success();
	}
}
