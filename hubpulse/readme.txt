=== Hubpulse – Analytics, AI Writer & Console Connector ===
Contributors: mrnoctis
Tags: analytics, ai writer, seo, privacy, cookieless
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Cookie-free analytics and an AI article writer, with optional WP-Hub console reports for speed, security and uptime.

== Description ==

Hubpulse is the official WordPress plugin of WP-Hub (https://wp-hub.org). It gives you cookie-free visitor analytics and an AI article writer inside WordPress. Everything works without an account; connecting the optional WP-Hub console adds speed, security and uptime reports and a managed AI writer that needs no API key.

= Analytics =

* Cookie-free tracking with no personal profiles and no cross-site identifiers.
* Records pageviews, referrers and channels, devices, browsers, engagement time, scroll depth, and outbound, download, form and cart events — without loading a third-party front-end script.
* Bot filtering using the User-Agent plus browser signals (JavaScript execution, WebGL renderer class, plugins, animation frames, interaction, visibility and timing).
* Four report modes: browser detected (default), all, no browser, and bot, with a visit-quality breakdown.
* Tiered data retention: per-visit details, daily reports and daily totals.
* Respects DNT and GPC; logged-in users are not tracked by default.
* Two storage modes: **on this site** (default, nothing leaves your server) or **in the WP-Hub console** (optional, keeps your database lean).

= AI Writer =

Write complete, SEO-ready articles and save them as drafts for review. Choose how the text is generated:

* **WP-Hub console (recommended):** connect a free site key and write from inside WordPress. The console studies your published posts, adds internal links and images from your media library, and fills in the SEO title, meta description, focus keyword and FAQ schema. Monthly limits come from your console plan. Nothing is published without your permission: by default the console can only create drafts.
* **Your own AI provider (advanced):** use your own API key with OpenAI, Anthropic, Google Gemini, OpenRouter, DeepSeek, Groq, Mistral, AvalAI, GapGPT, Metis AI, a self-hosted model (Ollama, LM Studio) or any OpenAI-compatible API. On WordPress 7.0+ you can also use the AI provider connected in **Settings → Connectors**. You set the system prompt, site topic, business description, audience, brand voice, language, house rules, length, internal links and FAQ, and test the connection before writing. Requests go directly from your site to the provider you chose; WP-Hub is not involved.

Works with Yoast SEO, Rank Math and All in One SEO. Without an SEO plugin, Hubpulse prints the meta description and FAQ schema for its own articles.

= Free and paid =

Every feature of this plugin is free and has no built-in limits: analytics, the local security check, and the AI Writer with your own provider. The plugin never locks features or shows paid-only screens.

The WP-Hub console is a separate, optional web service with a free plan. During its current public test period, console article writing is free for every account (15 articles per month). Monthly limits are set and enforced by the console, not by this plugin, and are shown on the AI Writer screen. Competitor analysis, content health reports and topic suggestions are features of the console website itself; they are not part of this plugin.

= Console reports (optional) =

With a site key the dashboard also shows the latest mobile and desktop speed test, security test and uptime status from the WP-Hub console. A basic local security check (directory listing, REST users, wp-admin exposure, security plugins, file editing, debug display) works without any account.

= How it works =

On a normal front-end request, the plugin records a lightweight server-side pageview in a protected spool file. A small deferred tracker confirms the visit from the browser and records engagement and events. WP-Cron processes the spool every five minutes into the local tables, or into the cloud queue when Cloud mode is active. If WP-Cron is disabled, you can run the cron event from the server or use the manual buttons on the plugin pages.

= Local or cloud? =

On-site storage is self-contained and needs no account. Data is stored in your own database and kept according to the retention settings.

Console storage is optional. It requires an account at the WP-Hub console, a verified domain, and a site key.

= Source credit =

Off by default. If you enable it under **Settings → Advanced**, the plugin adds an HTML comment and a generator meta tag reading "Traffic Analytics by Hubpulse" to the page head. It is not visible to visitors.

= Privacy =

Local mode:

* Stores page paths and titles, referrer host, channel, device type, browser, operating system, country code (when provided by the server or Cloudflare), hashed visitor and session identifiers, timestamps, engagement time, scroll depth, and event counts.
* Does not store the raw IP address or the raw User-Agent string.
* The visitor identifier is a truncated HMAC-SHA256 of the IP address and User-Agent with the site's salt and the current date. It changes every day. The session identifier uses a 30-minute window.
* Logged-in users are skipped by default. DNT and GPC are respected by default. Known bots are skipped.
* Retention defaults: detailed data 30 days, daily data 180 days, daily totals 24 months. All three are configurable.

Cloud mode:

* No analytics rows are written to the local analytics tables. Events wait in a protected spool file and are sent every five minutes.
* The batch includes the site URL and host, WordPress version, plugin version, timezone, and the event fields listed above. It includes the visitor IP address; the console uses it for datacenter, Tor, VPN, and country classification. Cloud mode is opt-in: no analytics events are sent until you select Cloud mode and save a site token. See the service privacy policy for details.
* No analytics events are sent until you select Cloud mode and save a site token.

Uninstalling the plugin removes its settings and temporary queue. Analytics tables are kept unless you enable **Delete analytics tables on uninstall**. See the External services section for the cloud service details.

= فارسی =

Hubpulse یک افزونهٔ سبک و بدون کوکی آمار بازدید است. به‌صورت پیش‌فرض همه‌چیز روی همان سایت ذخیره می‌شود و هیچ رویداد آماری به بیرون ارسال نمی‌شود. حالت ابری اختیاری است و برای آن به کلید اتصال از کنسول WP-Hub نیاز دارید. ربات‌ها با User-Agent و سیگنال‌های مرورگر فیلتر می‌شوند، DNT/GPC رعایت می‌شود و بازدید کاربران واردشده به‌صورت پیش‌فرض ثبت نمی‌شود. رابط مدیریت به انگلیسی است و ترجمهٔ فارسی همراه افزونه است و وقتی زبان پیشخوان فارسی باشد خودکار نمایش داده می‌شود.

== External services ==

Hubpulse works fully without any external service. It contacts a remote service only after you turn on one of the features below.

**WP-Hub console** — https://console.wp-hub.org

* Used only after you save a site key in **Settings** or the **AI Writer**.
* Console reports: the dashboard requests the latest speed, security and uptime results for your site (sends the site key).
* Console storage mode: every five minutes, or when you press "Send queue now", queued analytics events are sent: site URL and host, WordPress version, plugin version, timezone, page paths and titles, referrer host, channel, device, browser, operating system, country code, hashed visitor and session identifiers, visit grade, timestamps, engagement time, scroll depth, event counts, and the visitor IP address (used for country and datacenter classification, not stored).
* AI Writer (console mode): when you press "Enable article writing", Hubpulse generates a private content key on your site and registers it with the console over HTTPS; the console never sends keys back. With it, the console can call this site's signed REST routes (`/wp-json/wphub-content/v1/`) to read published posts, pages, products (WooCommerce), categories, tags and metadata of images that belong to published content, and to create drafts (or published posts, only if you allow it). Article topics and options you enter are sent to the console when you press "Write with the console".
* Outage fallback: if the console does not answer at all for 6 hours while Console storage is active, Hubpulse switches back to on-site storage automatically, counts the queued visits locally and shows a notice; you choose when to return to the console.
* Terms of Use: https://wp-hub.org/terms
* Privacy Policy: https://wp-hub.org/privacy

**Your own AI provider** (AI Writer, manual mode)

* Used only when you configure a provider and press "Test connection" or "Write article".
* Sent directly from your site to the provider URL you entered: your API key, the system prompt, the site details you filled in (name, domain, topic, business description, audience, voice, region, house rules), the article brief, and the titles and URLs of up to 25 recent published posts and pages for internal links.
* Preset providers and their terms and privacy policies:
  * OpenAI — https://openai.com/policies/terms-of-use — https://openai.com/policies/privacy-policy
  * Anthropic — https://www.anthropic.com/legal/commercial-terms — https://www.anthropic.com/legal/privacy
  * Google Gemini — https://ai.google.dev/gemini-api/terms — https://policies.google.com/privacy
  * OpenRouter — https://openrouter.ai/terms — https://openrouter.ai/privacy
  * DeepSeek — https://www.deepseek.com (terms and privacy policy linked in the site footer)
  * Groq — https://groq.com (terms and privacy policy linked in the site footer)
  * Mistral AI — https://mistral.ai/terms
  * AvalAI — https://avalai.ir (terms and privacy policy on the provider's website)
  * GapGPT — https://gapgpt.app (terms and privacy policy on the provider's website)
  * Metis AI — https://metisai.ir (terms and privacy policy on the provider's website)
* With "WordPress AI", the request is sent by WordPress itself to the provider you connected in **Settings → Connectors**, under that provider's terms.
* With a self-hosted or custom URL, data goes only to the server you entered.

== Installation ==

1. Install the plugin from **Plugins → Add New**, or upload the ZIP file.
2. Activate it. Analytics start immediately and are stored on your site.
3. Optional: open **Hubpulse → Settings**, paste a free site key from https://console.wp-hub.org to add speed, security and uptime reports.
4. Optional: open **Hubpulse → AI Writer** and choose the WP-Hub console or your own AI provider.
5. If you use a full-page cache, clear it once after activation so the tracker is included in cached pages.
6. If WP-Cron is disabled, run the `wphs_aggregate` and `wphs_cloud_sync` events from a real cron job or use the manual buttons under **Settings → Diagnostics**.

== Frequently Asked Questions ==

= Does this plugin use cookies? =

No. The tracker does not set cookies, use local storage, or create a cross-site identifier.

= Do I need a WP-Hub account? =

No. Analytics, the security check and the AI Writer with your own provider work without an account. Console reports, console storage and the console AI writer need a free site key.

= Is the AI Writer free? =

With your own provider, Hubpulse itself is free; you pay your provider directly, or nothing for a self-hosted model. In console mode, the number of articles per month depends on your WP-Hub plan and is shown on the AI Writer screen.

= What happens if the console cannot be reached? =

Analytics are never lost. If the console gives no answer for 6 hours (for example during a regional internet outage), Hubpulse switches to on-site storage, counts the queued visits locally and shows a notice with a button to return to the console once it is reachable. Console article writing is unavailable until the connection returns.

= Can the console publish to my site without asking? =

No. By default it can only create drafts or pending posts. Direct publishing must be turned on under **AI Writer → Publishing permissions**, and you can disconnect at any time.

= Does it slow down my site? =

The front-end tracker is a small deferred file served from your own site. Local mode writes one lightweight line per pageview to a spool file and processes it in batches with WP-Cron. Cloud mode sends batches every five minutes and never blocks page rendering.

= What is stored about visitors? =

See the Privacy section. In Local mode the plugin stores aggregate dimensions and daily hashed identifiers, not raw IP addresses or raw User-Agent strings.

= Does it track logged-in users? =

Not by default. You can enable it in the settings.

= Does it respect Do Not Track? =

Yes. DNT and GPC are respected by default. You can change this in the settings.

= How do I delete the data? =

In console storage mode, use **Settings → Diagnostics → Purge old local data** to remove leftover local summaries. To drop the analytics tables on uninstall, enable **Settings → Advanced → Delete all Hubpulse data when the plugin is deleted**. Keys and AI provider credentials are always removed on uninstall.

= Does it work with caching plugins? =

Yes. Clear the page cache once after activation. The server-side pageview is recorded on PHP requests; the browser tracker confirms the visit and sends engagement data.

= Does it work on multisite? =

The plugin uses the current site's database prefix. Multisite network activation is not officially tested.

= Is there a source credit on my site? =

Not unless you enable it under **Settings → Advanced**.

== Screenshots ==

1. Hubpulse dashboard overview with pageviews, visitors, sessions, average engaged time, bounce rate, and traffic quality.
2. Top pages, sources, devices, events, and the basic security check.
3. Settings: console connection, storage mode, privacy and data retention.
4. AI Writer: choose the WP-Hub console or your own AI provider.

The screenshots use demo data from a test site.

== Credits ==

The admin interface uses the Vazirmatn font, licensed under the SIL Open Font License 1.1. The license text is included at `admin/assets/fonts/OFL.txt`.

== Changelog ==

= 0.5.0 =
* Renamed to Hubpulse (slug: hubpulse).
* New AI Writer with two modes: the WP-Hub console (no API key, console quota) or your own AI provider (OpenAI-compatible presets, self-hosted models, or the WordPress 7 AI Client), with connection testing, model list, system prompt and detailed site profile.
* The console can now read published content and send articles to the site through signed REST routes, replacing the separate WP-Hub Content Connector plugin. Drafts only unless you allow publishing.
* SEO fields for Yoast SEO, Rank Math and All in One SEO, plus FAQ schema.
* Simpler settings: the site key is always available, privacy and retention side by side, advanced options and diagnostics folded away. The saved key is no longer shown in the form.
* The source credit is now off by default.
* The plugin no longer hides WordPress and other plugins' notices on its pages.
* Admin scripts and the menu icon style moved to enqueued files; no inline scripts or styles.
* The temporary queue now lives in the uploads directory (`uploads/hubpulse/`), protected from direct access.
* Translations: the bundled Persian translation is loaded by WordPress automatically from the plugin's languages folder (no custom loader); translations from translate.wordpress.org take priority.
* The content signing key is generated on your site and only registered with the console; it is never sent back.
* Automatic fallback to on-site analytics after 6 hours without an answer from the console, with an admin notice to switch back.
* Rejected console requests are logged at most once a minute; media of unpublished posts is not shared with the console.
* The stored AI provider key is only ever sent to the saved provider address.
* WooCommerce products can be used as internal link targets.

= 0.4.2 =
* Source credit now reads "Traffic Analytics by Hubpulse" in both the HTML comment and the generator meta tag.
* Simpler visitor labels: "Browser detected", "No browser", and "Bot" replace the technical Verified, No-JS, and Suspect labels.

= 0.4.1 =
* Send tracking beacons through admin-ajax.php so collection works on sites where the CDN blocks POST requests to the REST API.
* Keep the tracker in cacheable HTML even when a cache entry is created by a bot or a request that is not counted, so real visitors on cached pages are still tracked.
* Exclude the tracker from LiteSpeed Cache JS optimization and delay, and pass the tracker configuration on the script tag.
* Add a generator meta tag so the source credit remains visible when HTML minification removes comments.
* Cloud mode now always sends the visitor IP for country and datacenter classification; the separate "Send visitor IP" option was removed.

= 0.4.0 =
* Initial public release.
* Cookie-free local analytics with a dashboard for pageviews, visitors, sessions, bounce rate, engaged time, pages, sources, devices, and events.
* User-Agent and browser-signal bot filtering.
* Browser-detected, all, no-browser, and bot report modes.
* Tiered data retention.
* Optional cloud sync to the WP-Hub console with HMAC-SHA256, gzip, queue, and backoff.
* Optional source credit comment.
* Basic security check card for directory listing, REST users, wp-admin exposure, security plugins, file editing, and debug display.
* English admin interface with a Persian translation that follows the site or user locale.

== Upgrade Notice ==

= 0.5.0 =
Adds the AI Writer (WP-Hub console or your own AI provider), simplifies settings, and turns the source credit off by default.

= 0.4.2 =
Updates the source credit text to "Traffic Analytics by Hubpulse" and makes visitor labels simpler.

= 0.4.1 =
Improves tracker delivery on cached and CDN-protected sites, keeps the source credit in optimized HTML, and removes the separate visitor IP option. Cloud mode now includes the visitor IP.

= 0.4.0 =
Initial public release.
