/**
 * Consent handling for Pressed Hog.
 *
 * PostHog is initialized with opt_out_capturing_by_default when a consent
 * mode is active, so nothing is captured until consent is granted here.
 *
 * - banner mode: renders a small banner; the decision is stored in a cookie.
 * - external mode: watches the configured cookie and exposes
 *   window.pressedHog.grantConsent() / denyConsent() for consent plugins.
 */
(function () {
	'use strict';

	var settings = window.pressedHogConsent;
	if (!settings || typeof window.posthog === 'undefined') {
		return;
	}

	var COOKIE_DAYS = 180;

	function readCookie(name) {
		var match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
		return match ? decodeURIComponent(match[1]) : null;
	}

	function writeCookie(name, value) {
		var expires = new Date(Date.now() + COOKIE_DAYS * 864e5).toUTCString();
		document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
	}

	function grantConsent() {
		writeCookie(settings.cookieName, settings.cookieValue);
		window.posthog.opt_in_capturing();
		if (typeof window.pressedHogIdentify === 'function') {
			window.pressedHogIdentify();
		}
		removeBanner();
	}

	function denyConsent() {
		writeCookie(settings.cookieName, 'denied');
		window.posthog.opt_out_capturing();
		removeBanner();
	}

	window.pressedHog = window.pressedHog || {};
	window.pressedHog.grantConsent = grantConsent;
	window.pressedHog.denyConsent = denyConsent;

	var banner = null;

	function removeBanner() {
		if (banner && banner.parentNode) {
			banner.parentNode.removeChild(banner);
			banner = null;
		}
	}

	function renderBanner() {
		banner = document.createElement('div');
		banner.className = 'pressed-hog-banner';
		banner.setAttribute('role', 'dialog');
		banner.setAttribute('aria-live', 'polite');

		var text = document.createElement('p');
		text.className = 'pressed-hog-banner__text';
		text.textContent = settings.text;

		var actions = document.createElement('div');
		actions.className = 'pressed-hog-banner__actions';

		var accept = document.createElement('button');
		accept.type = 'button';
		accept.className = 'pressed-hog-banner__button pressed-hog-banner__button--accept';
		accept.textContent = settings.accept;
		accept.addEventListener('click', grantConsent);

		var decline = document.createElement('button');
		decline.type = 'button';
		decline.className = 'pressed-hog-banner__button pressed-hog-banner__button--decline';
		decline.textContent = settings.decline;
		decline.addEventListener('click', denyConsent);

		actions.appendChild(decline);
		actions.appendChild(accept);
		banner.appendChild(text);
		banner.appendChild(actions);
		document.body.appendChild(banner);
	}

	var stored = readCookie(settings.cookieName);

	if (stored === settings.cookieValue) {
		// Consent was previously granted (or the external plugin has set it).
		window.posthog.opt_in_capturing();
		if (typeof window.pressedHogIdentify === 'function') {
			window.pressedHogIdentify();
		}
		return;
	}

	if (settings.mode === 'banner' && stored === null) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', renderBanner);
		} else {
			renderBanner();
		}
	}
})();
