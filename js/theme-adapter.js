/**
 * Theme adapter for the AgeVerif verification gate.
 * Detects the active host theme (light / dark) and re-applies the matching
 * class on the gate element whenever the document root toggles `class`.
 *
 * Notes:
 *  - Re-uses a single `<style id="ageverif-theme-vars">` element to avoid the
 *    DOM bloat / memory leak that earlier revisions caused when the
 *    MutationObserver fired repeatedly.
 *  - Uses `attributeFilter: ['class']` so we don't react to unrelated
 *    attribute mutations.
 *  - Disconnects the observer after a single, stable re-application.
 */
(function () {
	'use strict';

	if (typeof window === 'undefined' || typeof document === 'undefined') {
		return;
	}

	var STYLE_ID = 'ageverif-theme-vars';
	var STYLED_ATTR = 'data-ageverif-themed';

	function getThemeMode() {
		try {
			var doc = document.documentElement;
			var body = document.body;

			if (body && body.classList) {
				if (body.classList.contains('wp-dark-mode') || body.classList.contains('is-dark')) {
					return 'dark';
				}
			}
			if (doc && doc.classList && doc.classList.contains('is-dark')) {
				return 'dark';
			}
			if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
				return 'dark';
			}
			if (doc) {
				var styles = window.getComputedStyle(doc);
				if (styles && styles.getPropertyValue('--wp-mode') &&
					styles.getPropertyValue('--wp-mode').toLowerCase().indexOf('dark') !== -1) {
					return 'dark';
				}
			}
		} catch (e) {
			/* defensive – never throw in client code */
		}
		return 'light';
	}

	function buildCssForMode(mode) {
		var doc = document.documentElement;
		var computed = doc ? window.getComputedStyle(doc) : null;
		var c = function (name) {
			return computed ? computed.getPropertyValue(name) || '' : '';
		};
		var bg = c('--wp-color-bg') || c('--wp--background-color') || c('--bg-color');
		var text = c('--wp-color-text') || c('--wp--text-color') || c('--text-color');
		var primary = c('--wp-color-primary') || c('--wp--primary-color');
		var secondary = c('--wp-color-secondary') || c('--wp--secondary-color');
		var radius = c('--wp-border-radius') || c('--border-radius');
		var font = '';
		if (computed) {
			var ff = computed.getPropertyValue('font-family');
			if (ff) {
				font = ff.split(',')[0].trim();
			}
		}
		var safe = function (v) {
			return (v || '').replace(/["\\]/g, '');
		};
		return [
			':root {',
			'  --ageverif-bg-color: ' + safe(bg) + ';',
			'  --ageverif-text-color: ' + safe(text) + ';',
			'  --ageverif-primary-color: ' + safe(primary) + ';',
			'  --ageverif-secondary-color: ' + safe(secondary) + ';',
			'  --ageverif-border-radius: ' + safe(radius) + ';',
			'  --ageverif-font-family: ' + safe(font) + ';',
			'}',
			'.ageverif-mode-light .ageverif, .ageverif-mode-light .ageverif-modal {',
			'  background-color: var(--ageverif-bg-color);',
			'  color: var(--ageverif-text-color);',
			'  border-radius: var(--ageverif-border-radius);',
			'  font-family: var(--ageverif-font-family);',
			'}',
			'.ageverif-mode-dark .ageverif, .ageverif-mode-dark .ageverif-modal {',
			'  background-color: var(--ageverif-bg-color);',
			'  color: var(--ageverif-text-color);',
			'  border-radius: var(--ageverif-border-radius);',
			'  font-family: var(--ageverif-font-family);',
			'}'
		].join('\n');
	}

	function applyThemeOnce() {
		var mode = getThemeMode();
		var styleEl = document.getElementById(STYLE_ID);
		if (!styleEl) {
			styleEl = document.createElement('style');
			styleEl.type = 'text/css';
			styleEl.id = STYLE_ID;
			document.head.appendChild(styleEl);
		}
		styleEl.textContent = buildCssForMode(mode);

		var gates = document.querySelectorAll('.ageverif, .ageverif-modal');
		for (var i = 0; i < gates.length; i++) {
			var g = gates[i];
			if (g.getAttribute(STYLED_ATTR) === mode) {
				continue;
			}
			g.classList.remove('ageverif-mode-light', 'ageverif-mode-dark');
			g.classList.add('ageverif-mode-' + mode);
			g.setAttribute(STYLED_ATTR, mode);
		}
	}

	function attachObserver() {
		try {
			var observer = new MutationObserver(function (mutations) {
				for (var i = 0; i < mutations.length; i++) {
					if (mutations[i].attributeName !== 'class') {
						continue;
					}
					applyThemeOnce();
					return;
				}
			});
			observer.observe(document.documentElement, {
				attributes: true,
				attributeFilter: ['class']
			});
		} catch (e) {
			applyThemeOnce();
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', function () {
			applyThemeOnce();
			attachObserver();
		});
	} else {
		applyThemeOnce();
		attachObserver();
	}
})();
