/**
 * WooCommerce event capture for Pressed Hog.
 *
 * Add-to-cart is captured from both cart implementations: the legacy jQuery
 * `added_to_cart` event (classic themes) and the `wc-blocks_added_to_cart`
 * DOM CustomEvent (block themes). The blocks event carries no product data,
 * so the last-clicked add-to-cart button is remembered for its product ID.
 * A short dedup window guards against both firing for one add.
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

	var lastClicked = null;
	var lastAddAt = 0;

	function captureAddToCart(props) {
		var now = Date.now();
		if (now - lastAddAt < 500) {
			return; // both the jQuery and blocks events fired for one add
		}
		lastAddAt = now;
		capture('product_added_to_cart', props);
	}

	// Remember the product of the most recently clicked add-to-cart button.
	document.body.addEventListener('click', function (event) {
		var target = event.target;
		var button = target && target.closest ? target.closest('.add_to_cart_button') : null;
		if (button) {
			lastClicked = {
				product_id: parseInt(button.getAttribute('data-product_id'), 10) || null,
				quantity: parseInt(button.getAttribute('data-quantity'), 10) || 1
			};
		}
	}, true);

	// Block themes: WooCommerce Blocks dispatches this DOM CustomEvent.
	document.body.addEventListener('wc-blocks_added_to_cart', function () {
		captureAddToCart(lastClicked || {});
	});

	// Classic themes: fired by WooCommerce after an AJAX add-to-cart.
	if ($) {
		$(document.body).on('added_to_cart', function (event, fragments, cartHash, button) {
			var $button = button ? $(button) : $();
			captureAddToCart({
				product_id: $button.data('product_id') || (lastClicked && lastClicked.product_id) || null,
				quantity: parseInt($button.data('quantity'), 10) || 1
			});
		});

		// Non-AJAX add-to-cart (single product pages) submits a form; capture on click.
		$(document.body).on('click', '.single_add_to_cart_button:not(.ajax_add_to_cart)', function () {
			var $form = $(this).closest('form.cart');
			captureAddToCart({
				product_id: parseInt($form.find('[name=add-to-cart]').val() || $(this).val(), 10) || null,
				quantity: parseInt($form.find('[name=quantity]').val(), 10) || 1
			});
		});
	}

	if (data.checkoutStarted) {
		capture('checkout_started');
	}

	if (data.order) {
		capture('order_completed', data.order);
	}
})(window.jQuery);
