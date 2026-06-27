=== AgeVerif – Age Verification ===
Contributors: ageverif
Tags: age verification, age gate, age restriction, ageverif, content protection, age check, adult content
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 1.3.1
Requires PHP: 7.4
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integrate the ageverif.com age verification service into your WordPress site with no coding required. Protect age-restricted content with a privacy-focused, compliant verification gate.

== Description ==

AgeVerif is a free, fast, and privacy-focused age verification service that helps websites comply with regulations protecting minors from accessing age-restricted content.

This official WordPress plugin lets you integrate AgeVerif in minutes — no coding required.

= Features =

* **Simple Setup** — Enter your API key, choose which content to protect, and you're done.
* **Content Type Selection** — Protect posts, pages, custom post types, and WooCommerce products.
* **Per-post override** — Tick the Age Gate meta box in any post to force or skip the gate, regardless of global settings.
* **URL Exclusion** — Exclude specific URLs from the age verification gate (supports `*` glob paths).
* **Test Mode** — Preview the gate as an admin before enabling it for all visitors.
* **Async Loading** — The checker script loads asynchronously so it won't slow down your site.
* **Page Builder Compatible** — Works with Elementor, WPBakery, Divi, and other major builders.
* **Cache & Security Plugin Friendly** — No conflicts with caching or security plugins.
* **SEO Safe** — Every major search engine (Google, Bing, Yahoo, DuckDuckGo, Baidu, Yandex, Apple…), AI crawler (GPTBot, ClaudeBot, PerplexityBot…), and social-preview bot (Facebook, Twitter, LinkedIn, Discord, Telegram) is **enabled by default** — they bypass the gate server-side and always see the full HTML. Custom User-Agent regex supported for any client not covered.
* **Logged-in role bypass** — Skip the gate entirely for specific roles (administrators, editors, …) so your team is never gated.
* **Display controls** — Pick gate language (Auto / EN / DE / ES / FR / IT / PT), verification steps (selfie, CC, ID, OTP), display mode (popup / tab / redirect), closable gate.
* **Manual start mode** + `[ageverif]` shortcode — Trigger the gate from a button instead of on every page load.
* **Content blur** — Apply a CSS blur to the page until the visitor is verified (great for adult sites).
* **Underage redirect** — Send visitors who fail / close the gate to any URL.
* **Localized into 5 languages** — ships with full `.po` translation files for French (`fr_FR`), Spanish (`es_ES`), Italian (`it_IT`), German (`de_DE`), and Brazilian Portuguese (`pt_BR`). The full settings page — including Quick Start, Glossary, Common Issues, Pre-launch Checklist, Status widget, and tooltips — renders in the admin’s site language. The modal itself continues to render in the visitor’s browser language when their locale is supported.
* **Built-in newbie-friendly guide** — Settings → AgeVerif includes a live status widget that flags misconfiguration in real time, a Quick Start walkthrough (with optional screencast video embed for visual learners), a Glossary, Common Issues & Fixes, and a Pre-launch Checklist. Every non-obvious field — and every jargon term in the docs — has a hover tooltip. No external documentation required.
* **OAuth2 support** — Optional Authorization Code flow that exchanges a one-time code for an HMAC-signed verification cookie. Use the `[ageverif_oauth]` shortcode or let the plugin auto-gate protected pages when OAuth is enabled.
* **OAuth Health Check** — One-click test posts your Client ID/Secret to api.ageverif.com/v1/oauth2/token with a deliberately invalid code; 400 invalid_grant means the credentials are accepted, 401 invalid_client tells the admin exactly which credential is wrong.
* **SEO-safe OAuth** — OAuth still respects admin / role / bot bypass rules, so search engines and AI crawlers continue to see the full HTML.
* **OAuth callback registration** — When OAuth is enabled, register the **REST callback URL** shown in Settings → AgeVerif → OAuth2 with your site’s entry in the Webmasters Portal. The REST form (`/wp-json/ageverif/v1/oauth/callback`) is exempted from most full-page caches (Nginx Helper, Cloudflare APO) by default, so the OAuth round-trip is cleaner than the legacy `?ageverif_oauth=callback` query string (still honored as a fallback for existing Webmasters Portal registrations).

======== For new admins ========
If you’re new to age verification, the Settings → AgeVerif page itself walks you through the entire setup: click “Add Website” in the Webmasters Platform, copy your Public Live Key, paste it under Connection, tick at least one Protected Content Type, preview with Test Mode, then go live. The page also has a Glossary, Common Issues, and a Pre-launch Checklist — no API experience required.
* **Health check** — Built-in admin tool that pings AgeVerif with your key to confirm reachability.
* **GDPR Compliant** — Privacy-first verification with double-anonymity options.

= How It Works =

1. Register at the AgeVerif Webmasters Platform and add your website.
2. Copy your Public Live Key and paste it into the plugin settings.
3. Select which content types to protect.
4. The age verification gate automatically appears for visitors in regulated regions.

== Installation ==

= From the WordPress Admin =

1. Go to Plugins → Add New.
2. Search for "AgeVerif".
3. Click Install Now, then Activate.

= Manual Upload =

1. Download the plugin ZIP file.
2. Go to Plugins → Add New → Upload Plugin.
3. Choose the ZIP file and click Install Now.
4. Activate the plugin.

= After Activation =

1. Go to Settings → AgeVerif in your WordPress admin menu.
2. Enter your Public Live Key from the AgeVerif Webmasters Platform.
3. Select which content types (posts, pages, products, etc.) should display the age gate.
4. Click Save Changes.

== Frequently Asked Questions ==

= Do I need an AgeVerif account? =

Yes. Register for free at the AgeVerif Webmasters Platform to get your API key.

= Does this affect my site's SEO? =

