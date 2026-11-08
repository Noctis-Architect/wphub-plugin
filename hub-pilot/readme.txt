=== HubPilot ===
Contributors: wphub
Tags: analytics, security, monitoring, privacy, cookieless
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connects WordPress to the WP-Hub console for cloud analytics, speed, security, and uptime monitoring, with optional local analytics.

== Description ==

HubPilot connects WordPress to the WP-Hub console for cloud analytics, speed tests, security checks, and uptime monitoring, with optional local analytics and basic security checks. In local mode it records pageviews, referrers and channels, devices, browsers, engagement time, scroll depth, and outbound, download, form, and cart events without loading a third-party front-end script.

The plugin works in two modes:

* **Local (default):** all analytics data stays in your WordPress database. No analytics events are sent anywhere.
* **Cloud (optional):** events are queued locally and sent to the WP-Hub console over HTTPS. This mode requires a site token from the console.

= Features =

* Cookie-free tracking with no personal profiles and no cross-site identifiers.
* Bot filtering using the User-Agent plus browser signals (JavaScript execution, WebGL renderer class, plugins, animation frames, interaction, visibility, and timing).
* Four report modes: verified (default), all, no-JS, and suspect.
* Visit quality card with a breakdown by traffic group.
* Tiered data retention: detailed data, daily data, and daily totals.
* Local dashboard with pageviews, visitors, sessions, bounce rate, average engaged time, top pages, sources, devices, and events.
* Optional cloud sync with gzip, HMAC-SHA256 signatures, a local queue, retries, and exponential backoff.
* Console overview with speed-test, security-test, and uptime status when Cloud mode is connected.
* Basic local security check card for directory listing, REST users, wp-admin exposure, security plugins, file editing, and debug display.
* Respects DNT and GPC; logged-in users are not tracked by default.
* No third-party scripts, fonts, or images are loaded on the front end.

= How it works =

On a normal front-end request, the plugin records a lightweight server-side pageview in a protected spool file. A small deferred tracker confirms the visit from the browser and records engagement and events. WP-Cron processes the spool every five minutes into the local tables, or into the cloud queue when Cloud mode is active. If WP-Cron is disabled, you can run the cron event from the server or use the manual buttons on the plugin pages.

= Local or cloud? =

Local mode is self-contained and needs no account. Data is stored in your own database and kept according to the retention settings.

Cloud mode is optional. It requires an account at the WP-Hub console, a verified domain, and a site token. The console adds speed tests, security checks, uptime monitoring, and cross-site dashboards.

= Source credit =

After activation, the plugin adds a small HTML comment at the top of the page's head section with the HubPilot name. It is not a visible banner and has no effect on the page layout or speed. You can turn it off in the plugin's **Settings → Appearance and notifications** screen.

= Privacy =

Local mode:

* Stores page paths and titles, referrer host, channel, device type, browser, operating system, country code (when provided by the server or Cloudflare), hashed visitor and session identifiers, timestamps, engagement time, scroll depth, and event counts.
* Does not store the raw IP address or the raw User-Agent string.
* The visitor identifier is a truncated HMAC-SHA256 of the IP address and User-Agent with the site's salt and the current date. It changes every day. The session identifier uses a 30-minute window.
* Logged-in users are skipped by default. DNT and GPC are respected by default. Known bots are skipped.
* Retention defaults: detailed data 30 days, daily data 180 days, daily totals 24 months. All three are configurable.

Cloud mode:

* No analytics rows are written to the local analytics tables. Events wait in a protected spool file and are sent every five minutes.
* The batch includes the site URL and host, WordPress version, plugin version, timezone, and the event fields listed above. It can also include the visitor IP address when "Send visitor IP to console" is enabled; the console uses it for datacenter, Tor, VPN, and country classification. See the service privacy policy for details.
* No analytics events are sent until you select Cloud mode and save a site token.

Uninstalling the plugin removes its settings and temporary queue. Analytics tables are kept unless you enable **Delete analytics tables on uninstall**. See the External services section for the cloud service details.

= فارسی =

HubPilot یک افزونهٔ سبک و بدون کوکی آمار بازدید است. به‌صورت پیش‌فرض همه‌چیز روی همان سایت ذخیره می‌شود و هیچ رویداد آماری به بیرون ارسال نمی‌شود. حالت ابری اختیاری است و برای آن به کلید اتصال از کنسول WP-Hub نیاز دارید. ربات‌ها با User-Agent و سیگنال‌های مرورگر فیلتر می‌شوند، DNT/GPC رعایت می‌شود و بازدید کاربران واردشده به‌صورت پیش‌فرض ثبت نمی‌شود. رابط مدیریت به انگلیسی است و ترجمهٔ فارسی بر اساس زبان کاربر بارگذاری می‌شود.

