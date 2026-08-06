# Pressed Hog – PostHog Analytics for WordPress

A WordPress plugin that installs [PostHog](https://posthog.com) product analytics on your site: tracking snippet, session replay, surveys, user identification, WooCommerce events, feature flags, and cookie consent. Works with PostHog Cloud (US/EU) and self-hosted instances.

## Features

- **Setup wizard** — activation redirects to a 4-step wizard: pick your region (US/EU/self-hosted), paste your API key (validated live against your PostHog host from the server), choose tracking and consent options, then send a test event to confirm end-to-end delivery. Re-run it any time from the Plugins screen.
- **Settings page** — everything the wizard configures (plus role exclusions) is also editable at Settings → Pressed Hog; the plugin injects the official posthog-js snippet on every page.
- **Tracking toggles** — pageview capture, autocapture, session replay, and popover surveys are each switchable.
- **Identify logged-in users** — optionally call `posthog.identify()` with the WordPress user ID, email, and display name, so PostHog persons map to real accounts.
- **Role exclusions** — logged-in users with excluded roles (administrators and editors by default) never get the snippet.
- **Consent modes**
  - *None* — track immediately.
  - *Built-in banner* — a lightweight accept/decline cookie banner; PostHog starts opted-out and only captures after acceptance.
  - *External* — integrate any consent plugin: tracking starts when a configurable cookie matches, or when your plugin calls `window.pressedHog.grantConsent()` / `window.pressedHog.denyConsent()`.
- **WooCommerce events** — `product_added_to_cart`, `checkout_started`, and `order_completed` (with totals and line items, deduplicated per order via order meta).
- **Reverse proxy** — optionally serve PostHog through your own domain (`yoursite.com/phog/…` by default, prefix configurable). A rewrite rule relays requests server-side to your PostHog host (static assets to the asset domain, everything else to the ingestion domain), forwarding the visitor's IP via `X-Forwarded-For`. Ad-blockers that block PostHog's domains can't block first-party requests. Requires pretty permalinks.
- **In-dashboard analytics** — a "PostHog" admin page showing pageviews, unique visitors, views-per-visitor (with deltas vs the previous period), a traffic chart, top pages, referrers, and device breakdown for the last 7/30/90 days, queried server-side from PostHog's Query API (HogQL) with 5-minute caching. Includes a WordPress dashboard widget with the 7-day summary, and can embed a PostHog shared dashboard via iframe. Needs a personal API key (read-only Query scope) and project ID.
- **Feature flags, server-side** — gate content with a shortcode or PHP helpers, evaluated against PostHog's `/decide` endpoint with a 60-second cache:

  ```
  [posthog_flag key="new-pricing"]Shown when the flag is on[/posthog_flag]
  [posthog_flag key="experiment" variant="test"]Shown for the "test" variant[/posthog_flag]
  ```

  ```php
  if ( pressed_hog_is_feature_enabled( 'new-pricing' ) ) { /* ... */ }
  $variant = pressed_hog_get_feature_flag( 'experiment' );
  ```

## Installation

1. Download this repository as a zip (or clone it into `wp-content/plugins/pressed-hog`).
2. Activate **Pressed Hog – PostHog Analytics** in wp-admin.
3. The setup wizard opens automatically — connect your PostHog project and you're done.

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `pressed_hog_is_active` | filter | Whether the snippet is output for the current request. |
| `pressed_hog_init_config` | filter | The `posthog.init()` config array before output. |

## Structure

```
pressed-hog.php                                Bootstrap, defaults, option access
includes/class-pressed-hog-settings.php        Settings page (Settings API)
includes/class-pressed-hog-wizard.php          Setup wizard (validation, save, test event)
includes/class-pressed-hog-tracker.php         Snippet output, identify, consent gating
includes/class-pressed-hog-flags.php           Server-side flags, shortcode, helpers
includes/class-pressed-hog-woocommerce.php     WooCommerce event payloads
includes/class-pressed-hog-proxy.php           Reverse proxy (rewrite rule + relay)
includes/class-pressed-hog-analytics.php       Analytics page, widget, Query API client
assets/js/consent.js                           Banner + consent API
assets/js/woocommerce.js                       Client-side WooCommerce captures
uninstall.php                                  Removes options and cached flags
readme.txt                                     WordPress.org plugin readme
```

## License

GPLv2 or later. PostHog is a registered trademark of PostHog, Inc.; this plugin is an independent integration and is not affiliated with or endorsed by PostHog, Inc.
