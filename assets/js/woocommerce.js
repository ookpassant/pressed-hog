/**
 * WooCommerce event capture for Pressed Hog.
 *
 * When a consent mode is active and consent has not been granted, posthog-js
 * is opted out and silently drops these events — no extra gating needed here.
 */
(function ($) {
	'use strict';

	var data = window.pressedHogWoo;
	if (!data || typeof window.posthog === 'undefined') {
		return;
	}

	function capture(event, properties) {
		window.posthog.capture(event, properties || {});
	}

	// Fired by WooCommerce after an AJAX add-to-cart.
	$(document.body).on('added_to_cart', function (event, fragments, cartHash, button) {
		var $button = button ? $(button) : $();
		capture('product_added_to_cart', {
			product_id: $button.data('product_id') || null,
			quantity: parseInt($button.data('quantity'), 10) || 1
		});
	});

	// Non-AJAX add-to-cart (single product pages) submits a form; capture on click.
	$(document.body).on('click', '.single_add_to_cart_button:not(.ajax_add_to_cart)', function () {
		var $form = $(this).closest('form.cart');
		capture('product_added_to_cart', {
			product_id: parseInt($form.find('[name=add-to-cart]').val() || $(this).val(), 10) || null,
			quantity: parseInt($form.find('[name=quantity]').val(), 10) || 1
		});
	});

	if (data.checkoutStarted) {
		capture('checkout_started');
	}

	if (data.order) {
		capture('order_completed', data.order);
	}
})(jQuery);
