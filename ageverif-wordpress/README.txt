=== AgeVerif – Age Verification ===
Contributors: ageverif
Tags: age verification, age gate, age restriction, ageverif, content protection, age check, adult content
Requires at least: 5.6
Tested up to: 6.7
Stable tag: 1.0.0
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
* **URL Exclusion** — Exclude specific URLs from the age verification gate.
* **Test Mode** — Preview the gate as an admin before enabling it for all visitors.
* **Async Loading** — The checker script loads asynchronously so it won't slow down your site.
* **Custom CSS** — Adjust the gate's appearance to match your theme.
* **Page Builder Compatible** — Works with Elementor, WPBakery, Divi, and other major builders.
* **Cache & Security Plugin Friendly** — No conflicts with caching or security plugins.
* **SEO Safe** — Search engine bots can still crawl and index your content.
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

Yes. You can add custom CSS in the plugin settings. The gate's logo and colors can also be customized in the AgeVerif Webmasters Platform.

= What if I only want to verify visitors from certain countries? =

Configure your target regions in the AgeVerif Webmasters Platform. The checker will only activate for visitors in those regions.

== Screenshots ==

1. The AgeVerif settings page in the WordPress admin.
2. The age verification gate as seen by visitors.

== Changelog ==

= 1.0.0 =
* Initial release.
