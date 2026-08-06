# Pressed Hog – PostHog Analytics for WordPress

A WordPress plugin that installs [PostHog](https://posthog.com) product analytics on your site: tracking snippet, session replay, surveys, user identification, WooCommerce events, feature flags, and cookie consent. Works with PostHog Cloud (US/EU) and self-hosted instances.

## Features

- **One-click install** — enter your project API key and host on the settings page (Settings → Pressed Hog); the plugin injects the official posthog-js snippet on every page.
- **Tracking toggles** — pageview capture, autocapture, session replay, and popover surveys are each switchable.
- **Identify logged-in users** — optionally call `posthog.identify()` with the WordPress user ID, email, and display name, so PostHog persons map to real accounts.
- **Role exclusions** — logged-in users with excluded roles (administrators and editors by default) never get the snippet.
- **Consent modes**
  - *None* — track immediately.
  - *Built-in banner* — a lightweight accept/decline cookie banner; PostHog starts opted-out and only captures after acceptance.
  - *External* — integrate any consent plugin: tracking starts when a configurable cookie matches, or when your plugin calls `window.pressedHog.grantConsent()` / `window.pressedHog.denyConsent()`.
- **WooCommerce events** — `product_added_to_cart`, `checkout_started`, and `order_completed` (with totals and line items, deduplicated per order via order meta).
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
3. Go to **Settings → Pressed Hog** and enter your PostHog project API key (`phc_…`) and host.

## Developer hooks

| Hook | Type | Purpose |
| --- | --- | --- |
| `pressed_hog_is_active` | filter | Whether the snippet is output for the current request. |
| `pressed_hog_init_config` | filter | The `posthog.init()` config array before output. |

## Structure

```
pressed-hog.php                                Bootstrap, defaults, option access
includes/class-pressed-hog-settings.php        Settings page (Settings API)
includes/class-pressed-hog-tracker.php         Snippet output, identify, consent gating
includes/class-pressed-hog-flags.php           Server-side flags, shortcode, helpers
includes/class-pressed-hog-woocommerce.php     WooCommerce event payloads
assets/js/consent.js                           Banner + consent API
assets/js/woocommerce.js                       Client-side WooCommerce captures
uninstall.php                                  Removes options and cached flags
readme.txt                                     WordPress.org plugin readme
```

## License

GPLv2 or later. PostHog is a registered trademark of PostHog, Inc.; this plugin is an independent integration and is not affiliated with or endorsed by PostHog, Inc.