== External services ==

This plugin connects to the WP-Hub console only when Cloud mode is enabled and a site token is saved.

* Service: WP-Hub console
* URL: https://console.wp-hub.org
* Data sent: site URL and host, WordPress version, plugin version, timezone, page paths and titles, referrer host, channel, device, browser, operating system, country code, hashed visitor and session identifiers, visit grade, timestamps, engagement time, scroll depth, event counts, and optionally the visitor IP address.
* When: every five minutes via WP-Cron, or when you press "Send queue now".
* Purpose: analytics reports, traffic quality, speed tests, security checks, and uptime monitoring in the console.
* Terms of Use: https://wp-hub.org/terms
* Privacy Policy: https://wp-hub.org/privacy
* Contact: https://wp-hub.org/contact

The plugin works without an account in Local mode; Cloud sync is optional.

== Installation ==

1. Upload the plugin ZIP through **Plugins → Add New → Upload Plugin**, or install it from the WordPress plugin directory.
2. Activate the plugin.
3. Open the plugin's **Settings** screen.
4. Choose **Local** to keep all data on this site, or **Cloud** to send data to the WP-Hub console.
5. For Cloud mode, create an account at https://console.wp-hub.org, verify the domain, copy the site token, and paste it into the settings.
6. If you use a full-page cache, clear it once after activation so the tracker is included in cached pages.
7. If WP-Cron is disabled, run the `wphs_aggregate` and `wphs_cloud_sync` events from a real cron job or use the manual buttons.

== Frequently Asked Questions ==

= Does this plugin use cookies? =

No. The tracker does not set cookies, use local storage, or create a cross-site identifier.

= Do I need a WP-Hub account? =

No for Local mode. Cloud mode requires an account and a site token.

= Does it slow down my site? =

The front-end tracker is a small deferred file served from your own site. Local mode writes one lightweight line per pageview to a spool file and processes it in batches with WP-Cron. Cloud mode sends batches every five minutes and never blocks page rendering.

= What is stored about visitors? =

See the Privacy section. In Local mode the plugin stores aggregate dimensions and daily hashed identifiers, not raw IP addresses or raw User-Agent strings.

= Does it track logged-in users? =

Not by default. You can enable it in the settings.

= Does it respect Do Not Track? =

Yes. DNT and GPC are respected by default. You can change this in the settings.

= How do I delete the data? =

In Cloud mode, use **Purge local data** to remove leftover local summaries. On uninstall, enable **Delete analytics tables on uninstall** to drop the analytics tables. If it is disabled, the tables remain in the database.

= Does it work with caching plugins? =

Yes. Clear the page cache once after activation. The server-side pageview is recorded on PHP requests; the browser tracker confirms the visit and sends engagement data.

= Does it work on multisite? =

The plugin uses the current site's database prefix. Multisite network activation is not officially tested.

= How do I remove the source credit? =

Go to the plugin's **Settings → Appearance and notifications** screen and disable the source credit option.

== Screenshots ==

1. HubPilot dashboard overview with pageviews, visitors, sessions, average engaged time, bounce rate, and traffic quality.
2. Top pages, sources, devices, events, and the basic security check.
3. Storage mode, three data retention tiers, and console connection settings.
4. Bot detection, privacy, source credit, and processing status.

The screenshots use demo data from a test site.

== Credits ==

The admin interface uses the Vazirmatn font, licensed under the SIL Open Font License 1.1. The license text is included at `admin/assets/fonts/OFL.txt`.

== Changelog ==

= 0.4.0 =
* Initial public release.
* Cookie-free local analytics with a dashboard for pageviews, visitors, sessions, bounce rate, engaged time, pages, sources, devices, and events.
* User-Agent and browser-signal bot filtering.
* Verified, all, no-JS, and suspect report modes.
* Tiered data retention.
* Optional cloud sync to the WP-Hub console with HMAC-SHA256, gzip, queue, and backoff.
* Optional source credit comment.
* Basic security check card for directory listing, REST users, wp-admin exposure, security plugins, file editing, and debug display.
* English admin interface with a Persian translation that follows the site or user locale.

== Upgrade Notice ==

= 0.4.0 =
Initial public release.
