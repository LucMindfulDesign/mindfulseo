=== MindfulSEO ===
Contributors: mindfuldesign
Tags: seo, ai, openai, claude, rank-math, yoast, batch, keywords
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 2.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered SEO optimization and blog content generation with brand-aware guidelines. Works with Rank Math and Yoast SEO.

== Description ==

MindfulSEO helps you optimize titles, meta descriptions, and keywords at scale using OpenAI or Claude, while respecting your language guidelines. It includes an SEO audit view, batch processing, keyword strategy tooling, and optional DataForSEO metrics.

== Installation ==

1. Upload the `mindfulseo` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** screen
3. Open **MindfulSEO** in the admin menu and complete setup (API keys, optional DataForSEO)

See `README.md` and `instructions.md` for detailed documentation.

== Changelog ==

= 2.4.0 - 2026-03-29 =
* Add OpenRouter as an optional AI backend (many models via one key; presets, custom model id, wizard + settings, connection test, optional fallback to direct APIs)
* Add Import / Export for posts (ZIP + manifest; re-import with SEO and content options)
* Setup wizard: preserve imports as authoritative; preflight import before analyze; improved progress UX; import-aware keyword/guideline caps
* Keywords & guidelines: extend-mode balancing and stronger prompts; batch optimizer focus-keyword and strategy fallbacks; analyzer robustness for wizard JSON

= 2.3.0 - 2026-03-26 =
* Add setup wizard, dashboard, content hub, and dedicated keywords admin pages
* Add content cluster, gap analysis, internal linking, and cache manager components
* Update batch optimizer, SEO audit, admin UI, and AI/data pipeline code
* Align plugin version header and `MINDFULSEO_VERSION`; add CHANGELOG.md
* Remove sample test-data files from the package

= 1.0.0 =
* Initial public foundation: AI optimization, audit, batch optimizer, keywords, guidelines, DataForSEO, Rank Math / Yoast support

== Upgrade Notice ==

= 2.4.0 =
Adds OpenRouter and a posts Import/Export screen. If you use OpenRouter, add your key under MindfulSEO settings; ZipArchive must be enabled on the server for export/import.

= 2.3.0 =
Major admin and workflow updates. After upgrading, open MindfulSEO in the admin and confirm API keys and menus load as expected.
