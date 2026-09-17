=== Pressed Hog – PostHog Analytics ===
Contributors: sea
Tags: posthog, analytics, feature flags, woocommerce, cookie consent
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 7.4
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to PostHog: analytics, session replay, surveys, user identification, WooCommerce events, feature flags, and cookie consent.

== Description ==

Pressed Hog installs [PostHog](https://posthog.com) product analytics on your WordPress site — no code required. Works with PostHog Cloud (US or EU) and self-hosted PostHog instances.

**Features**

* Guided setup wizard on activation: pick your region, validate your API key live against your PostHog host, choose tracking and consent options, and send a test event to confirm everything works end to end.
* Injects the official posthog-js snippet with your project API key and host.
* Toggles for pageview capture, autocapture, session replay, and popover surveys.
* Optionally identify logged-in users, linking events to their WordPress account.
* Exclude roles (administrators, editors, …) from tracking so your own clicks stay out of your data.
* Consent handling with three modes: no gate, a built-in cookie banner, or integration with an external consent plugin via a cookie or JavaScript API.
* WooCommerce events: `product_added_to_cart`, `checkout_started`, and `order_completed` (with order totals and line items, deduplicated per order).
* Server-side feature flags: a `[posthog_flag key="my-flag"]…[/posthog_flag]` shortcode to gate content, plus `pressed_hog_is_feature_enabled()` and `pressed_hog_get_feature_flag()` template helpers.
* Reverse proxy: serve PostHog through your own domain (e.g. `yoursite.com/phog/…`) so ad-blockers that filter PostHog's domains can't block tracking.
* In-dashboard analytics: a PostHog admin page with pageviews, unique visitors, a traffic chart, top pages, referrers, and devices — plus a WordPress dashboard widget and an optional embedded PostHog shared dashboard. Requires a personal API key with read-only Query scope.
* QR codes & trackable links: turn any URL of your own into a campaign-tagged link (UTM parameters plus a unique per-link id so PostHog can attribute each QR individually), generate its QR code right in the browser (nothing is sent to a third-party QR service), keep a saved list of your links, and export the whole list as a downloadable CSV spreadsheet. Download each QR as PNG or SVG.

**Consent integration for developers**

In "external" consent mode, tracking stays off until either the configured cookie equals the configured value, or your consent plugin calls:

`window.pressedHog.grantConsent();` or `window.pressedHog.denyConsent();`

**Hooks**

* `pressed_hog_is_active` — filter whether the snippet is output for the current request.
* `pressed_hog_init_config` — filter the `posthog.init()` config array.

PostHog is a registered trademark of PostHog, Inc. This plugin is an independent integration and is not affiliated with or endorsed by PostHog, Inc.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/pressed-hog`, or install it via the Plugins screen.
2. Activate it — you'll be taken straight to the setup wizard.
3. Follow the wizard: pick your PostHog region, paste your project API key (starts with `phc_`), choose tracking and consent options, and send a test event.

You can re-run the wizard any time from the "Setup wizard" link on the Plugins screen, or manage everything on Settings → Pressed Hog.

== Frequently Asked Questions ==

= Where do I find my project API key? =

In PostHog, open Settings → Project. The key starts with `phc_` and is safe to expose publicly.

= Does this work with self-hosted PostHog? =

Yes — choose "Self-hosted / custom" as the host and enter your instance URL.

= Does the plugin send any data to PostHog on its own? =

Only what you enable. The tracking snippet runs in visitors' browsers; the only server-side request is feature flag evaluation (when you use the shortcode or helpers), sent to your configured PostHog host.

= Does the reverse proxy work on any host? =

It needs pretty permalinks enabled (Settings → Permalinks). Every tracked event then passes through your server as a lightweight relay to PostHog — fine for most sites, but consider a CDN-level proxy for very high-traffic sites.

= How do the QR codes track scans? =

Each trackable link appends standard UTM parameters (source, medium, campaign) plus a unique `phg_qr` id to your destination URL. When someone scans the code and lands on your site, posthog-js captures those parameters on the `$pageview` event, so you can break scans down by campaign — or by individual QR code — in PostHog. The QR image itself is generated in the visitor's/your browser; the URL is never sent to an external QR service.

= Where are my links stored, and can I export them? =

They're saved in your WordPress database (the `pressed_hog_links` option) and listed on the QR Codes page. Use "Download sheet (CSV)" to export every link — label, destination, full tracked URL, UTM values, tracking id, and created date — as a spreadsheet you can open in Excel or Google Sheets.

= Is my personal API key safe? =

It is stored in its own non-autoloaded WordPress option. Public tracking requests load a separate settings option and do not retrieve the credential. It is only ever used server-side (never printed on the front end). Create it with the read-only Query scope so it can't modify anything.

== Changelog ==

= 0.3.0 =
* New "QR Codes" page (under the PostHog menu): create trackable links from your own URLs with UTM tags and a unique tracking id, generate QR codes, save your links, and download the list as a CSV spreadsheet. QR codes are generated entirely in the browser and can be downloaded as PNG or SVG.

= 0.2.1 =
* Security hardening: restrict the reverse proxy to known PostHog paths and methods with a request-body size cap; validate the wizard's key-check host (HTTPS only, via wp_safe_remote_post) to prevent internal-network probing; stop exposing the personal API key in the setup wizard's page HTML and keep it out of the autoloaded options cache; reject numeric anonymous feature-flag identifiers.

= 0.2.0 =
* Reverse proxy for ad-blocker-resistant tracking through your own domain.
* In-dashboard analytics page, dashboard widget, and optional embedded shared dashboard.

= 0.1.0 =
* Initial release.
