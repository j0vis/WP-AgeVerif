/**
 * AgeVerif integration glue.
 * Reacts to events fired by https://www.ageverif.com/checker.js:
 *   ageverif:load   - checker script has loaded
 *   ageverif:ready  - gate UI has appeared
 *   ageverif:close  - gate was closed (with or without verification)
 *   ageverif:error  - checker failed to initialize or verify
 *   ageverif:success - verification succeeded
 *
 * Config is provided inline by the WordPress plugin in the variable
 * `window.ageverifIntegration` BEFORE this script runs.
 *
 *   cfg = {
 *     blurContent: bool,         // apply a CSS blur to the page until success
 *     underageRedirectUrl: str,  // where to send visitors who fail / close
 *     manualStart: bool          // start via .ageverif-trigger click delegation
 *   }
 */
(function () {
	'use strict';

	function getCfg() {
		return (typeof window !== 'undefined' && window.ageverifIntegration) || {};
	}

	function ensureStyle(id, css) {
		var el = document.getElementById(id);
		if (!el) {
			el = document.createElement('style');
			el.type = 'text/css';
			el.id = id;
			document.head.appendChild(el);
		}
		el.textContent = css;
		return el;
	}

	function applyBlur() {
		try {
			document.documentElement.classList.add('ageverif-blur-on');
			ensureStyle(
				'ageverif-blur-css',
				'.ageverif-blur-on > *:not(.ageverif):not(.ageverif-modal):not(.ageverif-overlay) {' +
				'  filter: blur(8px);' +
				'  pointer-events: none;' +
				'  user-select: none;' +
				'  transition: filter .25s ease;' +
				'}' +
				'.ageverif-blur-on > .ageverif,' +
				'.ageverif-blur-on > .ageverif-modal,' +
				'.ageverif-blur-on > .ageverif-overlay { filter: none !important; pointer-events: auto !important; }'
			);
		} catch (e) { /* defensive */ }
	}

	function removeBlur() {
		try {
			document.documentElement.classList.remove('ageverif-blur-on');
			var el = document.getElementById('ageverif-blur-css');
			if (el && el.parentNode) {
				el.parentNode.removeChild(el);
			}
		} catch (e) { /* defensive */ }
	}

	function redirectTo(url) {
		if (!url) {
			return;
		}
		try {
			window.top.location.replace(url);
		} catch (e) {
			try { window.location.replace(url); } catch (_e) { /* ignore */ }
		}
	}

	function bindEvent(name, handler) {
		window.addEventListener('ageverif:' + name, function (e) {
			try { handler(e); } catch (err) { /* defensive */ }
		});
	}

	var cfg = getCfg();

	if (cfg.blurContent) {
		bindEvent('ready', applyBlur);
		bindEvent('success', removeBlur);
		bindEvent('error', removeBlur);
	}

	if (cfg.underageRedirectUrl) {
		var onFail = function () { redirectTo(cfg.underageRedirectUrl); };
		bindEvent('close', onFail);
		bindEvent('error', onFail);
	}

	if (cfg.manualStart) {
		document.addEventListener('click', function (e) {
			var t = e.target;
			while (t && t !== document) {
				if (t.classList && t.classList.contains('ageverif-trigger')) {
					e.preventDefault();
					if (window.ageverif && typeof window.ageverif.start === 'function') {
						window.ageverif.start();
					}
					return;
				}
				t = t.parentNode;
			}
		});
	}
})();
