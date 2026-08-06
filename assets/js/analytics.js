/**
 * Traffic chart for the Pressed Hog analytics page.
 *
 * Two-series line chart (pageviews, unique visitors) rendered as inline SVG —
 * no libraries. Includes a crosshair + tooltip hover layer and redraws on
 * resize. Colors match the legend chips defined in analytics.css.
 */
(function () {
	'use strict';

	var container = document.getElementById('pressed-hog-chart');
	var dataEl = document.getElementById('pressed-hog-chart-data');
	if (!container || !dataEl) {
		return;
	}

	var series;
	try {
		series = JSON.parse(dataEl.textContent || '[]');
	} catch (e) {
		series = [];
	}

	if (!series.length) {
		container.className = 'ph-chart-empty';
		container.textContent = container.getAttribute('data-empty-text') || '';
		return;
	}

	var COLORS = { pageviews: '#2a78d6', visitors: '#eb6834' };
	var INK = { muted: '#898781', grid: '#e1e0d9', axis: '#c3c2b7' };
	var HEIGHT = 280;
	var MARGIN = { top: 16, right: 20, bottom: 30, left: 52 };

	var tooltip = document.createElement('div');
	tooltip.className = 'ph-tooltip';
	tooltip.hidden = true;
	container.appendChild(tooltip);

	function niceMax(value) {
		if (value <= 0) {
			return 1;
		}
		var magnitude = Math.pow(10, Math.floor(Math.log(value) / Math.LN10));
		var normalized = value / magnitude;
		var step = normalized <= 1 ? 1 : normalized <= 2 ? 2 : normalized <= 5 ? 5 : 10;
		return step * magnitude;
	}

	function formatNumber(value) {
		return Number(value).toLocaleString();
	}

	function shortDate(iso) {
		var d = new Date(iso + 'T00:00:00');
		return isNaN(d) ? iso : d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
	}

	var svg = null;
	var geometry = null;

	function draw() {
		var width = Math.max(container.clientWidth, 320);
		var plotW = width - MARGIN.left - MARGIN.right;
		var plotH = HEIGHT - MARGIN.top - MARGIN.bottom;
		var n = series.length;
		var yMax = niceMax(Math.max.apply(null, series.map(function (p) { return p.pageviews; })));

		function x(i) {
			return MARGIN.left + (n === 1 ? plotW / 2 : (plotW * i) / (n - 1));
		}
		function y(v) {
			return MARGIN.top + plotH - (plotH * v) / yMax;
		}

		var parts = [];

		// Horizontal gridlines + y tick labels (4 divisions).
		for (var t = 0; t <= 4; t++) {
			var value = (yMax / 4) * t;
			var gy = y(value);
			parts.push('<line x1="' + MARGIN.left + '" x2="' + (width - MARGIN.right) + '" y1="' + gy + '" y2="' + gy + '" stroke="' + (t === 0 ? INK.axis : INK.grid) + '" stroke-width="1"/>');
			parts.push('<text x="' + (MARGIN.left - 8) + '" y="' + (gy + 4) + '" text-anchor="end" fill="' + INK.muted + '" font-size="11" style="font-variant-numeric:tabular-nums">' + formatNumber(value) + '</text>');
		}

		// Sparse x labels (~6).
		var stride = Math.max(1, Math.ceil(n / 6));
		for (var i = 0; i < n; i += stride) {
			parts.push('<text x="' + x(i) + '" y="' + (HEIGHT - 8) + '" text-anchor="middle" fill="' + INK.muted + '" font-size="11">' + shortDate(series[i].day) + '</text>');
		}

		// Series lines (2px) + end markers.
		['pageviews', 'visitors'].forEach(function (key) {
			var path = series.map(function (p, idx) {
				return (idx === 0 ? 'M' : 'L') + x(idx).toFixed(1) + ' ' + y(p[key]).toFixed(1);
			}).join(' ');
			parts.push('<path d="' + path + '" fill="none" stroke="' + COLORS[key] + '" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>');
			var last = series[n - 1];
			parts.push('<circle cx="' + x(n - 1) + '" cy="' + y(last[key]) + '" r="4" fill="' + COLORS[key] + '"/>');
		});

		// Hover layer: crosshair + per-series dots (moved on mousemove).
		parts.push('<line class="ph-crosshair" x1="0" x2="0" y1="' + MARGIN.top + '" y2="' + (MARGIN.top + plotH) + '" stroke="' + INK.axis + '" stroke-width="1" stroke-dasharray="3 3" visibility="hidden"/>');
		parts.push('<circle class="ph-hoverdot" data-key="pageviews" r="4" fill="' + COLORS.pageviews + '" stroke="#fff" stroke-width="2" visibility="hidden"/>');
		parts.push('<circle class="ph-hoverdot" data-key="visitors" r="4" fill="' + COLORS.visitors + '" stroke="#fff" stroke-width="2" visibility="hidden"/>');

		if (svg) {
			container.removeChild(svg);
		}
		svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
		svg.setAttribute('width', width);
		svg.setAttribute('height', HEIGHT);
		svg.setAttribute('role', 'img');
		svg.innerHTML = parts.join('');
		container.insertBefore(svg, tooltip);

		geometry = { x: x, y: y, n: n, width: width };
	}

	function hideHover() {
		tooltip.hidden = true;
		if (!svg) {
			return;
		}
		svg.querySelector('.ph-crosshair').setAttribute('visibility', 'hidden');
		svg.querySelectorAll('.ph-hoverdot').forEach(function (dot) {
			dot.setAttribute('visibility', 'hidden');
		});
	}

	function onMove(event) {
		if (!svg || !geometry) {
			return;
		}
		var rect = svg.getBoundingClientRect();
		var px = event.clientX - rect.left;
		var n = geometry.n;

		var nearest = 0;
		var best = Infinity;
		for (var i = 0; i < n; i++) {
			var d = Math.abs(geometry.x(i) - px);
			if (d < best) {
				best = d;
				nearest = i;
			}
		}

		var point = series[nearest];
		var cx = geometry.x(nearest);

		svg.querySelector('.ph-crosshair').setAttribute('visibility', 'visible');
		var crosshair = svg.querySelector('.ph-crosshair');
		crosshair.setAttribute('x1', cx);
		crosshair.setAttribute('x2', cx);
		svg.querySelectorAll('.ph-hoverdot').forEach(function (dot) {
			dot.setAttribute('visibility', 'visible');
			dot.setAttribute('cx', cx);
			dot.setAttribute('cy', geometry.y(point[dot.getAttribute('data-key')]));
		});

		tooltip.hidden = false;
		tooltip.innerHTML = '';
		var title = document.createElement('div');
		title.className = 'ph-tooltip__title';
		title.textContent = shortDate(point.day);
		tooltip.appendChild(title);
		[['pageviews', COLORS.pageviews], ['visitors', COLORS.visitors]].forEach(function (entry) {
			var row = document.createElement('div');
			row.className = 'ph-tooltip__row';
			var chip = document.createElement('span');
			chip.className = 'ph-chip';
			chip.style.background = entry[1];
			row.appendChild(chip);
			row.appendChild(document.createTextNode(formatNumber(point[entry[0]])));
			tooltip.appendChild(row);
		});

		var left = cx + 12;
		if (left + 130 > geometry.width) {
			left = cx - 12 - tooltip.offsetWidth;
		}
		tooltip.style.left = left + 'px';
		tooltip.style.top = MARGIN.top + 'px';
	}

	container.addEventListener('mousemove', onMove);
	container.addEventListener('mouseleave', hideHover);

	var resizeTimer = null;
	window.addEventListener('resize', function () {
		window.clearTimeout(resizeTimer);
		resizeTimer = window.setTimeout(function () {
			hideHover();
			draw();
		}, 150);
	});

	draw();
})();