No. The AgeVerif checker script is designed to allow search engine bots and AI crawlers to access your content without the age verification gate.

= Will this work with my caching plugin? =

Yes. The checker script loads asynchronously from AgeVerif's CDN and does not interfere with server-side caching.

= Is it compatible with WooCommerce? =

Yes. Select "product" in the protected content types to enable age verification on WooCommerce product pages.

= Can I customize the gate's appearance? =

Yes. The gate's logo and colors can be customized in the AgeVerif Webmasters Platform.

= What if I only want to verify visitors from certain countries? =

Configure your target regions in the AgeVerif Webmasters Platform. The checker will only activate for visitors in those regions.

== Screenshots ==

1. The AgeVerif settings page in the WordPress admin.
2. The age verification gate as seen by visitors.

== Changelog ==

= 1.3.1 =
* **Inline jargon tooltip rendering fix** — the Quick Start paragraph's hover-trigger words ("OAuth2", "Client Secret") were previously wrapped by a span styled as an 18×18 ? icon, which clipped the visible text. Added a new `.ageverif-tip--text` variant that flows with the surrounding paragraph text (underlined, word-width) while keeping the same hover popup, so terminology in the Quick Start now reads correctly instead of being squashed into a tiny box.
* **Dropped "jargon" framing** — the Quick Start paragraph no longer labels those terms as jargon; it now describes them as "hover explanations", matching what the popup actually does.
* **OAuth field tooltips completed** — every OAuth field now has its own hover tooltip, fulfilling the Quick Start promise of "Every field on this page has its own tooltip with deeper details". Added tips to `oauth_enabled`, `oauth_button_label`, `oauth_language`, and `oauth_challenges` (previously only Client ID, Client Secret, Flow, and Button Color had tooltips).
* **Status widget Fix-link wiring** — clicking the "Fix →" links in the live status widget now smooth-scrolls the corresponding input/checkbox into the viewport center *and* drops keyboard focus on it, so screen-reader and keyboard-only admins can immediately tab to the offending field. Targets: Public Live Key, OAuth2 enabled, Protected Content Types grid, Test Mode toggle.
* **Copy-to-clipboard a11y** — the OAuth Callback URL copy button now exposes a polite `aria-live` live region so screen-reader users hear "Copied!" / "Copy failed" announced, and gets a distinct red failure state when the modern Clipboard API rejects *and* the legacy `execCommand` fallback also fails (instead of silently no-op'ing). Success label tightened to "Copied!".
* **OAuth field id consistency** — all 8 `oauth_*` fields now carry a matching `id="ageverif-..."` attribute (oauth-enabled, oauth-client-id, oauth-client-secret, oauth-flow, oauth-button-label, oauth-button-color, oauth-language, oauth-challenges) under the existing dashed-naming convention. Status-widget anchors updated in lockstep so Fix-link clicks still resolve.
* **Reduced-motion respect** — the new focus flash on the Status widget Fix-link target honors `prefers-reduced-motion`, swapping the keyframe animation for a static 3px ring, so the scroll/focus behavior works without motion.

= 1.3.0 =
* **In-page popover** for the OAuth auto-gate — protected pages now render normally with a native `<dialog>` modal overlay on top of the content, instead of doing a full-page redirect to AgeVerif and back. Esc closes the modal and remembers the dismissal for the rest of the tab session. Better UX (visitors stay on the page, see the content blurred under the modal) and friendlier to full-page caches (the page response is cacheable; only the popover JS differs per visitor).
* **REST callback** — the OAuth callback is now `GET /wp-json/ageverif/v1/oauth/callback` (public REST route, exempt from most page caches). The legacy `?ageverif_oauth=callback` query-var form is still honored as a deprecated fallback so existing Webmasters Portal registrations don’t break on upgrade.
* **Refactored auth pipeline** — both handlers (REST + query-var) call a shared `process_oauth_callback()` method so CSRF, state-freshness, token exchange, and cookie issuance are guaranteed to agree across paths.
* **Optional Quick Start screencast** — paste a YouTube/Vimeo/Loom URL or a self-hosted MP4/WebM URL under *Settings → Onboarding*, and a sandboxed lazy-loaded embed renders at the top of the Quick Start panel for visual learners. Leave the field empty to fall back to the step-by-step text only.
* New `assets/frontend.css` — popover modal styles (with `prefers-reduced-motion` opt-out and a 480px mobile breakpoint) shipped separately from `admin.css`.
* New `js/ageverif-oauth-popover.js` — vanilla JS, focus-trap fallback for browsers without `<dialog>` `showModal()`, backdrop-click-to-close, sessionStorage dismissal persistence.

= 1.2.0 =
* OAuth2 support (**[ageverif.com/oauth2](https://docs.ageverif.com/oauth2.html)**) — Authorization Code flow with stateless CSRF defense (short-lived cookie + base64url JSON state, zero DB writes) and an HMAC-signed verification cookie. When OAuth is enabled the in-page checker script is bypassed entirely.
* New `[ageverif_oauth]` shortcode — drop a "Verify with AgeVerif" button on any page.
* OAuth auto-gate — protected pages render a branded full-page OAuth gate when no verification cookie is present (admin / role / bot bypass still wins).
* New **OAuth2** section in Settings → AgeVerif — choose flow (`/checker` or `/login`), button label, button color (per AgeVerif Brand Guidelines), language, and challenges.

= 1.1.2 =
* Expanded bot-bypass coverage — Baidu, Yandex, Apple, and all major AI / social-preview crawlers (GPTBot, ClaudeBot, PerplexityBot, Facebook, Twitter, LinkedIn, Discord, Telegram) are now enabled by default.
* One-time backfill migration — existing installations inherit the expanded bot-bypass list on upgrade without needing to re-save settings.

= 1.0.0 =
* Initial release.
