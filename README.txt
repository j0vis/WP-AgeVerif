=== AgeVerif – Age Verification ===
Contributors: ageverif
Tags: age verification, age gate, age restriction, ageverif, content protection, age check, adult content
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 1.1.2
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

= 1.1.2 =
* Expanded bot-bypass coverage — Baidu, Yandex, Apple, and all major AI / social-preview crawlers (GPTBot, ClaudeBot, PerplexityBot, Facebook, Twitter, LinkedIn, Discord, Telegram) are now enabled by default.
* One-time backfill migration — existing installations inherit the expanded bot-bypass list on upgrade without needing to re-save settings.

= 1.0.0 =
* Initial release.
