# Changelog

All notable changes to **MindfulSEO** are documented here.  
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [2.4.0] - 2026-03-29

### Added

- **OpenRouter backend** — optional AI routing via OpenRouter (`includes/class-openrouter-provider.php`, AI connector, settings, and setup wizard). One API key can target many models: curated presets (e.g. Qwen, MiniMax), optional **custom model id**, HTTP Referer for OpenRouter attribution, AJAX connection test, and optional fallback to direct OpenAI / Claude when OpenRouter fails.
- **Posts Import / Export** — ZIP export with `manifest.csv` and `html/{id}.html`; re-import on another site by **slug + post type** with modes (full merge, create-only, update-existing) and toggles for HTML body and SEO meta via the active SEO plugin adapter (`includes/class-post-import-export.php`, **MindfulSEO → Import / Export**).

### Changed

- **Setup wizard** — Imports from CSV/Markdown stay **authoritative**; AI suggestions are additive caps, not replacements. **Preflight import** runs selected files through the same import endpoints as manual Import before analysis so keywords/guidelines are saved reliably. **Progress UX** uses time-based phases instead of fake/random percentages. Keyword/guideline source labels use sanitized filenames where applicable; extend/regenerate flows respect import preservation counts.
- **Keywords (wizard & manager)** — Extend-style suggestions favor **new primary topics** with server-side balancing after AI so results are not mostly long-tail stacks on the same primary. CSV importer and keyword manager behavior aligned with wizard preservation and caps.
- **Guidelines (wizard & engine)** — Extend/regenerate prompts and caps favor substantive **avoid / preferred / SEO** rules over walls of trivial **capitalize** rows; tighter pattern-capitalize limits when imports already exist.
- **Content analyzer** — Refined prompts and token budgets for wizard keyword/guideline JSON; more resilient extraction/normalization when the model returns loose or truncated structure; clearer handling when zero rows parse (wizard vs. generic errors).
- **Batch optimizer** — Detects **weak generic** target keywords, can ask the model for **focus_keyword**, surfaces strategy context, and falls back to a matching strategy primary when appropriate.
- **AI connector, providers, logger** — Routing for OpenRouter vs. direct vendors; retries/fallbacks; usage and cost logging extended for OpenRouter (approximate where needed).
- **Admin & AJAX** — Settings UI for OpenRouter and primary AI selection; wizard step-1 provider wiring; related API tester and handler endpoints.

## [2.3.0] - 2026-03-26

### Added

- **Setup wizard** — guided onboarding (`admin/class-setup-wizard.php`, styles/scripts).
- **Dashboard** — admin landing/overview page (`admin/pages/class-dashboard-page.php`).
- **Content Hub** — hub UI and interactions (`admin/pages/class-content-hub-page.php`, `assets/js/content-hub.js`).
- **Keywords** — dedicated keywords admin page (`admin/pages/class-keywords-page.php`).
- **Content cluster engine** — clustering support (`includes/class-content-cluster-engine.php`).
- **Gap analyzer** — content gap analysis (`includes/class-gap-analyzer.php`).
- **Internal linker** — internal linking suggestions (`includes/class-internal-linker.php`).
- **Cache manager** — centralized caching helpers (`includes/class-cache-manager.php`).
- **Main admin controller** — `admin/class-admin.php` wiring for the new admin experience.
- **WordPress.org-style `readme.txt`** — for distribution and update notes.
- **This changelog** — `CHANGELOG.md`.

### Changed

- **Core plugin bootstrap** (`mindfulseo.php`) — loads new components and keeps version in sync with releases.
- **Batch Optimizer, SEO Audit, admin UI** — updates to pages, CSS, and JS for the expanded workflow.
- **AI & data pipeline** — refinements across AI connector, Claude/OpenAI providers, CSV import, DataForSEO connector, guidelines engine, keyword manager, logger, optimizer, content analyzer, AJAX handlers, API tester.
- **README** — version and changelog section aligned with 2.3.0.

### Removed

- **Sample test data** — `test-data/sample-guidelines.md` and `test-data/sample-keywords.csv` removed from the repository (use real imports or your own fixtures).

---

## [1.0.0] - (initial public baseline)

- Initial plugin foundation: AI optimization, SEO audit, batch optimizer, keywords, guidelines, DataForSEO, Rank Math / Yoast compatibility.

*Note: Development used mixed 2.2.x header/constant values before **2.3.0**; this changelog starts the authoritative public release line for the org repo.*
