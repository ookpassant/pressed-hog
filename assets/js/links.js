/**
 * QR codes & trackable links for Pressed Hog.
 *
 * QR generation runs entirely in the browser — the destination URL is never
 * sent to a third-party QR service. The encoder below is a compact port of
 * Project Nayuki's QR Code generator (MIT-licensed, https://www.nayuki.io/),
 * producing a module bitmap we render as inline SVG (for on-screen preview and
 * SVG download) or onto a canvas (for PNG download).
 */
(function () {
	'use strict';

	/* =====================================================================
	 * QR Code encoder (byte mode, automatic version/mask, ECC level M)
	 * Ported from Project Nayuki's "QR Code generator" library.
	 * ================================================================== */

	function appendBits(val, len, bb) {
		for (var i = len - 1; i >= 0; i--) {
			bb.push((val >>> i) & 1);
		}
	}

	function getBit(x, i) {
		return ((x >>> i) & 1) !== 0;
	}

	// Error-correction level: ordinal + format bits.
	var Ecc = {
		LOW: { ordinal: 0, formatBits: 1 },
		MEDIUM: { ordinal: 1, formatBits: 0 },
		QUARTILE: { ordinal: 2, formatBits: 3 },
		HIGH: { ordinal: 3, formatBits: 2 }
	};

	var ECC_CODEWORDS_PER_BLOCK = [
		[-1, 7, 10, 15, 20, 26, 18, 20, 24, 30, 18, 20, 24, 26, 30, 22, 24, 28, 30, 28, 28, 28, 28, 30, 30, 26, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
		[-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28],
		[-1, 13, 22, 18, 26, 18, 24, 18, 22, 20, 24, 28, 26, 24, 20, 30, 24, 28, 28, 26, 30, 28, 30, 30, 30, 30, 28, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30],
		[-1, 17, 28, 22, 16, 22, 28, 26, 26, 24, 28, 24, 28, 22, 24, 24, 30, 28, 28, 26, 28, 30, 24, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30, 30]
	];
	var NUM_ERROR_CORRECTION_BLOCKS = [
		[-1, 1, 1, 1, 1, 1, 2, 2, 2, 2, 4, 4, 4, 4, 4, 6, 6, 6, 6, 7, 8, 8, 9, 9, 10, 12, 12, 12, 13, 14, 15, 16, 17, 18, 19, 19, 20, 21, 22, 24, 25],
		[-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49],
		[-1, 1, 1, 2, 2, 4, 4, 6, 6, 8, 8, 8, 10, 12, 16, 12, 17, 16, 18, 21, 20, 23, 23, 25, 27, 29, 34, 34, 35, 38, 40, 43, 45, 48, 51, 53, 56, 59, 62, 65, 68],
		[-1, 1, 1, 2, 4, 4, 4, 5, 6, 8, 8, 11, 11, 16, 16, 18, 16, 19, 21, 25, 25, 25, 34, 30, 32, 35, 37, 40, 42, 45, 48, 51, 54, 57, 60, 63, 66, 70, 74, 77, 81]
	];

	var PENALTY_N1 = 3, PENALTY_N2 = 3, PENALTY_N3 = 40, PENALTY_N4 = 10;
	var BYTE_MODE_BITS = 0x4;

	function byteCharCountBits(ver) {
		return [8, 16, 16][Math.floor((ver + 7) / 17)];
	}

	function getNumRawDataModules(ver) {
		var result = (16 * ver + 128) * ver + 64;
		if (ver >= 2) {
			var numAlign = Math.floor(ver / 7) + 2;
			result -= (25 * numAlign - 10) * numAlign - 55;
			if (ver >= 7) {
				result -= 36;
			}
		}
		return result;
	}

	function getNumDataCodewords(ver, ecl) {
		return Math.floor(getNumRawDataModules(ver) / 8) -
			ECC_CODEWORDS_PER_BLOCK[ecl.ordinal][ver] *
			NUM_ERROR_CORRECTION_BLOCKS[ecl.ordinal][ver];
	}

	function reedSolomonMultiply(x, y) {
		var z = 0;
		for (var i = 7; i >= 0; i--) {
			z = (z << 1) ^ ((z >>> 7) * 0x11D);
			z ^= ((y >>> i) & 1) * x;
		}
		return z & 0xFF;
	}

	function reedSolomonComputeDivisor(degree) {
		var result = [];
		for (var i = 0; i < degree - 1; i++) {
			result.push(0);
		}
		result.push(1);
		var root = 1;
		for (var j = 0; j < degree; j++) {
			for (var k = 0; k < result.length; k++) {
				result[k] = reedSolomonMultiply(result[k], root);
				if (k + 1 < result.length) {
					result[k] ^= result[k + 1];
				}
			}
			root = reedSolomonMultiply(root, 0x02);
		}
		return result;
	}

	function reedSolomonComputeRemainder(data, divisor) {
		var result = divisor.map(function () { return 0; });
		data.forEach(function (b) {
			var factor = b ^ result.shift();
			result.push(0);
			divisor.forEach(function (coef, i) {
				result[i] ^= reedSolomonMultiply(coef, factor);
			});
		});
		return result;
	}

	// UTF-8 encode a string to an array of byte values.
	function toUtf8Bytes(str) {
		var encoded = unescape(encodeURIComponent(str));
		var bytes = [];
		for (var i = 0; i < encoded.length; i++) {
			bytes.push(encoded.charCodeAt(i) & 0xFF);
		}
		return bytes;
	}

	function QrCode(version, ecl, dataCodewords, msk) {
		this.version = version;
		this.ecl = ecl;
		this.size = version * 4 + 17;
		this.modules = [];
		this.isFunction = [];
		var size = this.size;
		for (var i = 0; i < size; i++) {
			var row = [], funcRow = [];
			for (var j = 0; j < size; j++) {
				row.push(false);
				funcRow.push(false);
			}
			this.modules.push(row);
			this.isFunction.push(funcRow);
		}

		this.drawFunctionPatterns();
		var allCodewords = this.addEccAndInterleave(dataCodewords);
		this.drawCodewords(allCodewords);

		if (msk === -1) {
			var minPenalty = Infinity;
			for (var m = 0; m < 8; m++) {
				this.applyMask(m);
				this.drawFormatBits(m);
				var penalty = this.getPenaltyScore();
				if (penalty < minPenalty) {
					msk = m;
					minPenalty = penalty;
				}
				this.applyMask(m); // Undo (XOR).
			}
		}
		this.mask = msk;
		this.applyMask(msk);
		this.drawFormatBits(msk);
	}

	QrCode.encodeText = function (text) {
		var ecl = Ecc.MEDIUM;
		var data = toUtf8Bytes(text);
		var bb = [];
		data.forEach(function (b) { appendBits(b, 8, bb); });
		var numChars = data.length;

		// Pick the smallest version (1..40) that fits.
		var version, dataCapacityBits;
		for (version = 1; version <= 40; version++) {
			dataCapacityBits = getNumDataCodewords(version, ecl) * 8;
			var usedBits = 4 + byteCharCountBits(version) + bb.length;
			if (numChars < (1 << byteCharCountBits(version)) && usedBits <= dataCapacityBits) {
				break;
			}
			if (version === 40) {
				throw new Error('Data too long');
			}
		}

		// Assemble the final bit buffer with header, terminator and padding.
		var out = [];
		appendBits(BYTE_MODE_BITS, 4, out);
		appendBits(numChars, byteCharCountBits(version), out);
		for (var i = 0; i < bb.length; i++) {
			out.push(bb[i]);
		}
		appendBits(0, Math.min(4, dataCapacityBits - out.length), out);
		appendBits(0, (8 - out.length % 8) % 8, out);
		for (var padByte = 0xEC; out.length < dataCapacityBits; padByte ^= 0xEC ^ 0x11) {
			appendBits(padByte, 8, out);
		}

		var dataCodewords = [];
		while (dataCodewords.length * 8 < out.length) {
			dataCodewords.push(0);
		}
		out.forEach(function (b, idx) {
			dataCodewords[idx >>> 3] |= b << (7 - (idx & 7));
		});

		return new QrCode(version, ecl, dataCodewords, -1);
	};

	QrCode.prototype.getModule = function (x, y) {
		return x >= 0 && x < this.size && y >= 0 && y < this.size && this.modules[y][x];
	};

	QrCode.prototype.setFunctionModule = function (x, y, isDark) {
		this.modules[y][x] = isDark;
		this.isFunction[y][x] = true;
	};

	QrCode.prototype.drawFunctionPatterns = function () {
		var size = this.size, i;
		for (i = 0; i < size; i++) {
			this.setFunctionModule(6, i, i % 2 === 0);
			this.setFunctionModule(i, 6, i % 2 === 0);
		}
		this.drawFinderPattern(3, 3);
		this.drawFinderPattern(size - 4, 3);
		this.drawFinderPattern(3, size - 4);

		var alignPos = this.getAlignmentPatternPositions();
		var numAlign = alignPos.length;
		for (i = 0; i < numAlign; i++) {
			for (var j = 0; j < numAlign; j++) {
				if (!((i === 0 && j === 0) || (i === 0 && j === numAlign - 1) || (i === numAlign - 1 && j === 0))) {
					this.drawAlignmentPattern(alignPos[i], alignPos[j]);
				}
			}
		}

		this.drawFormatBits(0);
		this.drawVersion();
	};

	QrCode.prototype.drawFormatBits = function (mask) {
		var data = (this.ecl.formatBits << 3) | mask;
		var rem = data, i;
		for (i = 0; i < 10; i++) {
			rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
		}
		var bits = ((data << 10) | rem) ^ 0x5412;

		for (i = 0; i <= 5; i++) {
			this.setFunctionModule(8, i, getBit(bits, i));
		}
		this.setFunctionModule(8, 7, getBit(bits, 6));
		this.setFunctionModule(8, 8, getBit(bits, 7));
		this.setFunctionModule(7, 8, getBit(bits, 8));
		for (i = 9; i < 15; i++) {
			this.setFunctionModule(14 - i, 8, getBit(bits, i));
		}

		var size = this.size;
		for (i = 0; i < 8; i++) {
			this.setFunctionModule(size - 1 - i, 8, getBit(bits, i));
		}
		for (i = 8; i < 15; i++) {
			this.setFunctionModule(8, size - 15 + i, getBit(bits, i));
		}
		this.setFunctionModule(8, size - 8, true);
	};

	QrCode.prototype.drawVersion = function () {
		if (this.version < 7) {
			return;
		}
		var rem = this.version, i;
		for (i = 0; i < 12; i++) {
			rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
		}
		var bits = (this.version << 12) | rem;
		for (i = 0; i < 18; i++) {
			var color = getBit(bits, i);
			var a = this.size - 11 + (i % 3);
			var b = Math.floor(i / 3);
			this.setFunctionModule(a, b, color);
			this.setFunctionModule(b, a, color);
		}
	};

	QrCode.prototype.drawFinderPattern = function (x, y) {
		for (var dy = -4; dy <= 4; dy++) {
			for (var dx = -4; dx <= 4; dx++) {
				var dist = Math.max(Math.abs(dx), Math.abs(dy));
				var xx = x + dx, yy = y + dy;
				if (xx >= 0 && xx < this.size && yy >= 0 && yy < this.size) {
					this.setFunctionModule(xx, yy, dist !== 2 && dist !== 4);
				}
			}
		}
	};

	QrCode.prototype.drawAlignmentPattern = function (x, y) {
		for (var dy = -2; dy <= 2; dy++) {
			for (var dx = -2; dx <= 2; dx++) {
				this.setFunctionModule(x + dx, y + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
			}
		}
	};

	QrCode.prototype.getAlignmentPatternPositions = function () {
		if (this.version === 1) {
			return [];
		}
		var numAlign = Math.floor(this.version / 7) + 2;
		var step = (this.version === 32) ? 26 :
			Math.ceil((this.version * 4 + 4) / (numAlign * 2 - 2)) * 2;
		var result = [6];
		for (var pos = this.size - 7; result.length < numAlign; pos -= step) {
			result.splice(1, 0, pos);
		}
		return result;
	};

	QrCode.prototype.addEccAndInterleave = function (data) {
		var ver = this.version, ecl = this.ecl;
		var numBlocks = NUM_ERROR_CORRECTION_BLOCKS[ecl.ordinal][ver];
		var blockEccLen = ECC_CODEWORDS_PER_BLOCK[ecl.ordinal][ver];
		var rawCodewords = Math.floor(getNumRawDataModules(ver) / 8);
		var numShortBlocks = numBlocks - rawCodewords % numBlocks;
		var shortBlockLen = Math.floor(rawCodewords / numBlocks);

		var blocks = [];
		var rsDiv = reedSolomonComputeDivisor(blockEccLen);
		for (var i = 0, k = 0; i < numBlocks; i++) {
			var dat = data.slice(k, k + shortBlockLen - blockEccLen + (i < numShortBlocks ? 0 : 1));
			k += dat.length;
			var ecc = reedSolomonComputeRemainder(dat, rsDiv);
			if (i < numShortBlocks) {
				dat.push(0);
			}
			blocks.push(dat.concat(ecc));
		}

		var result = [];
		for (var idx = 0; idx < blocks[0].length; idx++) {
			for (var b = 0; b < blocks.length; b++) {
				if (idx !== shortBlockLen - blockEccLen || b >= numShortBlocks) {
					result.push(blocks[b][idx]);
				}
			}
		}
		return result;
	};

	QrCode.prototype.drawCodewords = function (data) {
		var i = 0, size = this.size;
		for (var right = size - 1; right >= 1; right -= 2) {
			if (right === 6) {
				right = 5;
			}
			for (var vert = 0; vert < size; vert++) {
				for (var j = 0; j < 2; j++) {
					var x = right - j;
					var upward = ((right + 1) & 2) === 0;
					var y = upward ? size - 1 - vert : vert;
					if (!this.isFunction[y][x] && i < data.length * 8) {
						this.modules[y][x] = getBit(data[i >>> 3], 7 - (i & 7));
						i++;
					}
				}
			}
		}
	};

	QrCode.prototype.applyMask = function (mask) {
		for (var y = 0; y < this.size; y++) {
			for (var x = 0; x < this.size; x++) {
				var invert;
				switch (mask) {
					case 0: invert = (x + y) % 2 === 0; break;
					case 1: invert = y % 2 === 0; break;
					case 2: invert = x % 3 === 0; break;
					case 3: invert = (x + y) % 3 === 0; break;
					case 4: invert = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; break;
					case 5: invert = (x * y) % 2 + (x * y) % 3 === 0; break;
					case 6: invert = ((x * y) % 2 + (x * y) % 3) % 2 === 0; break;
					case 7: invert = ((x + y) % 2 + (x * y) % 3) % 2 === 0; break;
					default: invert = false;
				}
				if (!this.isFunction[y][x] && invert) {
					this.modules[y][x] = !this.modules[y][x];
				}
			}
		}
	};

	QrCode.prototype.getPenaltyScore = function () {
		var result = 0, size = this.size, modules = this.modules;
		var x, y, runColor, runLen, runHistory;

		// Rows.
		for (y = 0; y < size; y++) {
			runColor = false;
			runLen = 0;
			runHistory = [0, 0, 0, 0, 0, 0, 0];
			for (x = 0; x < size; x++) {
				if (modules[y][x] === runColor) {
					runLen++;
					if (runLen === 5) { result += PENALTY_N1; }
					else if (runLen > 5) { result++; }
				} else {
					this.finderPenaltyAddHistory(runLen, runHistory);
					if (!runColor) { result += this.finderPenaltyCountPatterns(runHistory) * PENALTY_N3; }
					runColor = modules[y][x];
					runLen = 1;
				}
			}
			result += this.finderPenaltyTerminateAndCount(runColor, runLen, runHistory) * PENALTY_N3;
		}
		// Columns.
		for (x = 0; x < size; x++) {
			runColor = false;
			runLen = 0;
			runHistory = [0, 0, 0, 0, 0, 0, 0];
			for (y = 0; y < size; y++) {
				if (modules[y][x] === runColor) {
					runLen++;
					if (runLen === 5) { result += PENALTY_N1; }
					else if (runLen > 5) { result++; }
				} else {
					this.finderPenaltyAddHistory(runLen, runHistory);
					if (!runColor) { result += this.finderPenaltyCountPatterns(runHistory) * PENALTY_N3; }
					runColor = modules[y][x];
					runLen = 1;
				}
			}
			result += this.finderPenaltyTerminateAndCount(runColor, runLen, runHistory) * PENALTY_N3;
		}

		// 2x2 blocks.
		for (y = 0; y < size - 1; y++) {
			for (x = 0; x < size - 1; x++) {
				var color = modules[y][x];
				if (color === modules[y][x + 1] && color === modules[y + 1][x] && color === modules[y + 1][x + 1]) {
					result += PENALTY_N2;
				}
			}
		}

		// Dark/light balance.
		var dark = 0;
		for (y = 0; y < size; y++) {
			for (x = 0; x < size; x++) {
				if (modules[y][x]) { dark++; }
			}
		}
		var total = size * size;
		var kk = Math.ceil(Math.abs(dark * 20 - total * 10) / total) - 1;
		result += kk * PENALTY_N4;
		return result;
	};

	QrCode.prototype.finderPenaltyCountPatterns = function (runHistory) {
		var n = runHistory[1];
		var core = n > 0 && runHistory[2] === n && runHistory[3] === n * 3 && runHistory[4] === n && runHistory[5] === n;
		return (core && runHistory[0] >= n * 4 && runHistory[6] >= n ? 1 : 0) +
			(core && runHistory[6] >= n * 4 && runHistory[0] >= n ? 1 : 0);
	};

	QrCode.prototype.finderPenaltyTerminateAndCount = function (runColor, runLen, runHistory) {
		if (runColor) {
			this.finderPenaltyAddHistory(runLen, runHistory);
			runLen = 0;
		}
		runLen += this.size;
		this.finderPenaltyAddHistory(runLen, runHistory);
		return this.finderPenaltyCountPatterns(runHistory);
	};

	QrCode.prototype.finderPenaltyAddHistory = function (runLen, runHistory) {
		if (runHistory[0] === 0) {
			runLen += this.size;
		}
		runHistory.pop();
		runHistory.unshift(runLen);
	};

	/* =====================================================================
	 * Rendering helpers
	 * ================================================================== */

	var BORDER = 4;

	function toSvgString(qr) {
		var parts = [];
		for (var y = 0; y < qr.size; y++) {
			for (var x = 0; x < qr.size; x++) {
				if (qr.getModule(x, y)) {
					parts.push('M' + (x + BORDER) + ',' + (y + BORDER) + 'h1v1h-1z');
				}
			}
		}
		var dim = qr.size + BORDER * 2;
		return '<svg xmlns="http://www.w3.org/2000/svg" version="1.1" viewBox="0 0 ' + dim + ' ' + dim + '" shape-rendering="crispEdges">' +
			'<rect width="100%" height="100%" fill="#ffffff"/>' +
			'<path d="' + parts.join(' ') + '" fill="#000000"/>' +
			'</svg>';
	}

	function toCanvas(qr, scale) {
		var dim = (qr.size + BORDER * 2) * scale;
		var canvas = document.createElement('canvas');
		canvas.width = dim;
		canvas.height = dim;
		var ctx = canvas.getContext('2d');
		ctx.fillStyle = '#ffffff';
		ctx.fillRect(0, 0, dim, dim);
		ctx.fillStyle = '#000000';
		for (var y = 0; y < qr.size; y++) {
			for (var x = 0; x < qr.size; x++) {
				if (qr.getModule(x, y)) {
					ctx.fillRect((x + BORDER) * scale, (y + BORDER) * scale, scale, scale);
				}
			}
		}
		return canvas;
	}

	function renderInto(el, text) {
		try {
			var qr = QrCode.encodeText(text);
			el.innerHTML = toSvgString(qr);
			el.classList.remove('ph-qr--empty');
			return qr;
		} catch (e) {
			el.innerHTML = '';
			el.classList.add('ph-qr--empty');
			el.textContent = el.getAttribute('data-empty-text') || '';
			return null;
		}
	}

	function slugify(text) {
		return String(text || '').toLowerCase()
			.replace(/[^a-z0-9]+/g, '-')
			.replace(/^-+|-+$/g, '')
			.slice(0, 40) || 'qr-code';
	}

	function triggerDownload(href, filename) {
		var a = document.createElement('a');
		a.href = href;
		a.download = filename;
		document.body.appendChild(a);
		a.click();
		document.body.removeChild(a);
	}

	function downloadPng(text, label) {
		var qr = QrCode.encodeText(text);
		var canvas = toCanvas(qr, 10);
		if (canvas.toBlob) {
			canvas.toBlob(function (blob) {
				var url = URL.createObjectURL(blob);
				triggerDownload(url, slugify(label) + '.png');
				setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
			});
		} else {
			triggerDownload(canvas.toDataURL('image/png'), slugify(label) + '.png');
		}
	}

	function downloadSvg(text, label) {
		var qr = QrCode.encodeText(text);
		var blob = new Blob([toSvgString(qr)], { type: 'image/svg+xml' });
		var url = URL.createObjectURL(blob);
		triggerDownload(url, slugify(label) + '.svg');
		setTimeout(function () { URL.revokeObjectURL(url); }, 1000);
	}

	/* =====================================================================
	 * Trackable-URL preview (mirrors the server-side builder)
	 * ================================================================== */

	function buildTrackedUrl(destination) {
		var source = value('source');
		var medium = value('medium');
		var campaign = value('campaign');

		var fragment = '';
		var hashPos = destination.indexOf('#');
		if (hashPos !== -1) {
			fragment = destination.slice(hashPos);
			destination = destination.slice(0, hashPos);
		}

		var pairs = [];
		if (source) { pairs.push('utm_source=' + encodeURIComponent(source)); }
		if (medium) { pairs.push('utm_medium=' + encodeURIComponent(medium)); }
		if (campaign) { pairs.push('utm_campaign=' + encodeURIComponent(campaign)); }
		if (!pairs.length) { return destination + fragment; }

		var sep = destination.indexOf('?') === -1 ? '?' : '&';
		return destination + sep + pairs.join('&') + fragment;
	}

	function value(name) {
		var el = document.getElementById('ph-field-' + name);
		return el ? el.value.trim() : '';
	}

	function isHttpUrl(str) {
		return /^https?:\/\/.+/i.test(str);
	}

	/* =====================================================================
	 * Wire up the page
	 * ================================================================== */

	document.addEventListener('DOMContentLoaded', function () {
		setupPreview();
		setupCopyButtons();
		setupModal();
	});

	function setupPreview() {
		var previewQr = document.getElementById('ph-links-preview-qr');
		var previewUrl = document.getElementById('ph-links-preview-url');
		var form = document.getElementById('ph-links-form');
		if (!previewQr || !form) {
			return;
		}

		function update() {
			var destination = value('destination');
			if (!isHttpUrl(destination)) {
				previewQr.innerHTML = '';
				previewQr.classList.add('ph-qr--empty');
				previewQr.textContent = previewQr.getAttribute('data-empty-text') || '';
				if (previewUrl) { previewUrl.textContent = ''; }
				return;
			}
			var url = buildTrackedUrl(destination);
			renderInto(previewQr, url);
			if (previewUrl) { previewUrl.textContent = url; }
		}

		['destination', 'source', 'medium', 'campaign'].forEach(function (name) {
			var el = document.getElementById('ph-field-' + name);
			if (el) {
				el.addEventListener('input', update);
			}
		});
		update();
	}

	function setupCopyButtons() {
		document.addEventListener('click', function (e) {
			var btn = e.target.closest ? e.target.closest('.ph-copy') : null;
			if (!btn) {
				return;
			}
			e.preventDefault();
			var text = btn.getAttribute('data-copy');
			var done = function () {
				var original = btn.textContent;
				btn.textContent = (window.pressedHogLinks && window.pressedHogLinks.i18n.copied) || 'Copied!';
				setTimeout(function () { btn.textContent = original; }, 1500);
			};
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(text).then(done, function () { fallbackCopy(text, done); });
			} else {
				fallbackCopy(text, done);
			}
		});
	}

	function fallbackCopy(text, done) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); done(); } catch (e) { /* noop */ }
		document.body.removeChild(ta);
	}

	function setupModal() {
		var modal = document.getElementById('ph-qr-modal');
		if (!modal) {
			return;
		}
		var titleEl = modal.querySelector('.ph-qr-modal__title');
		var canvasEl = document.getElementById('ph-qr-modal-canvas');
		var urlEl = modal.querySelector('.ph-qr-modal__url');
		var current = { url: '', label: '' };

		function open(url, label) {
			current.url = url;
			current.label = label;
			titleEl.textContent = label;
			urlEl.textContent = url;
			renderInto(canvasEl, url);
			modal.hidden = false;
			document.body.classList.add('ph-modal-open');
		}

		function close() {
			modal.hidden = true;
			document.body.classList.remove('ph-modal-open');
		}

		document.addEventListener('click', function (e) {
			var showBtn = e.target.closest ? e.target.closest('.ph-qr-show') : null;
			if (showBtn) {
				e.preventDefault();
				open(showBtn.getAttribute('data-url'), showBtn.getAttribute('data-label') || 'qr-code');
				return;
			}
			if (e.target.closest && e.target.closest('[data-close]')) {
				close();
			}
			var dl = e.target.closest ? e.target.closest('[data-download]') : null;
			if (dl && !modal.hidden) {
				if (dl.getAttribute('data-download') === 'png') {
					downloadPng(current.url, current.label);
				} else {
					downloadSvg(current.url, current.label);
				}
			}
		});

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && !modal.hidden) {
				close();
			}
		});
	}
})();
