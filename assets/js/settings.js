/**
 * Settings page: toggle the PostHog host input between a Cloud preset
 * (read-only) and a custom self-hosted URL.
 */
(function () {
	'use strict';

	var preset = document.getElementById('pressed-hog-host-preset');
	var input = document.getElementById('pressed-hog-host-input');
	if (!preset || !input) {
		return;
	}

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
