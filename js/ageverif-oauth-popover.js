/**
 * AgeVerif OAuth popover – vanilla JS, no dependencies.
 *
 * Behaviour
 *   • On DOMContentLoaded, look for `<dialog id="ageverif-oauth-popover">`
 *     and the matching inline config (`window.ageverifOauthPopover`).
 *   • Open it via `dialog.showModal()` so focus is trapped inside
 *     automatically (modern browsers: Chrome 37+, Safari 15.4+, FF 98+).
 *   • Trapping is reinforced manually below for older browsers.
 *   • Esc closes the dialog and writes a sessionStorage flag so the
 *     popover stays dismissed for subsequent navigations within the
 *     same tab session.
 *   • Clicking the "Verify" button is a normal `<a>` navigation to the
 *     authorize URL set by PHP – cross-origin prevents running the
 *     OAuth flow inside an iframe.
 *
 * Loaded with `defer`, so it can assume a complete DOM on readystatechange.
 */
(function(){
	'use strict';

	var cfg = window.ageverifOauthPopover || { armed: false, autoOpen: true };

	function ready(fn){
		if (document.readyState !== 'loading') { fn(); }
		else { document.addEventListener('DOMContentLoaded', fn); }
	}

	ready(function(){
		var dlg = document.getElementById('ageverif-oauth-popover');
		if (!dlg) { return; }
		if (!cfg.armed) { return; }
		if (!cfg.autoOpen) { return; }

		var lastFocus = null;
		var pristineFocusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex="-1"])';

		// Manual focus trap (in case showModal() doesn't do it natively,
		// e.g. very old FF/Edge).
		function trapFocus(e){
			if (e.key !== 'Tab' || !dlg.open) { return; }
			var focusables = dlg.querySelectorAll(pristineFocusableSelector);
			if (!focusables.length) { return; }
			var first = focusables[0];
			var last  = focusables[focusables.length - 1];
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}

		function open(){
			lastFocus = document.activeElement;
			// showModal: adds backdrop, traps focus natively on modern browsers.
			if (typeof dlg.showModal === 'function') {
				dlg.showModal();
			} else {
				dlg.setAttribute('open', '');
				dlg.classList.add('ageverif-oauth-popover--fallback-open');
				var first = dlg.querySelector(pristineFocusableSelector);
				if (first) { first.focus(); }
			}
			document.addEventListener('keydown', trapFocus);
		}

		function persistDismiss(){
			try {
				if (window.sessionStorage) {
					window.sessionStorage.setItem(cfg.storageKey, '1');
				}
			} catch (e) { /* private mode: ignore */ }
		}

		// The native <dialog> closes itself on Esc — when it does, mark
		// as dismissed so a quick re-render (e.g. back-forward cache)
		// doesn't reopen the modal mid-task.
		dlg.addEventListener('close', function(){
			document.removeEventListener('keydown', trapFocus);
			persistDismiss();
			if (lastFocus && typeof lastFocus.focus === 'function') {
				lastFocus.focus();
			}
		});

		// Click on the backdrop (the <dialog> element itself, not the panel)
		// closes too — matches the modal conventions people expect.
		dlg.addEventListener('click', function(e){
			if (e.target === dlg) {
				dlg.close('backdrop');
			}
		});

		open();
	});
})();
