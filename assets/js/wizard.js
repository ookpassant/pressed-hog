/**
 * Pressed Hog setup wizard.
 *
 * Drives the 4-step flow: Connect (with live key validation via AJAX),
 * Tracking, Consent, Done (save + optional test event).
 */
(function () {
	'use strict';

	var data = window.pressedHogWizard;
	if (!data) {
		return;
	}

	var i18n = data.i18n;
	var root = document.getElementById('pressed-hog-wizard');
	if (!root) {
		return;
	}

	function $(selector) {
		return root.querySelector(selector);
	}

	function $all(selector) {
		return Array.prototype.slice.call(root.querySelectorAll(selector));
	}

	/* ------------------------------------------------------------------
	 * Step navigation
	 * ---------------------------------------------------------------- */

	var current = 1;

	function goTo(step) {
		current = step;
		$all('.pressed-hog-wizard__step').forEach(function (section) {
			section.classList.toggle('is-active', Number(section.dataset.step) === step);
		});
		$all('.pressed-hog-wizard__steps li').forEach(function (item, index) {
			item.classList.toggle('is-current', index + 1 === step);
			item.classList.toggle('is-done', index + 1 < step);
		});
		root.scrollIntoView({ behavior: 'smooth', block: 'start' });
	}

	$all('[data-back]').forEach(function (button) {
		button.addEventListener('click', function () {
			goTo(current - 1);
		});
	});

	$all('[data-next]').forEach(function (button) {
		button.addEventListener('click', function () {
			goTo(current + 1);
		});
	});

	/* ------------------------------------------------------------------
	 * AJAX helper
	 * ---------------------------------------------------------------- */

	function post(action, fields) {
		var body = new FormData();
		body.append('action', action);
		body.append('nonce', data.nonce);
		Object.keys(fields || {}).forEach(function (key) {
			body.append(key, fields[key]);
		});
		return fetch(data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response.json();
			});
	}

	function setStatus(el, type, message, extra) {
		el.className = 'pressed-hog-wizard__status is-' + type;
		el.textContent = message;
		if (extra) {
			el.appendChild(extra);
		}
	}

	function clearStatus(el) {
		el.className = 'pressed-hog-wizard__status';
		el.textContent = '';
	}

	/* ------------------------------------------------------------------
	 * Step 1: Connect
	 * ---------------------------------------------------------------- */

	var regionSelect = $('#phw-region');
	var hostField = $('#phw-host-field');
	var hostInput = $('#phw-host');
	var keyInput = $('#phw-key');
	var keyLink = $('#phw-key-link');
	var connectStatus = $('#phw-connect-status');
	var validateButton = $('#phw-validate');

	// Prefill from saved options when re-running the wizard.
	var savedHost = data.options.api_host || 'https://us.i.posthog.com';
	if (savedHost === 'https://us.i.posthog.com' || savedHost === 'https://eu.i.posthog.com') {
		regionSelect.value = savedHost;
	} else {
		regionSelect.value = 'custom';
		hostInput.value = savedHost;
	}

	function currentHost() {
		return regionSelect.value === 'custom'
			? hostInput.value.trim().replace(/\/+$/, '')
			: regionSelect.value;
	}

	function appHost() {
		var host = currentHost();
		return host.replace('.i.posthog.com', '.posthog.com');
	}

	function syncRegionUI() {
		var custom = regionSelect.value === 'custom';
		hostField.hidden = !custom;
		keyLink.href = (custom ? (currentHost() || 'https://us.posthog.com') : appHost()) + '/settings/project';
	}

	regionSelect.addEventListener('change', syncRegionUI);
	hostInput.addEventListener('input', syncRegionUI);
	syncRegionUI();

	validateButton.addEventListener('click', function () {
		var key = keyInput.value.trim();
		var host = currentHost();

		if (!key || !host) {
			setStatus(connectStatus, 'error', i18n.keyMissing);
			return;
		}

		validateButton.disabled = true;
		setStatus(connectStatus, 'busy', i18n.validating);

		post('pressed_hog_validate_key', { host: host, api_key: key })
			.then(function (result) {
				validateButton.disabled = false;
				if (result.success) {
					setStatus(connectStatus, 'ok', i18n.valid);
					window.setTimeout(function () {
						goTo(2);
					}, 600);
					return;
				}
				var code = result.data && result.data.code;
				if (code === 'unreachable') {
					var anyway = document.createElement('button');
					anyway.type = 'button';
					anyway.className = 'button-link pressed-hog-wizard__anyway';
					anyway.textContent = i18n.continueAnyway;
					anyway.addEventListener('click', function () {
						goTo(2);
					});
					setStatus(connectStatus, 'warn', i18n.unreachable, anyway);
				} else {
					setStatus(connectStatus, 'error', i18n.invalid);
				}
			})
			.catch(function () {
				validateButton.disabled = false;
				setStatus(connectStatus, 'error', i18n.invalid);
			});
	});

	/* ------------------------------------------------------------------
	 * Step 3: Consent field visibility
	 * ---------------------------------------------------------------- */

	function syncConsentUI() {
		var checked = root.querySelector('input[name="phw-consent"]:checked');
		var mode = checked ? checked.value : 'none';
		$('#phw-banner-fields').hidden = mode !== 'banner';
		$('#phw-external-fields').hidden = mode !== 'external';
	}

	$all('input[name="phw-consent"]').forEach(function (radio) {
		radio.addEventListener('change', syncConsentUI);
	});
	if (!root.querySelector('input[name="phw-consent"]:checked')) {
		root.querySelector('input[name="phw-consent"][value="none"]').checked = true;
	}
	syncConsentUI();

	/* ------------------------------------------------------------------
	 * Save & finish
	 * ---------------------------------------------------------------- */

	var finishButton = $('#phw-finish');
	var saveStatus = $('#phw-save-status');

	function collectSettings() {
		var consent = root.querySelector('input[name="phw-consent"]:checked');
		return {
			api_key: keyInput.value.trim(),
			api_host: currentHost(),
			capture_pageviews: $('#phw-pageviews').checked ? 1 : 0,
			autocapture: $('#phw-autocapture').checked ? 1 : 0,
			session_recording: $('#phw-recording').checked ? 1 : 0,
			enable_surveys: $('#phw-surveys').checked ? 1 : 0,
			identify_users: $('#phw-identify').checked ? 1 : 0,
			consent_mode: consent ? consent.value : 'none',
			consent_cookie_name: $('#phw-cookie-name').value.trim(),
			consent_cookie_value: $('#phw-cookie-value').value.trim(),
			banner_text: $('#phw-banner-text').value,
			banner_accept: $('#phw-banner-accept').value.trim(),
			banner_decline: $('#phw-banner-decline').value.trim()
		};
	}

	finishButton.addEventListener('click', function () {
		finishButton.disabled = true;
		setStatus(saveStatus, 'busy', i18n.saving);

		post('pressed_hog_save_wizard', { settings: JSON.stringify(collectSettings()) })
			.then(function (result) {
				finishButton.disabled = false;
				if (result.success) {
					clearStatus(saveStatus);
					$('#phw-open-posthog').href = appHost();
					goTo(4);
				} else {
					setStatus(saveStatus, 'error', i18n.saveFailed);
				}
			})
			.catch(function () {
				finishButton.disabled = false;
				setStatus(saveStatus, 'error', i18n.saveFailed);
			});
	});

	/* ------------------------------------------------------------------
	 * Step 4: Test event
	 * ---------------------------------------------------------------- */

	var testButton = $('#phw-test-event');
	var testStatus = $('#phw-test-status');

	testButton.addEventListener('click', function () {
		testButton.disabled = true;
		setStatus(testStatus, 'busy', i18n.sendingTest);

		post('pressed_hog_test_event', {})
			.then(function (result) {
				testButton.disabled = false;
				if (result.success) {
					setStatus(testStatus, 'ok', i18n.testSent);
				} else {
					setStatus(testStatus, 'error', i18n.testFailed);
				}
			})
			.catch(function () {
				testButton.disabled = false;
				setStatus(testStatus, 'error', i18n.testFailed);
			});
	});
})();
