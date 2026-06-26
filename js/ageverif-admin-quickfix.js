/**
 * AgeVerif admin quick-fix handler
 *
 * The Status widget at the top of Settings → AgeVerif surfaces live
 * misconfiguration in the form of "Action needed / Heads up / Note" callouts,
 * each ending in an inline "Fix →" anchor link. Browsers handle scroll-to-fragment
 * automatically but do NOT move keyboard focus to the target — tab-key users then
 * have to scroll back up and hunt for the right input. This shim closes that gap.
 *
 * Behaviour:
 *   • Captures clicks on .ageverif-status-link (event delegation).
 *   • Resolves the href="#ageverif-xxx" target by id, falling back to the
 *     first focusable descendant if the target is a wrapper (<fieldset>/<div>).
 *   • Smooth-scrolls the target into the viewport center.
 *   • Calls .focus({ preventScroll: true }) so keyboard users get the cursor
 *     ready to type or arrow-key through radio/checkbox groups.
 *   • Briefly adds .ageverif-just-focused so the eye picks up where the
 *     browser moved focus.
 *   • Updates history so back/forward + page refresh preserves which field was
 *     jumped to (history.pushState with a try/catch fallback chain).
 *
 * Vanilla JS, no deps, runs in IIFE so each settings page can safely source
 * this file alongside the rest of WP's admin scripts.
 */
(function () {
	'use strict';

	function resolveFocusable(target) {
		if (!target) {
			return null;
		}
		// If the id is on a wrapper element (fieldset/div/tr/label), drill down
		// to the first focusable descendant so screen-reader announcements
		// land on the input, not the wrapper.
		var isFocusable =
			typeof target.matches === 'function' &&
			target.matches('input:not([type="hidden"]), select, textarea, button, a[href]');
		if (isFocusable) {
			return target;
		}
		var inner =
			target.querySelector &&
			target.querySelector('input:not([type="hidden"]), select, textarea, button');
		return inner || target;
	}

	function flash(target) {
		if (!target || !target.classList) {
			return;
		}
		// Restart the animation on rapid re-clicks.
		target.classList.remove('ageverif-just-focused');
		/* Force a reflow so the keyframes restart. */
		void target.offsetWidth;
		target.classList.add('ageverif-just-focused');
		window.setTimeout(function () {
			target.classList.remove('ageverif-just-focused');
		}, 1800);
	}

	function focusTarget(target) {
		var focusable = resolveFocusable(target);
		try {
			target.scrollIntoView({ behavior: 'smooth', block: 'center' });
		} catch (_e) {
			/* Older browser — synchronous scroll, no animation. */
			target.scrollIntoView();
		}
		if (focusable && typeof focusable.focus === 'function') {
			try {
				focusable.focus({ preventScroll: true });
			} catch (_e) {
				/* preventScroll not supported in this UA, fall back. */
				focusable.focus();
			}
		}
		flash(target);
	}

	function updateHash(id) {
		try {
			if (window.history && typeof window.history.pushState === 'function') {
				/* Drop the previous hash first so identical-id clicks re-fire. */
				window.history.pushState(null, '', window.location.pathname + window.location.search + '#' + id);
			} else if (window.history && typeof window.history.replaceState === 'function') {
				window.history.replaceState(null, '', '#' + id);
			} else {
				window.location.hash = id;
			}
		} catch (_e) {
			/* ignore — a stale anchor shouldn't break the click handler */
		}
	}

	document.addEventListener('click', function (e) {
		var node = e.target;
		var anchor = null;
		while (node && node !== document) {
			if (node.classList && node.classList.contains('ageverif-status-link')) {
				anchor = node;
				break;
			}
			node = node.parentNode;
		}
		if (!anchor) {
			return;
		}
		var href = anchor.getAttribute('href') || '';
		if (href.charAt(0) !== '#') {
			return;
		}
		var id = href.slice(1);
		if (!id) {
			return;
		}
		var target = document.getElementById(id);
		if (!target) {
			return;
		}
		e.preventDefault();
		focusTarget(target);
		updateHash(id);
	});
})();
