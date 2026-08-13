<?php
/**
 * Trackable link + QR code generator.
 *
 * Lets you turn any URL into a campaign-tagged "trackable" link (UTM
 * parameters plus a unique per-link id so PostHog can attribute each QR
 * individually), generate a QR code for it in the browser, keep a list of the
 * links you've made, and export the whole list as a CSV sheet.
 *
 * QR codes are rendered entirely client-side (see assets/js/links.js) — the
 * destination URL is never sent to a third-party QR service, matching the
 * privacy posture of the rest of the plugin.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_Links {

	/** Option that stores the array of generated links. */
	const OPTION = 'pressed_hog_links';

	/** admin-post.php action names. */
	const ACTION_SAVE   = 'pressed_hog_save_link';
	const ACTION_DELETE = 'pressed_hog_delete_link';
	const ACTION_EXPORT = 'pressed_hog_export_links';

	/** The query parameter that carries each link's unique tracking id. */
	const TRACK_PARAM = 'phg_qr';

	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_' . self::ACTION_SAVE, array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE, array( __CLASS__, 'handle_delete' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT, array( __CLASS__, 'handle_export' ) );
	}

	public static function add_menu() {
		add_submenu_page(
			'pressed-hog-analytics',
			__( 'QR Codes & Trackable Links', 'pressed-hog' ),
			__( 'QR Codes', 'pressed-hog' ),
			'manage_options',
			'pressed-hog-links',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue( $hook ) {
		if ( 'posthog_page_pressed-hog-links' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'pressed-hog-links', PRESSED_HOG_URL . 'assets/css/links.css', array(), PRESSED_HOG_VERSION );
		wp_enqueue_script( 'pressed-hog-links', PRESSED_HOG_URL . 'assets/js/links.js', array(), PRESSED_HOG_VERSION, true );
		wp_localize_script(
			'pressed-hog-links',
			'pressedHogLinks',
			array(
				'trackParam' => self::TRACK_PARAM,
				'i18n'       => array(
					'copied'       => __( 'Copied!', 'pressed-hog' ),
					'copy'         => __( 'Copy', 'pressed-hog' ),
					'invalidUrl'   => __( 'Enter a valid http(s) URL to preview a QR code.', 'pressed-hog' ),
					'downloadName' => __( 'qr-code', 'pressed-hog' ),
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Storage
	 * ------------------------------------------------------------------- */

	/**
	 * @return array[] List of link records, newest first.
	 */
	public static function get_links() {
		$links = get_option( self::OPTION, array() );
		return is_array( $links ) ? $links : array();
	}

	private static function save_links( $links ) {
		update_option( self::OPTION, array_values( $links ), false );
	}

	/**
	 * Build a trackable URL from a destination and campaign parameters.
	 *
	 * Merges UTM parameters and the unique tracking id into the destination's
	 * query string, preserving any existing query and fragment.
	 *
	 * @param string $destination Destination URL (already validated).
	 * @param array  $params      Keys: source, medium, campaign, id.
	 * @return string
	 */
	public static function build_tracked_url( $destination, $params ) {
		$fragment = '';
		$hash_pos = strpos( $destination, '#' );
		if ( false !== $hash_pos ) {
			$fragment    = substr( $destination, $hash_pos );
			$destination = substr( $destination, 0, $hash_pos );
		}

		// Build and append the query manually (rather than add_query_arg, which
		// decodes then re-emits the destination's existing query and can corrupt
		// already-encoded values). This mirrors the client-side preview builder.
		$pairs = array();
		if ( ! empty( $params['source'] ) ) {
			$pairs[] = 'utm_source=' . rawurlencode( $params['source'] );
		}
		if ( ! empty( $params['medium'] ) ) {
			$pairs[] = 'utm_medium=' . rawurlencode( $params['medium'] );
		}
		if ( ! empty( $params['campaign'] ) ) {
			$pairs[] = 'utm_campaign=' . rawurlencode( $params['campaign'] );
		}
		if ( ! empty( $params['id'] ) ) {
			$pairs[] = self::TRACK_PARAM . '=' . rawurlencode( $params['id'] );
		}

		if ( empty( $pairs ) ) {
			return $destination . $fragment;
		}

		$separator = ( false === strpos( $destination, '?' ) ) ? '?' : '&';
		return $destination . $separator . implode( '&', $pairs ) . $fragment;
	}

	/* ---------------------------------------------------------------------
	 * Form handlers
	 * ------------------------------------------------------------------- */

	private static function redirect_back( $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=pressed-hog-links' ) ) );
		exit;
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pressed-hog' ) );
		}
		check_admin_referer( self::ACTION_SAVE );

		$raw_destination = isset( $_POST['destination'] ) ? wp_unslash( $_POST['destination'] ) : '';
		$destination     = esc_url_raw( trim( $raw_destination ) );
		$scheme          = wp_parse_url( $destination, PHP_URL_SCHEME );

		if ( ! $destination || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			self::redirect_back( array( 'phg_notice' => 'invalid' ) );
		}

		$params = array(
			'source'   => sanitize_text_field( wp_unslash( $_POST['source'] ?? '' ) ),
			'medium'   => sanitize_text_field( wp_unslash( $_POST['medium'] ?? '' ) ),
			'campaign' => sanitize_text_field( wp_unslash( $_POST['campaign'] ?? '' ) ),
			'id'       => self::generate_id(),
		);
		$label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
		if ( '' === $label ) {
			$label = $params['campaign'] ? $params['campaign'] : wp_parse_url( $destination, PHP_URL_HOST );
		}

		$links   = self::get_links();
		$links[] = array(
			'id'          => $params['id'],
			'label'       => $label,
			'destination' => $destination,
			'source'      => $params['source'],
			'medium'      => $params['medium'],
			'campaign'    => $params['campaign'],
			'tracked_url' => self::build_tracked_url( $destination, $params ),
			'created'     => time(),
		);

		// Newest first.
		usort(
			$links,
			function ( $a, $b ) {
				return ( $b['created'] ?? 0 ) <=> ( $a['created'] ?? 0 );
			}
		);

		self::save_links( $links );
		self::redirect_back( array( 'phg_notice' => 'created' ) );
	}

	public static function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pressed-hog' ) );
		}
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		check_admin_referer( self::ACTION_DELETE . '_' . $id );

		$links = array_filter(
			self::get_links(),
			function ( $link ) use ( $id ) {
				return ( $link['id'] ?? '' ) !== $id;
			}
		);
		self::save_links( $links );
		self::redirect_back( array( 'phg_notice' => 'deleted' ) );
	}

	public static function handle_export() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'pressed-hog' ) );
		}
		check_admin_referer( self::ACTION_EXPORT );

		$links    = self::get_links();
		$filename = 'pressed-hog-links-' . gmdate( 'Y-m-d' ) . '.csv';

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM so Excel opens accented characters correctly.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv(
			$out,
			array(
				__( 'Label', 'pressed-hog' ),
				__( 'Destination', 'pressed-hog' ),
				__( 'Tracked URL', 'pressed-hog' ),
				__( 'Source', 'pressed-hog' ),
				__( 'Medium', 'pressed-hog' ),
				__( 'Campaign', 'pressed-hog' ),
				__( 'Tracking ID', 'pressed-hog' ),
				__( 'Created', 'pressed-hog' ),
			)
		);
		foreach ( $links as $link ) {
			fputcsv(
				$out,
				array(
					$link['label'] ?? '',
					$link['destination'] ?? '',
					$link['tracked_url'] ?? '',
					$link['source'] ?? '',
					$link['medium'] ?? '',
					$link['campaign'] ?? '',
					$link['id'] ?? '',
					isset( $link['created'] ) ? gmdate( 'Y-m-d H:i', (int) $link['created'] ) : '',
				)
			);
		}
		fclose( $out );
		exit;
	}

	private static function generate_id() {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
	}

	/* ---------------------------------------------------------------------
	 * Admin page
	 * ------------------------------------------------------------------- */

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$links   = self::get_links();
		$options = pressed_hog_get_options();

		echo '<div class="wrap pressed-hog-links">';
		printf( '<h1>%s</h1>', esc_html__( 'QR Codes & Trackable Links', 'pressed-hog' ) );
		printf(
			'<p class="ph-links-intro">%s</p>',
			esc_html__( 'Turn any URL into a campaign-tagged link with its own QR code. Each link carries UTM parameters and a unique tracking id, so scans show up as attributable traffic in PostHog. Your links are saved below and can be exported as a spreadsheet.', 'pressed-hog' )
		);

		if ( empty( $options['api_key'] ) ) {
			printf(
				'<div class="notice notice-info inline"><p>%s <a href="%s">%s</a></p></div>',
				esc_html__( 'PostHog isn’t connected yet, so scans won’t be recorded. QR codes still work — connect your project to start attributing them.', 'pressed-hog' ),
				esc_url( Pressed_Hog_Wizard::url() ),
				esc_html__( 'Run the setup wizard', 'pressed-hog' )
			);
		}

		self::render_notice();
		self::render_create_form();
		self::render_links_table( $links );

		echo '</div>';
	}

	private static function render_notice() {
		$notice = isset( $_GET['phg_notice'] ) ? sanitize_key( wp_unslash( $_GET['phg_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, post-redirect flash.
		if ( ! $notice ) {
			return;
		}
		$messages = array(
			'created' => array( 'success', __( 'Trackable link created.', 'pressed-hog' ) ),
			'deleted' => array( 'success', __( 'Link deleted.', 'pressed-hog' ) ),
			'invalid' => array( 'error', __( 'That doesn’t look like a valid http(s) URL. Nothing was saved.', 'pressed-hog' ) ),
		);
		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}
		printf(
			'<div class="notice notice-%s is-dismissible"><p>%s</p></div>',
			esc_attr( $messages[ $notice ][0] ),
			esc_html( $messages[ $notice ][1] )
		);
	}

	private static function render_create_form() {
		echo '<div class="ph-card ph-links-create">';
		printf( '<h2 class="ph-card__title">%s</h2>', esc_html__( 'Create a trackable link', 'pressed-hog' ) );
		echo '<div class="ph-links-create__grid">';

		// Left: the form.
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="ph-links-form" id="ph-links-form">';
		wp_nonce_field( self::ACTION_SAVE );
		printf( '<input type="hidden" name="action" value="%s" />', esc_attr( self::ACTION_SAVE ) );

		self::field(
			'destination',
			__( 'Destination URL', 'pressed-hog' ),
			'url',
			'https://example.com/landing-page',
			__( 'Where the QR code sends people — your own link.', 'pressed-hog' ),
			true
		);
		self::field(
			'label',
			__( 'Label (optional)', 'pressed-hog' ),
			'text',
			__( 'Spring flyer', 'pressed-hog' ),
			__( 'A name to recognise this link by in the list below.', 'pressed-hog' )
		);

		echo '<div class="ph-links-form__row">';
		self::field( 'source', __( 'Campaign source', 'pressed-hog' ), 'text', 'qr', '', false, 'qr' );
		self::field( 'medium', __( 'Campaign medium', 'pressed-hog' ), 'text', 'qr-code', '', false, 'qr-code' );
		echo '</div>';

		self::field(
			'campaign',
			__( 'Campaign name (optional)', 'pressed-hog' ),
			'text',
			__( 'spring-sale', 'pressed-hog' ),
			__( 'Groups scans under a campaign in PostHog (utm_campaign).', 'pressed-hog' )
		);

		printf(
			'<p class="submit"><button type="submit" class="button button-primary">%s</button></p>',
			esc_html__( 'Save link', 'pressed-hog' )
		);
		echo '</form>';

		// Right: live QR preview.
		echo '<div class="ph-links-preview">';
		printf( '<span class="ph-links-preview__label">%s</span>', esc_html__( 'Live preview', 'pressed-hog' ) );
		echo '<div class="ph-qr" id="ph-links-preview-qr" data-empty-text="' . esc_attr__( 'Enter a valid http(s) URL to preview a QR code.', 'pressed-hog' ) . '"></div>';
		echo '<code class="ph-links-preview__url" id="ph-links-preview-url"></code>';
		echo '</div>';

		echo '</div></div>';
	}

	private static function field( $name, $label, $type, $placeholder, $description = '', $required = false, $value = '' ) {
		printf( '<p class="ph-links-field"><label for="ph-field-%1$s">%2$s</label>', esc_attr( $name ), esc_html( $label ) );
		printf(
			'<input type="%1$s" id="ph-field-%2$s" name="%2$s" value="%3$s" placeholder="%4$s" class="regular-text" %5$s />',
			esc_attr( $type ),
			esc_attr( $name ),
			esc_attr( $value ),
			esc_attr( $placeholder ),
			$required ? 'required' : ''
		);
		if ( $description ) {
			printf( '<span class="description">%s</span>', esc_html( $description ) );
		}
		echo '</p>';
	}

	private static function render_links_table( $links ) {
		echo '<div class="ph-card">';
		echo '<div class="ph-links-table-head">';
		printf( '<h2 class="ph-card__title">%s</h2>', esc_html__( 'Your links', 'pressed-hog' ) );
		if ( ! empty( $links ) ) {
			$export_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::ACTION_EXPORT ),
				self::ACTION_EXPORT
			);
			printf(
				'<a href="%s" class="button">%s</a>',
				esc_url( $export_url ),
				esc_html__( 'Download sheet (CSV)', 'pressed-hog' )
			);
		}
		echo '</div>';

		if ( empty( $links ) ) {
			printf( '<p class="ph-empty">%s</p>', esc_html__( 'No links yet. Create your first trackable link above.', 'pressed-hog' ) );
			echo '</div>';
			return;
		}

		echo '<table class="widefat striped ph-links-table"><thead><tr>';
		printf( '<th>%s</th>', esc_html__( 'Label', 'pressed-hog' ) );
		printf( '<th>%s</th>', esc_html__( 'Tracked URL', 'pressed-hog' ) );
		printf( '<th>%s</th>', esc_html__( 'Created', 'pressed-hog' ) );
		printf( '<th class="ph-links-table__actions">%s</th>', esc_html__( 'Actions', 'pressed-hog' ) );
		echo '</tr></thead><tbody>';

		foreach ( $links as $link ) {
			$id          = $link['id'] ?? '';
			$tracked_url = $link['tracked_url'] ?? '';
			$delete_url  = wp_nonce_url(
				admin_url( 'admin-post.php?action=' . self::ACTION_DELETE . '&id=' . rawurlencode( $id ) ),
				self::ACTION_DELETE . '_' . $id
			);
			echo '<tr>';
			printf(
				'<td class="ph-links-table__label"><strong>%s</strong>%s</td>',
				esc_html( $link['label'] ?? '' ),
				$link['campaign'] ? '<span class="ph-links-table__campaign">' . esc_html( $link['campaign'] ) . '</span>' : ''
			);
			printf(
				'<td><a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a> <button type="button" class="button-link ph-copy" data-copy="%1$s">%3$s</button></td>',
				esc_url( $tracked_url ),
				esc_html( $tracked_url ),
				esc_html__( 'Copy', 'pressed-hog' )
			);
			printf(
				'<td>%s</td>',
				isset( $link['created'] ) ? esc_html( date_i18n( get_option( 'date_format' ), (int) $link['created'] ) ) : '—'
			);
			echo '<td class="ph-links-table__actions">';
			printf(
				'<button type="button" class="button ph-qr-show" data-url="%s" data-label="%s">%s</button> ',
				esc_attr( $tracked_url ),
				esc_attr( $link['label'] ?? 'qr-code' ),
				esc_html__( 'QR code', 'pressed-hog' )
			);
			printf(
				'<a href="%s" class="button-link ph-links-table__delete" onclick="return confirm(%s);">%s</a>',
				esc_url( $delete_url ),
				esc_attr( wp_json_encode( __( 'Delete this link?', 'pressed-hog' ) ) ),
				esc_html__( 'Delete', 'pressed-hog' )
			);
			echo '</td></tr>';
		}
		echo '</tbody></table></div>';

		// QR modal (populated client-side).
		?>
		<div class="ph-qr-modal" id="ph-qr-modal" hidden>
			<div class="ph-qr-modal__backdrop" data-close="1"></div>
			<div class="ph-qr-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="ph-qr-modal-title">
				<button type="button" class="ph-qr-modal__close" data-close="1" aria-label="<?php esc_attr_e( 'Close', 'pressed-hog' ); ?>">&times;</button>
				<h2 id="ph-qr-modal-title" class="ph-qr-modal__title"></h2>
				<div class="ph-qr" id="ph-qr-modal-canvas"></div>
				<code class="ph-qr-modal__url"></code>
				<p class="ph-qr-modal__actions">
					<button type="button" class="button button-primary" data-download="png"><?php esc_html_e( 'Download PNG', 'pressed-hog' ); ?></button>
					<button type="button" class="button" data-download="svg"><?php esc_html_e( 'Download SVG', 'pressed-hog' ); ?></button>
				</p>
			</div>
		</div>
		<?php
	}
}
