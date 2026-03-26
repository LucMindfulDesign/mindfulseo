# Changelog

All notable changes to **MindfulSEO** are documented here.  
This project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
