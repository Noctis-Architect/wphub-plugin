(function () {
	'use strict';

	var d = window.wphs;
	if (!d) {
		var el = document.currentScript || document.getElementById('wphs-tracker-js');
		if (el && el.getAttribute) {
			try {
				d = JSON.parse(el.getAttribute('data-wphs') || '');
			} catch (e) {}
		}
	}
	if (!d || !d.e || !d.h || !d.s) {
		return;
	}
	var dnt = navigator.doNotTrack || window.doNotTrack;
	if (d.d && (navigator.globalPrivacyControl === true || dnt === '1' || dnt === 'yes' || dnt === 1)) {
		return;
	}

	var cid = rid();
	var seq = 0;
	var pending = 0;
	var last = Date.now();
	var sentPv = false;
	var marks = {};
	var events = {};
	var scrollTimer = 0;
	var interactions = 0;
	var rafTicks = 0;
	var glClass = 0;
	var glDone = false;

	function rid() {
		var bytes = new Uint8Array(16);
		var out = '';
		var i;
		if (window.crypto && window.crypto.getRandomValues) {
			window.crypto.getRandomValues(bytes);
		} else {
			for (i = 0; i < 16; i++) {
				bytes[i] = Math.floor(Math.random() * 256);
			}
		}
		for (i = 0; i < 16; i++) {
			out += ('0' + bytes[i].toString(16)).slice(-2);
		}
		return out;
	}

	function clean(value, max) {
		value = String(value || '');
		return value.length > max ? value.slice(0, max) : value;
	}

	function send(payload) {
		var body = 'action=wphs_collect&payload=' + encodeURIComponent(JSON.stringify(payload));
		try {
			if (navigator.sendBeacon) {
				navigator.sendBeacon(d.e, new Blob([body], { type: 'application/x-www-form-urlencoded' }));
				return;
			}
		} catch (e) {}
		try {
			window.fetch(d.e, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body,
				keepalive: true
			});
		} catch (e) {}
	}

	function platformCategory() {
		var platform = '';
		try {
			platform = String((navigator.userAgentData && navigator.userAgentData.platform) || navigator.platform || '');
		} catch (e) {}
		platform = platform.toLowerCase();

		if (/android/.test(platform) || /android/.test(navigator.userAgent || '')) {
			return 4;
		}
		if (/iphone|ipad|ipod|ios/.test(platform)) {
			return 5;
		}
		if (/win/.test(platform)) {
			return 1;
		}
		if (/mac/.test(platform)) {
			return 2;
		}
		if (/linux|x11|cros/.test(platform)) {
			return 3;
		}

		return 0;
	}

	function softwareGl() {
		if (glDone) {
			return glClass;
		}
		glDone = true;

		try {
			var canvas = document.createElement('canvas');
			var gl = canvas.getContext('webgl') || canvas.getContext('experimental-webgl');
			if (!gl) {
				return glClass;
			}
			var info = gl.getExtension('WEBGL_debug_renderer_info');
			var renderer = info ? String(gl.getParameter(info.UNMASKED_RENDERER_WEBGL) || '') : '';
			if (/swiftshader|llvmpipe|software|mesa offscreen|basic render/i.test(renderer)) {
				glClass = 2;
			} else if (renderer) {
				glClass = 1;
			}
		} catch (e) {}

		return glClass;
	}

	function notificationState() {
		try {
			if (!('Notification' in window)) {
				return -1;
			}
			if (Notification.permission === 'granted') {
				return 1;
			}
			if (Notification.permission === 'denied') {
				return 2;
			}
			return 0;
		} catch (e) {
			return -1;
		}
	}

	function signals() {
		var languages = 0;
		try {
			if (navigator.languages && navigator.languages.length) {
				languages = navigator.languages.length;
			} else if (navigator.language) {
				languages = 1;
			}
		} catch (e) {}

		return {
			w: navigator.webdriver === true ? 1 : 0,
			l: languages,
			p: navigator.plugins ? navigator.plugins.length : -1,
			sw: window.screen ? Number(screen.width) || 0 : 0,
			sh: window.screen ? Number(screen.height) || 0 : 0,
			ow: Number(window.outerWidth) || 0,
			oh: Number(window.outerHeight) || 0,
			hc: Number(navigator.hardwareConcurrency) || 0,
			dm: Number(navigator.deviceMemory) || 0,
			gl: softwareGl(),
			nt: notificationState(),
			ch: window.chrome ? 1 : 0,
			raf: rafTicks,
			it: interactions,
			vs: document.visibilityState === 'visible' ? 1 : 0,
			ts: window.performance && performance.now ? Math.round(performance.now()) : -1,
			tp: Number(navigator.maxTouchPoints) || 0,
			pf: platformCategory()
		};
	}

	function pageview() {
		if (sentPv) {
			return;
		}
		sentPv = true;
		send({
			t: 'pv',
			id: cid,
			h: d.h,
			p: d.p,
			sg: d.s,
			r: clean(document.referrer, 300),
			q: clean(window.location.search, 300),
			ti: clean(document.title, 120),
			bs: signals()
		});
	}

	function markInteraction() {
		if (interactions < 20) {
			interactions++;
		}
	}

	function addActive(force) {
		var now = Date.now();
		if (force || !document.hidden) {
			pending += Math.min(30, (now - last) / 1000);
		}
		last = now;
	}

	function flush(force) {
		addActive(force);

		var sec = Math.floor(pending);
		var sc = [];
		var ev = {};
		var key;

		for (key in marks) {
			if (marks[key]) {
				sc.push(Number(key));
			}
		}
		for (key in events) {
			if (events[key]) {
				ev[key] = events[key];
			}
		}

		if (!sec && !sc.length && !Object.keys(ev).length) {
			return;
		}

		pending -= sec;
		marks = {};
		events = {};

		send({
			t: 'eng',
			id: cid,
			h: d.h,
			p: d.p,
			sg: d.s,
			sq: ++seq,
			s: sec,
			sc: sc,
			ev: ev
		});
	}

	function checkScroll() {
		var el = document.documentElement;
		var total = Math.max(el.scrollHeight, document.body ? document.body.scrollHeight : 0);
		var top = window.pageYOffset || el.scrollTop || 0;
		var pct = total > 0 ? ((top + window.innerHeight) / total) * 100 : 100;
		var levels = [25, 50, 75, 100];
		var i;

		for (i = 0; i < levels.length; i++) {
			if (pct >= levels[i]) {
				marks[levels[i]] = 1;
			}
		}
	}

	function onClick(event) {
		var el = event.target;

		while (el && el.tagName !== 'A') {
			el = el.parentNode;
		}
		if (!el || !el.href) {
			return;
		}

		try {
			var url = new URL(el.href, window.location.href);

			if (url.host !== window.location.host) {
				events.outbound = (events.outbound || 0) + 1;
				return;
			}

			if (/\.(pdf|zip|rar|7z|docx?|xlsx?|pptx?|mp3|mp4|avi|mkv|apk|dmg|exe|csv)$/i.test(url.pathname)) {
				events.download = (events.download || 0) + 1;
			}

			if (el.className && /add_to_cart_button|single_add_to_cart_button/.test(String(el.className))) {
				events.cart = (events.cart || 0) + 1;
			}
		} catch (e) {}
	}

	function countAnimationFrame() {
		if (rafTicks >= 3) {
			return;
		}
		rafTicks++;
		if (window.requestAnimationFrame) {
			window.requestAnimationFrame(countAnimationFrame);
		}
	}

	function startPageview() {
		if (document.prerendering) {
			document.addEventListener('prerenderingchange', function () {
				last = Date.now();
				schedulePageview();
			}, { once: true });
			return;
		}
		schedulePageview();
	}

	function schedulePageview() {
		var sent = false;
		var finish = function () {
			if (sent) {
				return;
			}
			sent = true;
			if (window.requestAnimationFrame) {
				window.requestAnimationFrame(function () {
					window.setTimeout(pageview, 150);
				});
			} else {
				window.setTimeout(pageview, 150);
			}
		};

		if (document.readyState === 'complete') {
			finish();
			return;
		}

		window.addEventListener('load', finish, { once: true });
		window.setTimeout(finish, 2500);
	}

	checkScroll();
	if (window.requestAnimationFrame) {
		window.requestAnimationFrame(countAnimationFrame);
	} else {
		rafTicks = 3;
	}

	window.addEventListener('scroll', function () {
		markInteraction();
		if (scrollTimer) {
			return;
		}
		scrollTimer = window.setTimeout(function () {
			scrollTimer = 0;
			checkScroll();
		}, 250);
	}, { passive: true });

	['pointermove', 'mousemove', 'touchstart', 'keydown', 'wheel', 'pointerdown', 'click'].forEach(function (name) {
		window.addEventListener(name, markInteraction, { passive: true, capture: true });
	});

	document.addEventListener('click', onClick, true);
	document.addEventListener('submit', function () {
		events.form = (events.form || 0) + 1;
	}, true);

	document.addEventListener('visibilitychange', function () {
		if (document.hidden) {
			flush(true);
		} else {
			last = Date.now();
		}
	});

	window.addEventListener('pagehide', function () {
		flush(true);
	});

	window.setInterval(function () {
		addActive(false);
		if (pending >= 30) {
			flush(false);
		}
	}, 10000);

	startPageview();
})();
