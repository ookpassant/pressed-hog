<?php
/**
 * WooCommerce event capture: product_added_to_cart, checkout_started, order_completed.
 *
 * @package Pressed_Hog
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Pressed_Hog_WooCommerce {

	const TRACKED_META = '_pressed_hog_tracked';

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 20 );
	}

	public static function is_enabled() {
		$options = pressed_hog_get_options();
		return ! empty( $options['woocommerce_events'] )
			&& class_exists( 'WooCommerce' )
			&& Pressed_Hog_Tracker::is_active();
	}

	public static function enqueue() {
		if ( ! self::is_enabled() ) {
			return;
		}

		wp_enqueue_script(
			'pressed-hog-woocommerce',
			PRESSED_HOG_URL . 'assets/js/woocommerce.js',
			array( 'jquery' ),
			PRESSED_HOG_VERSION,
			array( 'in_footer' => true )
		);

		$data = array(
			'checkoutStarted' => function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page(),
			'order'           => self::get_order_payload(),
		);

		wp_localize_script( 'pressed-hog-woocommerce', 'pressedHogWoo', $data );
	}

	/**
	 * Build the order_completed payload on the order-received page, at most
	 * once per order (an order meta flag prevents double counting on reload).
	 *
	 * @return array|null
	 */
	private static function get_order_payload() {
		if ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return null;
		}

		$order_id = absint( get_query_var( 'order-received' ) );
		if ( ! $order_id ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || $order->get_meta( self::TRACKED_META ) ) {
			return null;
		}

		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'product_id' => $item->get_product_id(),
				'name'       => $item->get_name(),
				'quantity'   => $item->get_quantity(),
				'total'      => (float) $item->get_total(),
			);
		}

		$order->update_meta_data( self::TRACKED_META, time() );
		$order->save();

		return array(
			'order_id'   => $order_id,
			'total'      => (float) $order->get_total(),
			'currency'   => $order->get_currency(),
			'item_count' => $order->get_item_count(),
			'items'      => $items,
		);
	}
}
