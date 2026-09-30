/**
 * Webklient Forms – sledování cesty návštěvníka (journey) pro kontext u poptávek.
 * Data žijí jen v sessionStorage prohlížeče (žádné cookies, žádný přenos na třetí
 * strany) a odesílají se teprve ve chvíli, kdy návštěvník sám odešle formulář.
 */
(function () {
	'use strict';

	var STORE = 'wkfJourney';
	var config = window.wkfJourneyConfig || {};
	var maxSteps = parseInt(config.maxSteps, 10) || 30;

	function read() {
		try {
			var raw = window.sessionStorage.getItem(STORE);
			return raw ? JSON.parse(raw) : null;
		} catch (err) {
			return null;
		}
	}

	function write(data) {
		try {
			window.sessionStorage.setItem(STORE, JSON.stringify(data));
		} catch (err) {
			/* privátní režim nebo plné úložiště – sledování se prostě neuloží */
		}
	}

	/** Vyhledávaný výraz z odkazující URL, pokud ho vyhledávač předává. */
	function searchTerm(url) {
		try {
			var params = new URL(url).searchParams;
			var keys = ['q', 'query', 'wd', 'text', 'p', 'search', 'k'];
			for (var i = 0; i < keys.length; i++) {
				var value = params.get(keys[i]);
				if (value && value.trim()) {
					return value.trim();
				}
			}
		} catch (err) {
			/* neplatná URL */
		}
		return '';
	}

	function sourceOf(referrer, params) {
		if (params.get('utm_source')) {
			return params.get('utm_source') + (params.get('utm_medium') ? ' / ' + params.get('utm_medium') : '');
		}
		if (params.get('gclid')) { return 'google / cpc'; }
		if (params.get('fbclid')) { return 'facebook / referral'; }
		if (!referrer) { return 'přímý vstup'; }
		try {
			var host = new URL(referrer).hostname.replace(/^www\./, '');
			if (host === window.location.hostname.replace(/^www\./, '')) { return ''; }
			var engines = ['google.', 'seznam.cz', 'bing.com', 'duckduckgo.com', 'ecosia.org', 'yandex.', 'centrum.cz', 'search.brave.com'];
			for (var i = 0; i < engines.length; i++) {
				if (host.indexOf(engines[i]) !== -1) { return host + ' / vyhledávání'; }
			}
			var social = ['facebook.com', 'instagram.com', 'linkedin.com', 't.co', 'x.com', 'youtube.com'];
			for (var s = 0; s < social.length; s++) {
				if (host.indexOf(social[s]) !== -1) { return host + ' / sociální síť'; }
			}
			return host + ' / odkaz';
		} catch (err) {
			return '';
		}
	}

	function track() {
		var params = new URLSearchParams(window.location.search);
		var data = read();
		var now = Date.now();

		if (!data) {
			var referrer = document.referrer || '';
			data = {
				landing: window.location.href,
				landingTitle: document.title,
				referrer: referrer,
				source: sourceOf(referrer, params),
				keyword: searchTerm(referrer),
				utm: {},
				started: now,
				steps: []
			};
			['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'gclid'].forEach(function (key) {
				if (params.get(key)) {
					data.utm[key] = params.get(key);
				}
			});
			// Klíčové slovo z utm_term (placené kampaně ho obvykle předávají).
			if (!data.keyword && params.get('utm_term')) {
				data.keyword = params.get('utm_term');
			}
		}

		var last = data.steps[data.steps.length - 1];
		if (!last || last.url !== window.location.href) {
			data.steps.push({ url: window.location.href, title: document.title, t: now });
			if (data.steps.length > maxSteps) {
				// Uprostřed dlouhé cesty se ubírá, začátek a konec jsou zajímavější.
				data.steps.splice(Math.floor(maxSteps / 2), data.steps.length - maxSteps);
			}
		}
		write(data);
		return data;
	}

	var journey = track();

	/** Vyplní skryté pole formuláře těsně před odesláním. */
	window.wkfJourneyPayload = function () {
		var data = read() || journey;
		if (!data) {
			return '';
		}
		var minutes = Math.max(0, Math.round((Date.now() - data.started) / 60000));
		var payload = {
			landing: data.landing,
			landingTitle: data.landingTitle,
			referrer: data.referrer,
			source: data.source,
			keyword: data.keyword,
			utm: data.utm,
			minutes: minutes,
			pages: data.steps.length,
			steps: data.steps.map(function (s) {
				return { url: s.url, title: s.title, t: Math.round((s.t - data.started) / 1000) };
			})
		};
		return JSON.stringify(payload);
	};
})();
