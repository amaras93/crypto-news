# CryptoNews

CryptoNews is a multilingual financial newspaper with a protected editorial CMS, moderated community, source discovery, a disabled AI drafting pipeline, reader engagement and administrator-managed monetization infrastructure. Phase 11 audits and hardens the existing application without deploying or enabling integrations. See the [readiness report](docs/DEPLOYMENT_READINESS.md), [staging QA checklist](docs/STAGING_QA_CHECKLIST.md), [production runbook](docs/PRODUCTION_RUNBOOK.md), [advertising operator guide](#phase-10--advertising-and-sponsorship), [engagement guide](#phase-09--reader-engagement), [AI guide](#phase-08-ai-assisted-editorial-pipeline), [discovery guide](#phase-07-news-discovery) and [editorial CMS guide](#phase-05-editorial-cms). The approved newspaper theme, eight public locales, Armenian archive and DEMO markets are preserved. Earlier sections retain historical phase behavior and verification results.

Phase 11 verification: **829 tests / 17,358 assertions**, zero failures/errors/skips, passing Pint, Composer validation, production asset build and Blade compilation. Production configuration/route caching passed in isolated fixture paths. All original rows in 42 business tables and the local `.env`/dependency lockfiles are unchanged. **Deployment remains BLOCKED** by MySQL 8 integration/restore, real browser QA and production infrastructure verification. The browser bridge failed before rendering a page; no screenshots or Core Web Vitals were verified. An unpatched picomatch advisory affects build/dev dependencies; production npm dependencies and locked Composer dependencies report no advisories. See the report for evidence, severity and release gates.

The subsequent [MariaDB 10.11 compatibility correction and staging recovery guide](docs/PRODUCTION_RUNBOOK.md#mariadb-1011-migration-compatibility-and-empty-staging-recovery) replaces one 66-character generated index name with `csr_candidate_source_index` and fixes indexed-column rollback cleanup. All 13 migrations were exercised on a disposable MariaDB 10.11.6 server, including standard Artisan migration/rollback/reapplication. With the optional real MariaDB regression enabled, **833 tests / 17,410 assertions** pass with no skips. Do not blindly retry a partially applied migration: use the guide's separate, initially empty staging database. Real MySQL 8 execution and the other production release gates remain outstanding.

## Installed stack

| Component | Verified version |
| --- | --- |
| Laravel | 13.42.0 |
| Filament | 5.11.2 |
| Livewire | 4.4.8 (includes Alpine.js) |
| PHP | 8.4.22 on this Windows machine |
| Composer | 2.11.2 through the project wrapper |
| Node.js / npm | 22.22.2 / 10.9.7 |
| Tailwind CSS | 4.3.5 |
| Vite | 8.3.8 |
| Local database | SQLite, database/database.sqlite |

Laravel 13 supports PHP 8.3+, but this project's resolved dependencies require **PHP 8.4+**. Use PHP 8.4 or 8.5, Composer 2.9+, and Node 22.12+ (Node 22 LTS recommended). Install from both lockfiles for reproducible dependencies.

The global Composer here is 2.2.7 and emits PHP 8.4 deprecation warnings. The project wrapper downloads a current Composer PHAR, verifies its official SHA-256 checksum, and caches it in ignored `.tools/`. It leaves the global installation untouched. A current global Composer may also be used normally.

## Windows and VS Code setup

Open this directory itself in VS Code; Laravel is at the workspace root. Use a PowerShell integrated terminal. Required PHP extensions include ctype, curl, dom, fileinfo, filter, hash, intl, mbstring, openssl, PDO, pdo_sqlite, session, tokenizer, xml, and zip. Enable pdo_mysql when using MySQL. These were available during implementation.

For a fresh checkout:

```powershell
php -v
node --version
npm --version
.\scripts\composer.ps1 install
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
php artisan app:prepare-local
php artisan migrate
php artisan storage:link
npm ci
npm run build
php artisan test
```

If PowerShell blocks the wrapper, invoke it for that process only:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\composer.ps1 install
```

`app:prepare-local` only runs in APP_ENV=local. It generates an application key only if missing and creates a SQLite file only if missing. It preserves existing keys and databases. Do not casually regenerate an application's key; existing encrypted data and sessions depend on it.

Setup has already been performed in this workspace, including the storage link. If another Windows machine denies symlink creation, enable Windows Developer Mode or use an elevated terminal for `storage:link`. Public files use storage/app/public; private files use storage/app/private.

## Start locally

Start Laravel, the queue worker, Vite and the scheduler together:

```powershell
.\scripts\composer.ps1 run dev
```

The combined script uses the installed cross-platform concurrently package. It does not depend on the optional Laravel Multiplex binary, which is unavailable on this Windows setup. Alternatively, start each process separately as follows.

Run each long-running command in its own VS Code terminal from the project root.

Terminal 1 — Laravel:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
```

Terminal 2 — Vite:

```powershell
npm run dev
```

Terminal 3 — database queue worker:

```powershell
php artisan queue:work --queue=default,ai,engagement --tries=1 --timeout=85
```

Terminal 4 — scheduler:

```powershell
php artisan schedule:work
```

Open <http://127.0.0.1:8000>. Use one hostname consistently for browser sessions. Stop processes with Ctrl+C. To use built assets, stop Vite and run `npm run build`; Laravel can serve them without an active Node process.

Fresh `.env.example` files set `DB_QUEUE_RETRY_AFTER=180` and `REDIS_QUEUE_RETRY_AFTER=180`. The existing local `.env` is preserved and may retain the framework's 90-second default; production must use the longer window and Linux PCNTL timeout enforcement described in the runbook. SQLite now uses IMMEDIATE transactions with a five-second busy timeout after a reproduced two-worker budget lock failure. Run `php artisan queue:restart` after application changes when ready to restart your workers. The scheduler prunes old batch bookkeeping after seven days; inspect it with `php artisan schedule:list`. Current business-job controls are documented in the Phase 09 and AI guides below.

For a future server, run `php artisan schedule:run` every minute through cron or Windows Task Scheduler, and supervise a worker separately. Set a Windows scheduled task's **Start in** directory to the project root and use the absolute path to the intended PHP executable. No OS-level scheduled task was installed in this phase.

The read-only command `php artisan app:check-production-readiness --json --no-interaction` reports configuration requirements without secret values or external provider requests. Exit 1 on this Windows/local/SQLite setup is expected; it is not a deployment certificate. An active local Vite server also intentionally fails the production hot-file check. The runbook provides the Linux production worker, proxy/HTTPS, backup, monitoring and rollback requirements. Existing credentials over bcrypt's 72-byte UTF-8 limit require a secure password reset before public launch; no stored password was changed. New credentials reject ambiguous overlong/NUL input and all eight languages include the validation error.

## Routes and administrator access

| URL | Behavior |
| --- | --- |
| / | Redirect to /en |
| /en, /ru, /fr, /de, /es, /it, /ja, /zh | Localized homepages |
| /{locale}/news | Paginated published news for one of the eight public locales |
| /hy, /hy/news | Permanent redirects to /en and /en/news |
| /hy/news/{slug} | Permanent redirect to the actual English translation, or English listing; unknown/unpublished stories return 404 |
| /{locale}/news/{slug} | Full article reader with translation-aware URLs and SEO |
| /sitemap.xml | Sitemap index |
| /sitemap-pages.xml | Public home/news page sitemap |
| /sitemap-articles-{start}.xml | Streamed article sitemap shard |
| /robots.txt | Crawler instructions and absolute sitemap location |
| /admin | Protected Filament dashboard |
| /admin/login | Filament login |
| /admin/articles | Scoped editorial article table and workflow |
| /admin/categories, /admin/tags | Administrator taxonomy management |
| /admin/users | Administrator editorial account management |
| /admin/editorial-settings | Administrator defaults for new drafts |
| /editorial/preview/{article}/{locale} | Authorized, authenticated, non-indexable saved-article preview |
| /up | Laravel health endpoint |

Create the first administrator from a trusted terminal:

```powershell
php artisan app:create-admin
```

The command asks for name, email, a hidden password, and confirmation. Use 12–64 characters containing upper and lowercase letters, a number, and a symbol. The user model hashes the password. Duplicate email addresses are rejected; existing accounts are never promoted or overwritten. Passwords are not accepted as CLI arguments. Noninteractive execution fails safely.

No default administrator or password exists. As of Phase 06, public registration creates readers with no CMS permissions; readers cannot log into the panel. Administrators and explicitly authorized editors can access the CMS with different permissions. Use this application's command instead of `make:filament-user`, which would create a reader without an editorial role. The admin UI uses English; article/category content supports all eight public languages. Boundaries are documented in [the architecture guide](docs/architecture.md).

## Database and services

The local .env is ignored by Git. The example contains no key or credentials. Defaults:

```dotenv
APP_NAME=CryptoNews
APP_ENV=local
APP_URL=http://localhost:8000
APP_LOCALE=en
APP_FALLBACK_LOCALE=en
DB_CONNECTION=sqlite
SESSION_DRIVER=database
SESSION_ENCRYPT=true
CACHE_STORE=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
FILESYSTEM_DISK=local
```

Leave DB_DATABASE unset for SQLite to use the portable default path. Migrations include users, sessions, cache, jobs, failed jobs, job batches, articles and translations, categories and translations, tags, and article/tag links. Dates use UTC. Local mail appears in storage/logs/laravel.log; SMTP is unnecessary.

MySQL was unavailable locally. To switch to an independently provisioned MySQL 8+ database, back up existing data, create a UTF-8 database and dedicated user, and update .env:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cryptonews
DB_USERNAME=cryptonews
DB_PASSWORD= # Inject the database password securely; do not commit it.
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
```

Fill DB_PASSWORD locally, then run `php artisan config:clear` and `php artisan migrate`. This creates schema on the selected database; it does not transfer SQLite records. MySQL runtime behavior remains to be verified on a real server. Do not run destructive database resets such as migrate:fresh against user data.

## Verification and architecture

```powershell
php artisan test
npm run build
.\scripts\composer.ps1 validate --strict
.\scripts\composer.ps1 check-platform-reqs
php artisan migrate:status
php artisan route:list
php artisan schedule:list
```

Tests use SQLite in memory and do not touch local data. Coverage includes locale routes, Armenian UTF-8, translated listings, pagination, draft/future publication exclusion, output escaping, admin authentication and authorization, administrator creation, translation uniqueness, relationships, and foreign keys.

Phase 01 verification: 37 tests passed with 156 assertions; production assets built; Composer validation and platform checks passed; all migrations ran; public pages, admin login and assets returned HTTP 200. The combined Windows startup command was exercised successfully. Automated visual browser verification was unavailable because the browser tool could not create a tab. MySQL verification awaits a real server.

Read [docs/architecture.md](docs/architecture.md) for schema ownership, editorial fields, locales, module boundaries, storage, and services. Implementation follows the official [Laravel 13 documentation](https://laravel.com/docs/13.x/installation) and [Filament 5 documentation](https://filamentphp.com/docs/5.x/introduction/installation). Laravel Boost is a development dependency installed by the skeleton's agent setup; it is not a product AI integration.

Git is initialized on branch main. Dependencies, builds, generated Filament assets, SQLite files, logs, .env files, and the Composer PHAR are ignored. No remote or deployment is configured. A future deployment needs its own environment, application key, HTTPS/session settings, and database credentials. Keep APP_DEBUG=true restricted to local development.

## Phase 02 frontend

The public site uses a navy and violet editorial design with responsive cards, original local SVG covers, a sticky header, mobile navigation, a demo market ticker, editorial selections, analysis, category discovery, and a newsletter preview. Subscription collection is not enabled. Markets in the primary navigation remains a clearly marked future module; gold and silver have a working combined news filter.

Search and filtering work through GET requests: `/en/news?q=Bitcoin&category=1`. Search is limited to 120 characters and matches the requested language's title, excerpt, or body. SQL values are bound and `%`/`_` are treated literally. Pagination retains active filters. Language changes retain the search/category selection and reset pagination; article language changes use actual translated slugs and omit unavailable translations.

The Phase 02 reader provided working story links and was expanded in Phase 03 below. Live markets, AI agents, comments, advertisements, portfolios, new admin modules, and subscriptions remain deferred.

### Optional demo content

```powershell
php artisan db:seed --class=DemoNewsSeeder
```

This explicitly invoked seeder is allowed only in local/testing environments. As of Phase 04.1, it adds 15 fictional stories with hand-authored English and Russian fixtures and nine categories. Existing Armenian translations are preserved, including stories identified only by an old Armenian fixture slug. It does not manufacture articles in the six new languages. Stories are visibly tagged as demo content; the reader explains their fictional nature. Market figures and illustrative sparklines in DEMO mode come from `config/markets.php`, with no live API calls. The six additional crypto instruments have no invented demo prices.

The seeder never runs from the default DatabaseSeeder and never overwrites existing rows, including edited demo stories. Repeated runs skip existing demo slugs, including those belonging to soft-deleted articles. It creates a non-admin demo author with a random password; no default administrator or usable shared credential is introduced. It was explicitly run in this local workspace to populate the preview.

Original SVG cover sources are in `public/images/editorial/`; no copyrighted news photography or remote image hotlinks are used. Public JavaScript is dependency-free and does not load another Alpine instance into Filament. Design tokens and responsive rules are in `resources/css/app.css`; reusable views are under `resources/views/components/`.

Phase 02 verification: **60 tests passed, 245 assertions**. This includes all Phase 01 tests, combined search/filtering, literal wildcard handling, pagination, locale navigation, reader escaping, publication protection, seeder repeatability and production refusal, plus constant query counts with lazy loading prohibited. Live HTTP route checks passed. Browser automation could not create a tab, so visual desktop/mobile verification and screenshots were unavailable.

Use the startup commands above to preview `/en`, `/ru`, or `/hy`. Phase 02 stops here; further modules await Phase 03 instructions.

### Phase 02 file map

| Area | Modified or created files |
| --- | --- |
| Routing and controllers | `routes/web.php`; `app/Http/Controllers/HomeController.php`, `NewsController.php`, `ArticleController.php` |
| Query and navigation helpers | `app/Support/NewsFeed.php`, `FrontendLinks.php`; `app/Http/Requests/NewsIndexRequest.php` |
| Model presentation helpers | `app/Models/Article.php`, `Category.php` |
| Page templates | `resources/views/layouts/app.blade.php`, `home.blade.php`, `news/index.blade.php`, `news/show.blade.php` |
| Reusable components | `resources/views/components/`: `ui-icon`, `article-card`, `article-meta`, `section-heading`, `language-switcher`, `market-overview`, `editor-picks`, `newsletter`, `empty-state`, `pagination` |
| Design and interactions | `resources/css/app.css`, `resources/js/app.js` |
| Local artwork | `public/images/brand.svg`; `public/images/editorial/`: `bitcoin.svg`, `ethereum.svg`, `gold.svg`, `silver.svg`, `markets.svg`, `network.svg` |
| Demo data | `config/markets.php`; `database/seeders/DemoNewsSeeder.php`, `data/demo-news.php` |
| UI translations | `lang/en/ui.php`, `lang/ru/ui.php`, `lang/hy/ui.php` |
| Tests | New `FrontendExperienceTest.php`, `DemoNewsSeederTest.php`; updated `LocaleRoutingTest.php` to initialize the isolated test database for the now data-driven homepage |
| Documentation | `README.md`, `docs/architecture.md` |

## Phase 03 article reader and SEO

Article pages now include localized breadcrumbs, author information, publication/edit dates, estimated reading time, a responsive hero, safe formatted content, heading-based contents, editorial sidebar selections, related market topics, existing demo markets, tags, sharing, and up to four related articles. Reading progress, copying and back-to-top behavior use a small dependency-free JavaScript module. Contents navigation is inline before the body on mobile and in the sidebar on desktop. Reduced-motion preferences are respected.

Related stories must be published, due, not deleted, and translated into the selected language. Ranking is shared category first, then shared substantive tags, then publication date and ID. The current article is excluded. Operational demo/selection tags do not influence relevance. The sidebar uses actual editorial selections because no popularity tracking exists.

### Content and summary storage

The additive migration `2026_11_13_103758_add_summary_fields_to_article_translations_table.php` adds only two nullable columns: JSON `summary` and string `summary_source`. It has already run locally. Existing bodies, translations, slugs and demo fixtures were preserved.

`summary` accepts a JSON list of 3–5 nonblank strings in the current translation; `summary_source` is `editorial` or `ai`. Invalid/missing lists are hidden. AI-sourced points are explicitly labeled, and all points are escaped. There is no generation endpoint or AI API. Existing demo fixtures do not supply summaries, so the component correctly stays hidden for them.

Bodies are interpreted as plain text or editorial HTML at render time; stored content is never rewritten. Plain text keeps paragraph breaks. HTML uses the already-installed Symfony HTML sanitizer with an explicit tag/attribute allowlist. Scripts, styles, embeds, event handlers and unsafe URL schemes are removed. Body images are restricted to same-host or local relative resources; remote images are not hotlinked automatically. Original heading IDs are replaced with safe, unique anchors while matching internal links are remapped. Tables are keyboard-scrollable, and useful paragraphs, headings, quotes, attribution, lists, links, tables, images and captions are preserved. Reading time estimates sanitized Unicode text at 200 words/minute. Standalone markup without editorial HTML is displayed safely as literal text, preserving earlier plaintext behavior.

The sanitizer is now explicitly declared as a direct Composer requirement so application code does not rely on an undeclared transitive dependency. Its installed version is unchanged (8.1.11); no new package or frontend dependency was installed.

### SEO URL policy

Set `APP_URL` to the real public HTTPS origin before deployment. Canonical, social, sharing, structured-data and sitemap URLs derive from that configured application URL and actual locale/slug; request Host headers and tracking query parameters do not determine article canonical URLs. Legacy `canonical_url` values remain stored but are not blindly advertised as unverified external or nonexistent destinations.

Article metadata includes a title, description (with excerpt/body fallback), canonical, Open Graph, Twitter/X, available-translation hreflang and English x-default when available. Missing translations are never advertised. Unicode sitemap/canonical paths are URL-encoded. HTML attributes are escaped; JSON-LD uses JSON context escaping so a headline cannot close its script element. `NewsArticle` and `BreadcrumbList` use actual dates, authors and visible breadcrumb destinations. Demo stories use `Article` with a fictional-demo designation. Missing hero images get a local visual fallback, and nonexistent image paths are omitted from SEO metadata rather than advertised as real images. No image credits or author profile pages are invented.

The sitemap index references a static page list plus 5,000-ID translation shards. Shards stream XML with 250-row keyset batches and eager-load published parents. Only supported, existing, public translations are emitted. ID ranges are disjoint; unique translation slugs prevent duplicate article URLs. Actual publication/edit timestamps supply lastmod. The index checks the 50,000-entry/50 MB protocol limits and refuses an over-capacity index rather than emitting invalid XML; extremely large installations would require additional top-level indexes. No generated sitemap files or scheduled rebuild jobs are needed.

### Verification and preview

Phase 03 verification: **96 tests passed, 433 assertions**, including every earlier test. Tests cover content safety and long articles, summaries, missing images, related ranking and query counts, publication/translation protection, escaped metadata/JSON-LD, alternate URLs, trusted canonical origins, valid streamed XML, shard boundaries and robots discovery. The production build, route checks, Blade compilation and Composer validation passed. Live checks returned HTTP 200 for all three readers and sitemap endpoints. The local article sitemap contains 45 existing demo translations. Browser automation still could not create a tab, so visual and browser-interaction verification remain unavailable. MySQL runtime verification still awaits a server.

```powershell
php artisan migrate
.\scripts\composer.ps1 run dev
```

Existing working examples:

- <http://localhost:8000/en/news/demo-bitcoin-long-view-en>
- <http://localhost:8000/ru/news/demo-bitcoin-long-view-ru>
- <http://localhost:8000/hy/news/demo-bitcoin-long-view-hy>
- <http://localhost:8000/sitemap.xml>

### Phase 03 file map

| Area | Created or modified files |
| --- | --- |
| Reader and sitemap endpoints | `app/Http/Controllers/ArticleController.php`, `SitemapController.php`; `routes/web.php` |
| Services | New `app/Support/ArticleContent.php`, `ArticleMedia.php`, `ArticleSeo.php`, `PublicUrl.php`, `Sitemap.php`; updated `NewsFeed.php` |
| Summary persistence | `app/Models/ArticleTranslation.php`; the additive summary migration above |
| Page and components | `resources/views/news/show.blade.php`, `layouts/app.blade.php`; new `article-summary`, `article-toc`, `seo-meta` components; updated `article-card`, `ui-icon` |
| Styling/interactions | New `resources/css/article.css`, `resources/js/article.js`; their existing app entry points import them |
| Translations | New `lang/en/article.php`, `lang/ru/article.php`, `lang/hy/article.php` |
| Tests | New `ArticlePageTest.php`, `ArticleSeoTest.php`, `SitemapTest.php` |
| Crawler discovery | Dynamic `/robots.txt` replaces the previous static boilerplate file |
| Dependencies/docs | `composer.json`, `composer.lock`, `README.md`, `docs/architecture.md` |

Phase 03 stops here. Phase 04 has not started.

## Phase 03.1 article visual refinement

The article reader now uses a wide cinematic hero with a roughly 45/55 text/image split on desktop, stacked composition on smaller screens, and navy, emerald, gold and violet accents. Real article titles, excerpts, authors and dates remain the source of every editorial label. Existing article images take precedence; missing images use the existing local artwork for the category's canonical English topic, including on Russian and Armenian pages.

Below the hero, the reading panel has refined headings, quotes, tables, captions and spacing. Stored summaries and heading-based contents remain conditional. The sidebar reuses editorial picks, related stories and explicitly labelled DEMO markets in compact form. Related cards retain 16:9 images, visible excerpts and accessible story links. Reading progress ends at the reader footer rather than extending through a taller sidebar.

No database migrations, dependencies, article URLs, SEO contracts, admin screens or translation strings changed in this phase. The supplied reference is preserved at `design-references/single-news-reference.png`; its sample headlines, people and financial figures were not inserted into article data.

| Area | Created or modified files |
| --- | --- |
| Hero | New `resources/views/components/article-hero.blade.php`; `app/Support/ArticleMedia.php`; `app/Http/Controllers/ArticleController.php` |
| Reader and reused components | `resources/views/news/show.blade.php`; `resources/views/components/article-summary.blade.php`, `article-toc.blade.php`, `editor-picks.blade.php`, `ui-icon.blade.php` |
| Appearance and interactions | `resources/css/article.css`; `resources/js/article.js` |
| Verification | `tests/Feature/ArticlePageTest.php`, including localized category fallbacks and actual-image precedence |
| Reference and documentation | `design-references/single-news-reference.png`; `README.md`; `docs/architecture.md` |

Verification: **100 tests passed, 445 assertions**; production build, Blade compilation and localized live HTTP route checks passed. Existing reader/SEO/sitemap tests cover safe content, metadata, schema, publication protection and related-story behavior. Browser control failed while claiming the local preview tab, so desktop/mobile visual verification, clipboard behavior and scroll interactions in a real browser remain incomplete. Existing demo fixtures have no stored summaries or body headings, so those conditional components are covered by automated fixtures rather than visible on every demo page.

Preview the English, Russian and Armenian article URLs listed above using the existing startup commands. Phase 03.1 stops here; Phase 04 has not started.

## Phase 03.2 approved newspaper discovery pages

The approved reference is preserved at `design-references/newspaper-homepage.png`. Home and News now use its warm ivory (#F8F6F1), black (#17191C), deep red (#A71919), large centered serif masthead, ruled navigation, split text/image lead, narrow editorial sidebar and traditional column grid. The shared header/footer use the newspaper theme on article pages too; the Phase 03.1 article reader itself keeps its dark layout and interactions for Phase 03.3.

The ticker and snapshot show the six existing DEMO assets with explicit labels. The masthead uses today's localized date and an actual language edition, without a fabricated issue number. Fonts use local system serif/sans stacks with Cyrillic and Armenian fallbacks; no remote font or image service is introduced. Existing story images, author names, excerpts, dates and calculated reading times remain the data source. Author initials replace unavailable portraits.

Breaking and opinion sections require an eligible published, due, translated article explicitly tagged `breaking` or `opinion`. They stay hidden otherwise; this phase creates no such records or tags. The homepage excludes stories already used in its lead/editorial/analysis positions from the latest grid. Listing page one presents three featured stories once, then its nine remaining stories; subsequent pages retain all twelve available records. The existing `topic` filter now also accepts `analysis` and `opinion`, intersecting tag designation with the existing safe search/category/locale/publication scopes. Existing URLs and pagination parameters remain intact.

| Area | Created or modified files |
| --- | --- |
| Shared chrome | New `resources/views/components/newspaper-header.blade.php`, `newspaper-navigation.blade.php`, `newspaper-footer.blade.php`; updated `resources/views/layouts/app.blade.php` |
| Editorial presentation | New `resources/views/components/newspaper-story.blade.php`, `newspaper-markets.blade.php`; updated `article-meta.blade.php`, `resources/views/home.blade.php`, `resources/views/news/index.blade.php` |
| Scoped styles | New `resources/css/newspaper.css`; import in `resources/css/app.css` |
| Public selection/filtering | `app/Http/Controllers/HomeController.php`, `NewsController.php`; `app/Http/Requests/NewsIndexRequest.php`; `app/Support/NewsFeed.php` |
| Translations | New `lang/en/paper.php`, `lang/ru/paper.php`, `lang/hy/paper.php` |
| Tests/reference/docs | New `tests/Feature/NewspaperDesignTest.php`; approved PNG above; `README.md`, `docs/architecture.md` |

Verification: **106 tests passed, 493 assertions**, production build, Blade compilation, public/admin route inspection and live HTTP checks. These include all existing reader/SEO/admin tests plus designation eligibility, front-page/listing deduplication, pagination completeness and intersected editorial topic filters. Tests use isolated SQLite and did not modify local records.

Preview: <http://127.0.0.1:8000/en>, <http://127.0.0.1:8000/ru>, <http://127.0.0.1:8000/hy> and their `/news` pages. Use the existing Windows startup instructions above; the existing local server was reused.

Visual limitations: browser tab reload and screenshot calls failed, so desktop/mobile screenshot comparison, responsive behavior and real keyboard/menu interaction checks remain incomplete. Exact pixel fidelity is unverified. Existing local vector artwork differs from the reference photography/portraits and bull illustration. Real content lengths, demo labels, all six market assets and computed reading times also differ; breaking/opinion columns are absent without designated content. No fake reporting, people, issue number or market data was added to imitate the reference. No database schema, dependency, Filament or article SEO changes were made.

Phase 03.2 stops here. Phase 03.3 and Phase 04 have not started.

## Phase 03.3 newspaper article reader

Single articles now share the Phase 03.2 ivory newspaper palette, serif headline system and red editorial accents. The heading includes real category, excerpt, author, localized publication/edit dates, estimated reading time and social links. A bounded landscape image follows the heading without cinematic overlays. Existing actual-image validation, category artwork fallbacks and alt text are preserved; no image credits or author credentials are invented.

The reading column uses serif body text, restrained heading hierarchy, ruled blockquotes, readable lists/tables and unrounded images/captions. Stored summaries use a warm neutral panel with a thin red rule; no summaries are generated. Contents remain conditional, sticky in the desktop sidebar and inline above the body on smaller screens. Armenian uses an explicit local serif fallback and additional line spacing.

The sidebar and bottom related section reuse `newspaper-story` and `newspaper-markets`. Editor picks overlapping the related collection are omitted from the picks panel. When four related stories exist, one goes to the compact sidebar and three to the related grid; smaller collections stay together in the related grid. Each story card appears once. Query ranking, publication/locale rules and the original related collection remain unchanged.

Copy URL, manual-copy fallback, sharing, contents links, back-to-top, tags and reading progress are retained. Progress now measures from the article position without subtracting the height of the non-sticky newspaper masthead. Its endpoint remains the reader footer, independent of a taller sidebar. The shared layout now applies the newspaper body/theme color to article pages too.

| Area | Modified files |
| --- | --- |
| Reader and shared layout | `resources/views/news/show.blade.php`; `resources/views/layouts/app.blade.php` |
| Reused article components | `resources/views/components/article-hero.blade.php`, `article-summary.blade.php`, `article-toc.blade.php` |
| Styles and progress | `resources/css/article.css` (replaces cinematic styles); `resources/css/newspaper.css` (removes obsolete dark-reader bridge); `resources/js/article.js` |
| Regression coverage/docs | `tests/Feature/ArticlePageTest.php`; `README.md`; `docs/architecture.md` |

No new components, packages, migrations, controller/query changes or translation strings were needed. Canonical/social metadata, language URLs, structured data, sitemap, sanitizer and Filament remain unchanged.

Verification: **107 Laravel tests passed, 499 assertions**, production build, Blade compilation and route inspection. Live HTTP checks passed for EN/RU/HY home/listing/article pages, sitemap and admin login; localized article responses retained canonical URLs and valid JSON-LD. Three isolated Node/VM checks passed for reader progress geometry, copying the canonical URL and the unavailable-clipboard fallback. These are logic checks, not browser interaction tests.

Preview:

- <http://127.0.0.1:8000/en/news/demo-bitcoin-long-view-en>
- <http://127.0.0.1:8000/ru/news/demo-bitcoin-long-view-ru>
- <http://127.0.0.1:8000/hy/news/demo-bitcoin-long-view-hy>

Visual verification remains incomplete: browser navigation and DOM inspection failed, preventing screenshot comparison at 1440+, 1024, 768, 390 and 320px and real keyboard/scroll/clipboard checks. Responsive CSS covers these widths, but overflow, text wrapping and visual defects at those exact sizes are unverified. Existing demo stories use local illustrations and short fictional bodies; summary and rich-content/TOC states are covered by automated fixtures rather than invented preview content.

Phase 03.3 stops here. Phase 04 has not started.

## Phase 04 cached cryptocurrency and precious-metal quotes

The ticker and market panels now share a normalized, read-only market snapshot. Six assets are supported: BTC, ETH, SOL, BNB, XAU/USD and XAG/USD. No public page, Blade component, cache miss or visitor refresh contacts a provider or queues a refresh. The approved newspaper layout, article data/URLs, SEO, sitemap and Filament are unchanged. No new dependencies or migrations were needed.

### Modes, credentials and rights

The local application remains in `MARKET_DATA_MODE=demo`, with the original fixed values explicitly labelled DEMO. This mode makes no provider calls, even if keys exist. In `live` mode, only cached actual quotes are shown; an empty cache displays unavailable values, never demo substitutes.

Obtain a CoinGecko key from the developer dashboard linked in the official [Demo authentication guide](https://docs.coingecko.com/demo/reference/authentication) or [paid authentication guide](https://docs.coingecko.com/reference/authentication). Obtain a goldApi.dev key through **Get API key** in its [official documentation](https://goldapi.dev/docs). No service was purchased, account created or paid plan activated in this phase.

Configure only the server's ignored `.env` or deployment secret store:

| Variable | Default | Purpose |
| --- | --- | --- |
| `MARKET_DATA_MODE` | `demo` | `demo` fixed fixtures, or `live` cached actual quotes |
| `MARKET_DATA_RIGHTS_CONFIRMED` | `false` | Operator confirmation that the selected plans permit the intended display, attribution and storage |
| `COINGECKO_API_TIER` | `demo` | `demo` for prototyping; `pro` uses the paid API host for any compatible paid plan |
| `COINGECKO_API_KEY` | empty | Corresponding Demo or paid key |
| `GOLDAPI_API_KEY` | empty | goldApi.dev key |
| `MARKET_CACHE_STORE` | unset | Uses `CACHE_STORE`; production requires a shared database or Redis store |
| `MARKET_CRYPTO_REFRESH_MINUTES` | `10` | Minimum time between crypto refreshes |
| `MARKET_METALS_REFRESH_MINUTES` | `30` | Minimum time between gold/silver refreshes |
| `MARKET_STALE_MULTIPLIER` | `3` | Stale threshold relative to the group's refresh interval |

Keys must never use a `VITE_` prefix, appear in command arguments, or be committed. `.env.example` contains only blank key placeholders. Keep `APP_KEY` secure and consistent across the web/scheduler/worker processes: normalized provider snapshots are encrypted before cache storage.

After reviewing the applicable rights, set `MARKET_DATA_MODE=live`, the appropriate keys/tier and `MARKET_DATA_RIGHTS_CONFIRMED=true`. Production refuses CoinGecko's Demo tier even after the rights flag is set. The rights flag records operator intent; it does not validate a contract or grant a license. Invalid modes fail closed. Missing/invalid credentials make no successful refresh and produce a sanitized operational status.

For local configuration changes:

```powershell
php artisan config:clear
php artisan markets:refresh --status
php artisan markets:refresh
```

For production, rebuild configuration after setting secrets with `php artisan config:cache` and restart long-running queue workers after deployment. Avoid printing the full markets configuration because it contains keys.

### Refresh, queue and cache operation

```powershell
# Synchronous manual refresh of due groups; fresh quotes/cooldowns are never bypassed.
php artisan markets:refresh
php artisan markets:refresh --group=crypto
php artisan markets:refresh --group=metals

# Queue due refreshes, or inspect sanitized monthly usage without an API call.
php artisan markets:refresh --queued
php artisan markets:refresh --status

# Run these in separate terminals if not already running.
php artisan queue:work --tries=1 --timeout=60
php artisan schedule:work
```

The scheduler checks each group every minute and dispatches only when its configured interval/cooldown is due. Defaults produce one batched crypto call every ten minutes and two metal calls every thirty minutes. Unique queued jobs prevent duplicate dispatch; a separate 90-second atomic refresh lock and an in-lock due check protect both queued and manual refreshes. The next eligible attempt is reserved in persistent cache before contacting a provider, so interrupted refreshes cannot immediately retry a potentially billed request. Jobs have one queue attempt, a 50-second timeout and 120-second uniqueness lifetime. The existing database queue retry interval is 90 seconds. Keep worker timeout below that interval.

Each HTTP request has a three-second connection timeout and eight-second total timeout. Network/5xx failures receive at most one retry after 250ms. Authentication, malformed responses and rate limits are not retried in the same request. A 429 stores the provider's numeric or HTTP-date Retry-After deadline; no group request occurs before it. Repeated failures also apply bounded exponential cooldown, no shorter than the normal refresh interval; authentication/credential failures wait at least an hour. Manual refresh has no force option.

One encrypted state per provider/tier holds the latest valid quote per asset, current-month request/failure counters and refresh/cooldown timestamps. It stores no raw responses or credentials. Crypto Demo/Pro caches are separate. No historical series or unbounded records are created. Cache deletion/eviction or changing cache/key configuration removes continuity; a missing snapshot stays unavailable until an explicit/scheduled refresh succeeds. Do not routinely flush market cache on deployments, since doing so also resets cost-control state. Production refuses cache drivers other than database or Redis; all production nodes must use the same shared backend.

Freshness uses the older of the provider timestamp and fetch timestamp. Values become Delayed after one interval plus a one-minute grace period, or after a later failed refresh; Stale after three intervals by default. Invalid/out-of-order quotes never overwrite a newer valid quote. Last-known prices survive failures and remain labelled with actual update times. Cache failures degrade to unavailable. Displayed times are localized and explicitly UTC. goldApi.dev's timestamp is its response-computation time, not a claimed exchange trade time.

### Provider behavior, attribution and licensing

CoinGecko uses a single `/simple/price` request for twenty configured IDs: `bitcoin,ethereum,solana,binancecoin,ripple,cardano,dogecoin,tron,avalanche-2,chainlink,the-open-network,polkadot,litecoin,bitcoin-cash,stellar,near,uniswap,sui,aptos,ethereum-classic`, with USD, genuine 24-hour percentage changes and provider update timestamps. Demo and paid credentials use different fixed HTTPS hosts/headers. Missing or invalid change values stay neutral. See the [official endpoint reference](https://docs.coingecko.com/reference/simple-price).

goldApi.dev uses authenticated `/api/v1/price/XAU/USD` and `/api/v1/price/XAG/USD` requests. Symbol, USD currency, ounce unit, positive numeric price and timezone-qualified timestamp must validate. Gold/silver display **USD per troy ounce**. This implementation leaves their 24-hour change unavailable: the free response lacks it, and Pro's `chPercent` compares with the previous close rather than a verified rolling 24-hour interval. It is not relabelled or calculated from unrelated snapshots. [Official fields and limits](https://goldapi.dev/docs/api-reference).

Before any public monetized deployment, review the current [CoinGecko commercial licensing guidance](https://support.coingecko.com/articles/1043089972-what-are-the-differences-between-commercial-and-custom-licenses) and [API terms](https://www.coingecko.com/en/api_terms). Demo has no commercial rights; attribution alone does not supply them. A suitable paid agreement is required. Its terms also cover user agreements/privacy notices, caching/security and deletion of retained provider data after termination. Normal refresh cadence is well below 24 hours, but prolonged outages, storage retention and any account termination require operator review against the actual agreement. No new legal pages or license-purchase flow is implemented here.

The interface displays a visible **Powered by CoinGecko** credit linking to its API page near actual crypto values. This required brand phrase is kept in English with a localized accessible label, in legible text at least 10px; [attribution guide](https://brand.coingecko.com/resources/attribution-guide). goldApi.dev receives a visible localized link near its values on all plans; its Free plan requires that link, while paid plans do not. Review its [commercial-use and redistribution terms](https://goldapi.dev/terms) before deployment. There is no public data proxy, export feed or raw-data resale endpoint.

### Estimated API consumption

For continuous successful operation over a 30-day month, one deployment sharing the same cache:

| Provider | Requests/day | Requests/30 days |
| --- | ---: | ---: |
| CoinGecko, all twenty assets batched every 10 minutes | 144 | 4,320 |
| goldApi.dev, five assets every 30 minutes (Phase 04.1.2) | 240 | 7,200 |
| Total | 384 | 11,520 |

A 31-day month is approximately 4,464 crypto plus 7,440 metals requests, or 11,904 total. Demo fixture mode makes zero. Retries can increase attempted calls up to twice the normal count before cooldown reductions; delayed workers/outages usually reduce it. Visitor traffic does not multiply these calls. Changing intervals or using independent caches changes consumption. Counters are local attempted HTTP requests, including failures, for the current UTC calendar month; they are not the provider's authoritative credit/billing report. Check provider dashboards and account limits before enabling live mode.

### Phase 04 file map and verification

| Area | Created or modified files |
| --- | --- |
| Normalization/read service | New `app/Support/MarketQuote.php`, `MarketDataService.php` |
| Providers/transport/cache | New `app/Support/CryptoMarketProvider.php`, `MetalsMarketProvider.php`, `MarketHttpClient.php`, `MarketDataCache.php` |
| Refresh execution | New `app/Console/Commands/MarketRefresh.php`, `app/Jobs/RefreshMarketData.php`; updated `routes/console.php` |
| Shared presentation source | `app/Providers/AppServiceProvider.php`; request-scoped snapshot reused by all market components |
| Blade integration | Updated `newspaper-header`, `newspaper-markets`, `market-overview`; new `market-price`, `market-change`, `market-status`, `market-source` under `resources/views/components/` |
| Configuration/localization/style | `config/markets.php`, `.env.example`; new `lang/en/market.php`, `lang/ru/market.php`, `lang/hy/market.php`; `resources/css/newspaper.css` |
| Tests/docs | New `tests/Feature/MarketProviderTest.php`, `MarketDataTest.php`; offline market defaults in `phpunit.xml`; `README.md`, `docs/architecture.md` |

Verification: **167 tests passed, 854 assertions**, including all previous reader/SEO/admin regressions plus provider HTTP fakes, encrypted cache, actual database locks, interrupted-refresh cost reservations, production cache guards, rate-limit/timeout cases, partial/stale retention, unique jobs, scheduler gating, localized rendering and zero visitor-triggered requests. Production build, Blade compilation, routes and scheduler inspection passed. The local safe manual command was checked in demo mode and made zero requests. No actual provider credential or paid/live adapter call was used for validation.

Browser tab creation failed, so screenshots and visual responsive verification remain incomplete. Preview the unchanged local routes at <http://127.0.0.1:8000/en>, `/ru`, `/hy`, their `/news` listings and existing article URLs. Default preview prices are still DEMO. Complete credentials, plan/rights review and a controlled real-provider acceptance check before live publication.

Phase 04 stops here. No next phase has started.

## Phase 04.1 international edition

Public UI languages are English (`en`), Русский (`ru`), Français (`fr`), Deutsch (`de`), Español (`es`), Italiano (`it`), 日本語 (`ja`) and 简体中文 (`zh`). URL keys stay short; Chinese emits `zh-Hans` in HTML, hreflang and structured data, and `zh_CN` in Open Graph. English remains the default. UI catalogs cover navigation, reader controls, market states, empty/error pages and pagination. Dates use locale-specific Carbon formats; prices use intl formatting, with six decimal places for crypto below one USD. System serif stacks support Japanese and Simplified Chinese without font downloads.

Only actual stored article translations appear in each edition, canonical URL, alternate list and article sitemap. A missing article translation produces 404 at an invented direct URL; selecting that language from an existing article goes to its localized news listing with an explanatory accessible label. The new language editions can therefore be empty until real translations are supplied. There is no automatic article translation, English-body fallback or duplicated translated content.

Armenian is absent from public language selection, public locale validation and newly generated SEO alternates/sitemaps. Its database rows, stored article text, slugs, archived language files and fixture source remain intact. `/hy` and `/hy/news` return 301 to their English equivalents. A published, known `/hy/news/{slug}` redirects to the same article's actual English translation, otherwise to `/en/news`; unknown or unpublished stories return 404. Existing English and Russian article URLs remain valid. No local database migration or seeding was performed during this phase.

Navigation is **Home, News, Crypto, Precious Metals, Markets, Analysis, Opinion**. Crypto opens All Crypto News, Bitcoin, Ethereum, Solana, Altcoins, DeFi and Regulation. Precious Metals opens All Metals News, Gold, Silver, Platinum and Palladium. These reuse existing category/tag filters; a missing category is clearly disabled text. Platinum/Palladium navigation labels do not represent price-feed support. Dropdowns use native click/keyboard details controls with Escape and outside dismissal; the mobile menu shares the same component. Known category UI labels can be translated from their canonical topic without inserting category translations or changing article text.

### Two ticker rows and market coverage

The precious-metals row precedes the crypto row above the preserved masthead. Both consume the same request-scoped cached snapshot used by sidebar market panels. The existing service, provider adapters, encrypted cache, locks, backoff, licensing guards, attribution and scheduler are retained. Visitor requests never initiate provider requests.

| Row | Instruments | Provider and batching |
| --- | --- | --- |
| Crypto | BTC, ETH, SOL, BNB, XRP, ADA, DOGE, TRX, AVAX, LINK | One CoinGecko `/simple/price` batch for ten canonical IDs |
| Precious metals | Gold (XAU), Silver (XAG) | Two goldApi.dev quotes, USD per troy ounce |

This is a fixed configured instrument list, not a current market-cap ranking. No platinum, palladium or global-index quotes are invented. The original six DEMO fixtures remain unchanged; the six newly supported crypto instruments remain unavailable in the backend snapshot until actual quotes exist in explicitly enabled live mode. As of Phase 04.1.1, assets without usable quotes are omitted from both ticker sequences; an entirely empty row shows one localized unavailable message outside the sequence. The compact sidebar retains BTC, ETH, SOL, BNB, Gold and Silver. Genuine stale values and unavailable metal rolling-24-hour changes remain clearly labelled. Provider credits and license restrictions from Phase 04 still apply.

On hover-capable desktop screens wider than 800px, a row moves continuously only when its natural item width exceeds its viewport, at approximately 28px/second with a minimum 40-second cycle. Short rows stay static. Hover/focus pauses motion; each moving row has its own accessible pause/resume button. Focusing the scroll region also allows keyboard scrolling. Duplicate animation copies are inert and hidden from assistive technology. Mobile/touch and reduced-motion users receive static horizontally scrollable rows. Without JavaScript, both lists remain scrollable. Fixed 16px SVG icons, nonwrapping one-line quotes, compact separators and viewport-local containment prevent overlap and ticker overflow without a global body overflow rule. Header styles preserve the ivory, serif, black/red newspaper composition.

The refresh intervals are unchanged: one crypto batch every ten minutes and two metal requests every thirty minutes. Successful continuous operation uses approximately **4,320 crypto + 2,880 metal = 7,200 requests per 30 days**, or 7,440 per 31 days. Ten crypto IDs still use one batch. Existing bounded retries can increase attempts; cache/credential failures and cooldowns reduce them. DEMO mode makes zero external requests. Local counters are not provider billing statements. LIVE was not enabled, and no subscription or credential was created.

### Translation workflow and files

Future editorial writers should validate locale with `ArticleTranslation::publicLocaleRules()` or `CategoryTranslation::publicLocaleRules()`, write a genuine translation with its own title/slug/body/metadata, and apply the existing parent publication/approval workflow. These are reusable validation rules for future forms/importers, not model save hooks that would prevent maintenance of archived Armenian rows. There is no new per-translation publication column or AI integration. An absent translation stays unavailable. Reading time estimates count ordinary words at 200/minute plus Han/Hiragana/Katakana characters at 400/minute; these are UI heuristics.

| Area | Created or modified files |
| --- | --- |
| Locale and assets | `config/news.php`, `config/markets.php` |
| Routes and filtering | `routes/web.php`, new `app/Http/Controllers/LegacyArmenianController.php`, `NewsController.php`, `app/Http/Requests/NewsIndexRequest.php` |
| Public content and metadata | `app/Support/FrontendLinks.php`, `NewsFeed.php`, `ArticleSeo.php`, `ArticleContent.php`; `app/Models/Category.php`, `ArticleTranslation.php`, `CategoryTranslation.php` |
| Market extension | `app/Support/MarketDataService.php`; existing adapters, transport, cache, job and scheduler reused |
| Catalogs | Six new directories `lang/{fr,de,es,it,ja,zh}/`, each with `ui.php`, `messages.php`, `paper.php`, `article.php`, `market.php`, `pagination.php`; updated EN/RU UI, message and market catalogs; HY catalogs preserved |
| Shared UI | `resources/views/layouts/app.blade.php`; components `newspaper-header`, `newspaper-navigation`, `language-switcher`, `newspaper-footer`, `seo-meta`, `newspaper-story`, `newspaper-markets`, `market-overview`, `market-price`, `market-status`, `article-card`, `article-meta`, `article-hero`, `editor-picks`; new `market-ticker` component and `errors/404.blade.php`; `news/index.blade.php`, `news/show.blade.php` |
| CSS and JS | New `resources/css/international.css`, `resources/js/tickers.js`; imported by `resources/css/app.css`, `resources/js/app.js` |
| Seed and tests | `database/seeders/DemoNewsSeeder.php`; new `tests/Feature/InternationalExperienceTest.php`; updated locale, visibility, frontend, article, SEO, sitemap, demo-seed, provider and market regression tests |
| Documentation | `README.md`, `docs/architecture.md` |

No dependencies, schema, credentials, stored editorial data or Filament authentication changed.

### Phase 04.1 verification and preview

The complete suite passes **213 tests / 7,651 assertions**, with zero failures/errors, using isolated SQLite databases and HTTP fakes. This includes all existing regressions plus eight-language routing/UI/SEO, catalog key/placeholder parity, actual-only translation links, archived Armenian retention/redirects, native dropdown markup, ten-ID batching, stale/unavailable quotes and zero visitor-triggered API calls. Pint, production Vite build, Blade compilation, route listing and scheduler inspection passed. `markets.mode` remains `demo`.

Actual local HTTP checks returned 200 for all sixteen homepage/listing routes and existing EN/RU articles, 301 for `/hy` and `/hy/news`, 404 for a missing Chinese article, and sixteen public page-sitemap URLs with no Armenian alternates. Provider behavior was tested with HTTP fakes; actual credential acceptance, account entitlements and provider availability were not tested. Browser tab creation failed, so desktop/tablet/mobile screenshots and visual/interaction verification remain incomplete. CSS/markup and build checks do not establish pixel fidelity or actual animation smoothness.

Use the existing startup command `./scripts/composer.ps1 run dev` from the workspace, or the separate server/Vite/queue/scheduler commands above. Preview <http://127.0.0.1:8000/en>, [/ru](http://127.0.0.1:8000/ru), [/fr](http://127.0.0.1:8000/fr), [/de](http://127.0.0.1:8000/de), [/es](http://127.0.0.1:8000/es), [/it](http://127.0.0.1:8000/it), [/ja](http://127.0.0.1:8000/ja), [/zh](http://127.0.0.1:8000/zh) and each edition's `/news` route. The existing user-owned local server was left running.

Phase 04.1 is complete. Phase 05 has not started.

### Phase 04.1.1 ticker UI refinement

The two row labels are now Metals & Global Markets and Crypto Market, localized across all eight editions; actual metal quote support remains Gold/Silver only. Ticker presentation filters usable prices without changing the market services, providers, shared cache, credentials or DEMO/LIVE configuration. Asset names, units and timestamps remain available to assistive technology without multi-line clutter in the sequence.

Changed files: the existing ticker/price Blade components, new `resources/views/components/market-asset-icon.blade.php`, `resources/css/international.css`, `resources/js/tickers.js`, eight active `market.php` catalogs, `InternationalExperienceTest.php` and these notes. No dependencies, database, SEO, admin or routing changes.

Verification: **222 Laravel tests / 7,745 assertions**, Pint, production build and Blade compilation passed. Isolated JavaScript logic checks passed for short/long rows, independent pause/resume, resize, reduced motion and empty rows, using width inputs 1920, 1440, 1024, 768, 390 and 320. These were simulated viewport inputs, not browser layout measurements. Browser navigation and screenshot capture failed, so visual verification was not performed at any of the six requested widths; actual loop smoothness, clipping and keyboard/touch interaction still require browser inspection. No real provider API request was used.

Phase 04.1.1 is complete. Phase 05 has not started.

### Phase 04.1.2 ticker logos and five-metal coverage

The two slim strips are now **Metals & Commodities** and **Crypto Market**, localized across all eight editions. Visible pause/play controls, their reserved column, obsolete labels and their JS/CSS handlers are removed. Overflowing desktop rows retain natural-width seamless copies and pause on hover, keyboard focus and touch interaction. Short rows stay static; mobile and reduced-motion readers use horizontal scrolling. Containment remains local to each viewport; no global body overflow rule was added. The masthead, navigation, SEO and Filament are unchanged.

Quotes retain compact symbol/price/change alignment. Icons occupy a fixed 20px square with object-fit containment, original colors/aspect ratio and a local neutral fallback. Names and precise timestamps remain accessible; live rows show their oldest provider update time and delayed/stale state outside the moving sequence. Missing assets are omitted, and an entirely empty row displays one localized unavailable message. DEMO still uses only the six original fixtures: no new prices were invented.

#### Local crypto logo provenance

These are original project-provided assets for editorial identification, not redrawn approximations or an endorsement claim. Files live under `public/images/markets/`. SVGs passed a strict element/attribute safety inspection; PNG signatures and chunk checksums were checked. The application uses only a fixed local mapping, never provider-supplied image URLs. There is no logo API, visitor-triggered download, image proxy or periodic logo fetch.

| Asset | Local file | Source and usage reference |
| --- | --- | --- |
| BTC | bitcoin.png | [Bitcoin Core source artwork](https://github.com/bitcoin/bitcoin/blob/master/share/pixmaps/bitcoin64.png); upstream MIT notice retained as bitcoin-LICENSE.txt |
| ETH | ethereum.png | [ethereum.org brand assets](https://ethereum.org/assets/), ETH diamond glyph PNG |
| SOL | solana.png | [Solana branding](https://solana.com/branding); exact PNG from the [official source repository](https://github.com/solana-foundation/solana-com/blob/main/apps/web/public/src/img/branding/solanaLogoMark.png); upstream repository notice retained as solana-LICENSE.txt |
| BNB | bnb.svg | [BNB Chain brand guidelines](https://www.bnbchain.org/en/brand-guidelines), official yellow standalone mark published for token/icon contexts |
| XRP | xrp.png | [XRPL documentation](https://xrpl.org/resources/contribute-documentation), original /static/img/xrp-x-logo.png |
| ADA | neutral.svg | Neutral local artwork: [Cardano guidance](https://cardano.org/brand-assets/) specifies a larger minimum logo size than this 20px ticker; no special small-size permission was obtained |
| DOGE | dogecoin.png | [Dogecoin trademark page](https://dogecoin.com/trademarks/), original dogecoin-1.png |
| TRX | tron.png | [Official TRON media kit](https://tron.network/resources/?lng=en), TRON LOGO ICON RED.png from its published PNG archive |
| AVAX | avalanche.svg | [Official Avalanche brand kit](https://support.avax.network/en/articles/4132288-avalanche-brand-media-kit); original Contentful logo referenced by the [official Ava Labs repository](https://github.com/ava-labs/builders-hub/blob/master/constants/l1-chains.json) |
| LINK | chainlink.png | [Chainlink brand assets](https://chain.link/brand-assets), official blue symbol PNG |

Nine assets have authentic logos; ADA intentionally uses the permitted neutral fallback. Brand ownership and trademark restrictions remain with each owner. Repository license notices do not grant trademark rights, and downloading public brand assets does not establish a partnership or a blanket commercial trademark licence. Keep the artwork unaltered and follow each linked usage policy for deployment. No account, paid licence or special permission was obtained. The five locally authored metal illustrations use restrained distinct colors; copper is represented as wire rather than a precious-metal bar.

#### Provider extension and operating budget

The existing goldApi.dev adapter now requests five fixed authenticated endpoints under `https://goldapi.dev/api/v1/price/{symbol}/USD`. Only these five URLs were added to the existing transport allowlist; HTTPS, header-only credentials, redirect refusal, response validation, shared encrypted snapshots, refresh locks, rate-limit handling and last-good retention remain intact.

| Instrument | Provider symbol | Required response unit | Display |
| --- | --- | --- | --- |
| Gold | XAU | oz | USD per troy ounce |
| Silver | XAG | oz | USD per troy ounce |
| Platinum | XPT | oz | USD per troy ounce |
| Palladium | XPD | oz | USD per troy ounce |
| Copper, an industrial/base metal | HG | lb | USD per pound, explicitly labelled in the ticker |

Identifiers, schema and units were checked against the [official API reference and symbols example](https://goldapi.dev/docs/api-reference), [troy-ounce explanation](https://goldapi.dev/prices/xau-usd) and [copper documentation](https://goldapi.dev/prices/hg-usd). Responses must match the requested symbol, USD, its exact unit, a valid positive numeric price and a timezone-qualified updatedAt timestamp. Wrong units never replace valid cached quotes. The adapter still excludes prevClose/chPercent because these do not document a rolling 24-hour change.

The [current plan comparison](https://goldapi.dev/pricing) includes all five metals on every plan. [Commercial display terms](https://goldapi.dev/terms) permit end-user display, require a nearby visible provider link on Free, and require a separate agreement for data redistribution/resale. The existing visible credit is retained. No provider price is presented as an official benchmark. CoinGecko licensing and attribution restrictions are unchanged.

Intervals remain ten minutes for one batched ten-asset crypto request and thirty minutes for five metal requests. That is **4,320 crypto + 7,200 metals = 11,520 requests per 30 days**, or **11,904 per 31 days**, before existing bounded retries and cooldowns. DEMO makes zero. Static logos were acquired once during development and do not add market-provider or visitor request costs.

Five sequential HTTP calls, each with at most two eight-second attempts and one 250ms retry delay, have a nominal worst-case network budget of 81.25 seconds. The metals job timeout is now 85 seconds; the crypto job remains 50 seconds. Both remain below the existing 90-second refresh-lock expiry and default queue retry interval. The 120-second unique-job lifetime, one job attempt and pre-request due reservation remain unchanged. Laravel uses the per-job timeout in preference to the worker's default flag. Keep any custom queue retry interval above 85 seconds and restart workers after this change. The small remaining lock/timeout margin and OS-specific worker signal behavior need deployment acceptance under actual latency/load; network timeouts remain bounded.

#### Phase 04.1.2 verification and file map

**230 tests / 8,020 assertions**, Pint, the production build and Blade compilation passed. Tests cover all eight editions, no controls, local logo mapping/fallback/safety, five normalized metals, wrong-unit rejection, copper labels, shared cache reuse, partial/stale retention and API failures. Market tests use HTTP fakes; actual credential acceptance and account entitlements were not tested. Local HTTP checks returned 200 for eight homepages and all fifteen local image files, with two rows and no ticker buttons.

Isolated JS checks passed for long/short/single/empty rows, touch start/end/cancel, reduced motion, resize and image-error fallback. Width inputs 1920, 1440, 1024, 768, 390 and 320 were simulated for animation logic only. Browser navigation and screenshot capture failed, so actual desktop/tablet/mobile appearance, loop smoothness and browser keyboard/touch behavior were not visually verified.

Changed: `config/markets.php`; `app/Support/MetalsMarketProvider.php`, `MarketHttpClient.php`; `app/Jobs/RefreshMarketData.php`; the `market-ticker`, `market-asset-icon` and `market-price` Blade components; `resources/css/international.css`; `resources/js/tickers.js`; active `lang/{en,ru,fr,de,es,it,ja,zh}/market.php`; `MarketProviderTest.php`, `MarketDataTest.php`, `InternationalExperienceTest.php`; local assets/notices under `public/images/markets/`; README and architecture notes. No dependency, database, route, article SEO, admin, credential or stored-content changes. MARKET_DATA_MODE remains demo.

Phase 04.1.2 is complete. Phase 05 has not started.

### Phase 04.1.3 full DEMO tickers

Both rows now contain their complete requested instrument lists in DEMO mode:

- Crypto: BTC, ETH, SOL, BNB, XRP, ADA, DOGE, TRX, AVAX, LINK, TON, DOT, LTC, BCH, XLM, NEAR, UNI, SUI, APT, ETC.
- Metals/commodities: Gold (XAU/USD), Silver (XAG/USD), Platinum (XPT/USD), Palladium (XPD/USD), Copper (HG). The four precious metals use USD per troy ounce; copper uses USD per pound.

The nineteen newly authorized illustrative prices and percentage changes live only in the existing `config/markets.php` DEMO fixtures. The six prior fixture quotes are preserved. These are explicitly fictional, static development previews, not captured current prices, recommendations or a market-cap ranking. Each ticker shows a localized **DEMO · Fictional prices** label. No fixture values are embedded in Blade, written into provider cache or used to fill missing LIVE quotes.

The existing service still selects its DEMO and LIVE branches explicitly. LIVE continues to use validated cached provider results, hides unavailable individual quotes, and shows delayed/stale information. Tests exercise DEMO-to-LIVE switching with a partial real-shaped cache and ensure missing assets remain null. No real provider request or LIVE activation was performed. The added crypto identifiers are configured in the existing batch adapter; no adapter, cache lock, job, scheduler, credential or licensing guard was replaced. The requested TON display alias uses the provider's documented `the-open-network` identifier; this is a fixed editorial instrument list, not a claim about current provider naming/rankings.

Ticker strips now span the page width above the existing masthead container; masthead size, centered alignment, navigation and other sections remain unchanged. For desktop motion, JavaScript repeats a short natural sequence enough times to cover the available viewport, then supplies a second identical cycle. The transform distance is exactly one complete repeated cycle, avoiding an empty tail on wide screens. All visual copies are inert and hidden from assistive technology; each row exposes only its original unique 20- or five-item list. Rows remain independent and pause on hover/focus/touch, with no visible controls. Mobile/reduced-motion readers receive the original static scrollable list. Containment stays within the ticker; no global overflow rule was added.

Existing authentic logos and metal icons are reused at 20px. ADA and the ten newly configured crypto assets use the existing neutral fallback; no new logo download, font, library or dependency was introduced. Image-error fallback now also covers copies generated during a resize. The compact market snapshot and sidebars retain the existing six-instrument composition, with prices supplied by the same request-scoped service snapshot as the full tickers.

Twenty crypto IDs still fit in one scheduled request, so the previous successful LIVE budget remains approximately **11,520 requests per 30 days**, or **11,904 per 31 days**, before bounded retries/cooldowns. DEMO makes zero external provider calls. No schedule or queue timeout changed in this phase.

Verification: **237 Laravel tests / 10,560 assertions** passed, including exact instrument order, quote counts/units, fictional labels, all eight home/listing editions, shared sidebar prices, cache retention, provider batching and zero DEMO/page-triggered provider requests. Pint, production build and Blade compilation passed. All sixteen local home/listing HTTP checks returned 200 with 20 crypto and five metals, fixed-size icons and no controls. Isolated JS checks passed for cycle coverage at simulated widths 1920, 1440, 1024, 768, 390 and 320, fractional item widths, stable clone counts, inert copies, touch pause, resize, reduced motion and logo failures.

Browser navigation/screenshots failed again: actual appearance, pixel overflow and motion smoothness at those widths were not visually verified. The expanded LIVE batch was tested with HTTP fakes, not real credentials. Existing brand/source restrictions from the preceding phase remain applicable.

Changed files: `config/markets.php`; `resources/views/components/newspaper-header.blade.php`, `market-ticker.blade.php`; `resources/css/international.css`; `resources/js/tickers.js`; the eight active `market.php` language catalogs; `tests/Feature/InternationalExperienceTest.php`, `MarketProviderTest.php`, `MarketDataTest.php`; README and architecture notes. No database, stored article data, route, admin, SEO, dependency or credential changes.

Phase 04.1.3 is complete. No subsequent phase has started.

## Phase 05 editorial CMS

### Start and access

The installed stack remains Laravel 13.42.0 / Filament 5.11.2 / Livewire 4.4.8 on PHP 8.4. No package was added or upgraded. From the VS Code workspace root:

```powershell
php artisan migrate --no-interaction
npm run build
.\scripts\composer.ps1 run dev
```

Use <http://127.0.0.1:8000/admin>. The existing local server was left running. If there is no administrator yet, run `php artisan app:create-admin` interactively in another trusted terminal, supply a real name/email and a hidden, confirmed strong password, then sign in. The command is unchanged; no account/password was created during this phase. There is no public registration or seeded administrator.

An administrator creates an editor under **Users → Create**, chooses the Editor role and enters the person's real byline name, unique email and a 12–64-character password with mixed case, a number and a symbol. Passwords are hashed; blank passwords on edit retain the existing hash. No automatic email/invitation is sent. Users can be changed to Reader to remove CMS access. The current administrator cannot remove their own admin access; role writes recheck authorization while admin rows are locked. Account deletion is not offered because authorship must remain intact.

### Roles and navigation

| Capability | Administrator | Editor |
| --- | --- | --- |
| Dashboard, article list, saved previews | All articles | Own articles only |
| Create drafts | Select an existing author | Own authorship, enforced server-side |
| Edit content | Any active article | Own Draft / In Review articles |
| Submit for review / save draft | Yes | Own permitted articles |
| Approve, schedule, publish, unpublish | Yes | No |
| Move articles to trash / restore | Yes | No |
| Categories, tags and editorial users | Manage | No |
| System editorial defaults | Manage | No |

The existing `is_admin` flag remains authoritative for administrators. New `is_editor` defaults to false; neither privilege flag is mass assignable on User. Readers remain excluded, even in local mode. Laravel policies, Filament strict authorization, scoped resource queries and server-side services enforce the matrix independently of visible controls.

Navigation groups contain implemented destinations only: **Dashboard**, **Content** (Articles, Categories, Tags), **Editorial** (filtered Review queue), **Users**, and **System** (Editorial defaults). The dashboard displays actual total, due-published, draft, review, scheduled and category counts. Editors' article counts/lists are scoped to themselves. Latest publications and real audited editorial operations have paginated, eager-loaded tables. No visitor analytics, revenue, AI spend or historical audit entries were fabricated.

### Editing and translations

Create an article under **Content → Articles → Create**. Choose an existing author/category and tags, upload/reuse a featured image if appropriate, then complete at least one language tab. There are exactly eight tabs: English, Russian, French, German, Spanish, Italian, Japanese and Simplified Chinese. Partial coverage is allowed. Enabling a tab requires its own headline, slug and readable body; there is no generated text or automatic English copy. Turning a tab off skips edits and retains any existing row; it does not delete or unpublish that translation.

Each tab contains headline, short description, rich body, optional 3–5 summary lines, summary provenance, image alt text, SEO title and SEO description. Human editing defaults to manual provenance; existing/imported AI provenance can be recorded without any AI API calls. The native editor supports paragraphs, H2/H3, bold/italic, safe links, blockquotes, lists, uploaded inline images, basic tables and undo/redo. HTML is sanitized on storage and again through the existing reading renderer. Storage sanitization excludes presentation wrappers/anchors so repeated edits stay stable.

New/changed article slugs accept lowercase Unicode letters/numbers, hyphens and underscores, up to 190 characters, and are unique per language, including trashed articles. Once a publication date has been assigned, existing translation slugs are locked; English/Russian URLs are not rewritten. Canonical URLs remain generated by the existing SEO service from locale/slug, and existing legacy canonical/OG-image fields are retained. No custom external canonical override was introduced. Public hreflang, metadata, structured data and sitemaps still use actual published translations only.

Historical Armenian rows never enter the editor's writable language set. Their values/slugs are left untouched, including during edits and article trash/restore. The public locale registry and redirects remain unchanged.

The article table supports headline/author search, status/category/author/language/publication-date filters, sortable dates/status, pagination, available-language badges, images and author/category metadata. Main headlines use the English translation when present, otherwise an actual supported translation. Table queries omit full article bodies and eager-load related labels. Administrator bulk trash is authorized and transactional.

### Review and publication

- New saves are **Draft** and private. **Submit for Review** moves valid saved content to **In Review** (`pending_review`).
- **Approve** is administrator-only and returns the reviewed article to Draft with its reviewer/approval stamp. Publishing or scheduling is also an explicit administrator approval operation.
- **Publish** assigns a valid current UTC publication date. Repeated publication of an already due published article does not change its publication time or duplicate the audit event.
- **Schedule** requires a future UTC time and stores **Scheduled**. It stays private, even after its due time, until the scheduled publisher performs the approved transition.
- **Unpublish / Save Draft** returns content to private Draft and clears approval. Existing assigned dates remain recorded to keep previously published URLs locked.
- Editor edits to In Review or approved Draft content revoke approval and return it to Draft. Administrator edits to In Review retain that review state; administrator saves to an existing publication are authorized edits and record the current approval. Editing Scheduled content returns it to Draft, invalidating the old schedule until an administrator schedules again.
- Trash is a soft deletion. Translations, tag links and files remain; restore returns the article as a private Draft. Permanent article deletion is never exposed.

Edit-page workflow actions save valid form edits before applying the transition. The Preview button opens the **saved** article; save changes first when previewing. The review queue is the same scoped Articles resource with its status filter applied. Legacy published rows retain their previous visibility; no old publication, date or approval metadata was rewritten by migration.

The existing scheduler now runs `articles:publish-scheduled` every minute with overlap protection. Its small database-only batches use row locks and recheck status, time, complete content and the stored approving user's current administrator permission. Repeated/concurrent runs cannot publish twice. Invalid or no-longer-approved schedules remain private. Scheduling alone does not run a worker or scheduler.

```powershell
# Separate terminal if the combined dev script is not running:
php artisan schedule:work
# Inspect registered tasks:
php artisan schedule:list
# Optional explicit execution of due approved schedules:
php artisan articles:publish-scheduled --no-interaction
```

### Taxonomy, media and defaults

Categories support eight translation tabs, localized unique slugs, descriptions/SEO fields and display order. Existing category/topic slugs are locked to preserve navigation; no categories were automatically added or duplicated. Maintain the current canonical topic slugs for existing Crypto/Metals navigation. Existing Analysis/Opinion editorial filters use their tag designations, so they need not be duplicated as categories. New subjects, including Web3, can be created as normal categories and accessed through their existing category-ID filter; this phase does not change the public navigation/topic registry.

Tags support names, unique lowercase slug identifiers, search and article assignment. Existing tag slugs are locked. A category referenced by any article, including trash, cannot be deleted; categories with historical Armenian translations are additionally protected. Tags referenced by any article cannot be deleted. Explicit deletion of unused, non-archived categories removes their category translation rows; article translations are never hard deleted by the CMS.

Featured and inline images accept JPEG/PNG/WebP only, at most 5 MB and 6000×6000 pixels. Server-side validation applies again when storage is called, regardless of browser checks. SVG/script uploads are rejected. UUID filenames are generated on the existing public disk under `articles/featured` and `articles/body`. The featured-image picker previews the current image, supports replacement and can reuse the first 100 valid local featured-image filenames. Existing editorial SVG references remain supported only as unchanged legacy references, not new uploads.

Alt text is localized; image credit is stored on the article and escaped in the public image caption when present. The existing article renderer owns safe HTML/URL handling. Storage paths are validated on article save. Files are never automatically deleted on edit/trash; shared images and unused uploads remain until deliberately managed. Public-disk images are public assets, so do not upload confidential material for a draft. This is not a private media vault or a heavy media-library package.

**System → Editorial Settings** stores defaults for new administrator-authored drafts and categories in one fixed settings row. Editors' authorship is always their own. Defaults do not change existing articles, approval requirements, public languages, market mode or credentials.

### Private previews and reusable services

`/editorial/preview/{article}/{locale}` requires a session and the article preview policy. Administrators can preview active records; editors can preview their own. Missing translations and archived/unsupported locales return 404. Responses carry noindex/nofollow/noarchive and private/no-store headers. The preview reuses sanitized reading content, summary, TOC, image and reader styles without emitting public canonical/hreflang/NewsArticle claims. Guessing an ID or preview URL does not grant anonymous draft access.

Future trusted Laravel jobs can use these existing application services with an explicitly authorized user; no AI API, scraping or autonomous publishing was added:

| Service | Boundary |
| --- | --- |
| `ArticleEditor::save()` | Authorized transactional draft creation/content updates and eight-language storage; optional typed ContentSource provenance on creation |
| `ArticleEditor::formData()` | Supported-language edit state, preserving archived rows |
| `ArticlePublication::transition()` | Draft, review, approve, schedule, publish and unpublish authorization/validation |
| `ArticlePublication::publishScheduled()` | Only due schedules retaining actual administrator approval; idempotent |
| `ArticlePublication::trash()/restore()` | Authorized reversible article deletion |
| `EditorialTaxonomy`, `EditorialUsers` | Validated taxonomy and protected account management |
| `EditorialMedia` | Validated uploads and safe local image references |

Use these services for future editorial jobs rather than writing publication status directly through raw Eloquent. Creation always starts private and metadata provenance never grants publishing permission. `EditorialEvent` stores only operation/actor/article/time, not article bodies, credentials or provider payloads.

### Migration, changed files and verification

`2026_11_16_161222_add_editorial_cms_foundation.php` adds `users.is_editor`, `articles.image_credit`, `article_translations.image_alt`, `editorial_events` and `editorial_settings`. Existing statuses are strings, so adding Scheduled to the PHP enum needs no destructive status conversion. Schema-builder foreign keys/indexes remain SQLite/MySQL-compatible; rollback removes only the new tables/columns. No migrate:fresh, seeding, account creation or existing-data rewrite was run.

The migration ran successfully on local SQLite after a consistent ignored backup at `.tools/phase05-before-20261116-161736.sqlite`. Comparing all original columns/rows against that backup confirmed existing users, articles, translations, categories, tags and relationships unchanged; SQLite integrity/foreign-key checks passed. A real MySQL server remains untested.

| Area | Created / changed files |
| --- | --- |
| Admin resources and UI | `app/Filament/Resources/{Articles,Categories,Tags,Users}/` and their pages; `app/Filament/EditorialForm.php`; `Pages/Dashboard.php`, `Pages/EditorialSettings.php`; three `Widgets/` classes; `resources/views/filament/pages/editorial-settings.blade.php` |
| Permissions and services | Four `app/Policies/` classes; five new `app/Support/{ArticleEditor,ArticlePublication,EditorialTaxonomy,EditorialUsers,EditorialMedia}.php`; modified `ArticleContent.php` |
| Models and schema | New EditorialEvent/EditorialSetting models, factories and optional seeders; User editor factory state; modified User/Article/ArticleTranslation and ArticleStatus; the additive migration |
| Panel, preview, scheduler | AdminPanelProvider; bootstrap guest redirect; EditorialPreviewController and `resources/views/editorial/preview.blade.php`; web/console routes; PublishScheduledArticles command |
| Public metadata presentation | Existing article-hero component: optional localized alt text and supplied image credit only; no public CSS/theme change |
| Tests and docs | New EditorialWorkflowTest, EditorialCmsTest, EditorialMediaTest; README and architecture notes |

Full verification: **291 tests / 10,867 assertions**, zero failures/errors. This includes all 237 previous regressions plus service workflows, native Filament form saves/actions, role/scoping protection, safe bulk trash/restore, localized slugs, historical Armenian preservation, media validation, eight-language private previews, scheduled idempotency, repair of invalid legacy publication dates, cache/market components and query-count checks. Pint, production build, Blade compilation, route listing, migration status and scheduler inspection passed. Local HTTP checks returned 200 for all sixteen homepage/listing editions and the admin login, and confirmed unauthenticated article-resource/preview redirects to that login. Market mode remains DEMO.

```powershell
php artisan test --compact tests/Feature/EditorialWorkflowTest.php tests/Feature/EditorialCmsTest.php tests/Feature/EditorialMediaTest.php
php artisan test
php vendor/bin/pint --dirty --format agent
php artisan view:cache --no-interaction
php artisan view:clear --no-interaction
npm run build
php artisan route:list
php artisan schedule:list
```

Authenticated native Livewire flows are covered automatically with isolated SQLite users and HTTP fakes. Chrome navigation and screenshot APIs failed, so browser visual/drag-and-drop/editor interaction verification was not performed; no default account was created for it. MySQL and actual production multi-worker scheduling still require deployment acceptance. Approval and scheduling do not implement notifications, revision history/diffs or a per-translation publication state. Shared public media is intentionally not automatically purged.

Phase 05 is complete. Phase 06 has not started.

## Phase 06 reader community

### Accounts, verification and local preview

The existing User table and `web` session guard now serve both public readers and Filament. No dependency was added or upgraded. Registration explicitly assigns `is_admin=false` and `is_editor=false`, rejects protected role/account fields and sends a queued verification notification. Administrator/editor access, `app:create-admin` and the existing panel login remain intact. No real accounts, passwords, comments, likes or reports were seeded during implementation.

Replace `en` with any of `en, ru, fr, de, es, it, ja, zh`:

| URL | Purpose |
| --- | --- |
| `/en/register` | Reader registration; real display name, email and confirmed strong password |
| `/en/login` | Reader login, with optional remember-me |
| `/en/forgot-password` | Request a password-reset email; generic response for known/unknown addresses |
| `/en/reset-password/{token}?email=...` | Single-use password-broker reset link from the email |
| `/en/verify-email` | Signed email-verification instructions/resend for an authenticated account |
| `/en/account` | Private profile, password, preferences and comment history |
| `/en/account?tab=liked` | Private list of liked comments still available publicly |
| `/en/news/demo-bitcoin-long-view-en#comments` | Existing local English demo story with its discussion |
| `/admin/comments` | Administrator moderation queue |
| `/admin/comment-reports` | Administrator report review |

Start from the existing workspace in VS Code using `.\scripts\composer.ps1 run dev`; its server, database queue worker, Vite and scheduler terminals remain the established local workflow. Alternatively run `php artisan serve --host=127.0.0.1 --port=8000`, `php artisan queue:work --tries=3 --timeout=60`, `npm run dev` and `php artisan schedule:work` in separate terminals. Do not start duplicate copies of already-running services. Restart long-running workers after deploying changed application code; restarting the combined dev script restarts its workers too.

Local `MAIL_MAILER=log` is retained. With a queue worker running, verification/reset emails appear in `storage/logs/laravel.log`, not a real inbox. Use your own registered account and follow its logged link; keep these logs private because they contain authentication links. Verification requires the matching authenticated account, email hash and a valid 60-minute relative signed URL. Password resets use Laravel's existing hashed-token broker, expiration/throttle and single-use behavior. Notification URLs use trusted `APP_URL`, not a supplied Host header. Password-reset notification jobs are encrypted at rest in the queue. No newsletter, marketing email or external mail provider was enabled.

### Private profile and permissions

Profiles support display name, validated avatar, email, preferred email language, password changes and optional reply notifications. Email changes require the current password, revoke email verification, rotate the remember token, invalidate other database sessions and queue a new verification email. Password changes require the current password; password reset/change rotates remember tokens and removes other database sessions. Passwords require 12–64 characters with mixed case, a number and a symbol. Profile requests can only change the current account's allowlisted fields; IDs, roles, verification state, suspension and storage paths are protected.

Avatars accept JPEG/PNG/WebP only, up to 2 MB and 2048×2048 pixels, with generated UUID filenames in the existing public disk's `avatars` directory. Failed writes are validation errors rather than reported success. Replacing an avatar retains the old file; public-disk assets are public and should not contain confidential material. There is no public user directory or public email/profile page. Public comments load only author ID, display name and avatar path, never the email address.

| Action | Guest | Reader / Editor / Administrator on public pages |
| --- | --- | --- |
| Read approved localized comments | Yes | Yes |
| Submit/reply | No | Verified email and active community access required |
| Like/unlike or report | No | Authenticated and active community access required |
| Edit own comment | No | Verified, active, Pending/Approved and within 15 minutes |
| Remove own comment | No | Own active comments, including from private account history |
| Moderate other comments/reports | No | Administrators only; editors receive no new moderation privilege |
| Access Filament | No | Existing administrator/editor rules; readers remain denied |

Community suspension is a separate administrator action under Users. It blocks new comments, edits, likes and reports without modifying editorial roles or deleting existing contributions. Suspended users can still access their private account and remove their own comments. Restoring access is explicit. Suspension/restoration is audited with the affected account ID. Administrators cannot suspend themselves through that action.

### Comments, replies, likes and reports

Discussion appears after the public article and before related stories, using scoped ivory/black/serif/red styles and thin separators. Each thread belongs to one real article translation locale. There is no automatic comment translation. All eight UI catalogs include community controls and notifications; localized validation supplements the seven non-English catalogs while Laravel's original English validation/CLI messages remain intact. Historical Armenian rows and the public locale registry are untouched.

Only due Published articles with an actual valid supported translation can render or accept discussion. These checks run again for Livewire requests; locked article/locale identifiers cannot be reassigned by a client. Draft previews contain no discussion component. Pending/rejected/spam comments and removed text are not public. New comments default to Pending; editing an eligible comment returns it to Pending regardless of its previous approval. The optional future auto-approval flag is centralized in `config/community.php` and remains disabled (`require_approval=true`).

Comments are trimmed plain text, maximum 2,000 characters, with HTML markup/control characters rejected and output escaped. Roots paginate by ten with newest/oldest/most-liked sorting and stable ID ordering. Replies may target only an approved, active top-level parent on the same article and locale. The first three approved replies are eagerly loaded per visible root; expanding one thread paginates its replies by ten, oldest first. A removed/non-public parent becomes a neutral placeholder if approved replies remain. No deeper nesting or duplicate thread/reply rendering is allowed.

AJAX likes toggle through Livewire, using a unique `(comment_id, user_id)` constraint and a locked comment transaction. Counts are real aggregates. Reports use Spam, Scam/phishing, Harassment, Misleading promotion or Other; one active report per account/comment is allowed. After administrator resolution, the same report row can be reopened with a new reason. One report never automatically changes publication or removes content. There are no fabricated engagement counts or external API calls per page/comment.

Administrators use **Editorial → Comments** to inspect body, author, article, locale, reports and recent audit events; search/filter/paginate; approve, reject, mark spam, remove or restore; and apply authorized bulk actions. Comment removal is soft deletion. Restore returns Pending and requires a separate approval to become public. Likes/reports/replies are retained. Reject/spam/remove resolves open reports; independent **Comment Reports** actions can resolve a report without removing content or mark its comment as spam. State changes are transactional and idempotent. Audit events record actor, action, previous/new state and time without copying bodies/emails. No irreversible deletion action is exposed.

Optional reply emails default off. A verified reader can opt in under Account. Only approval of a public reply to their active approved parent triggers a queued notification, at most once per reply; self-replies and opted-out/unverified/suspended recipients are skipped. Delivery rechecks visibility and preferences. No email is sent merely because a pending reply was submitted.

### Abuse prevention and response privacy

Every mutation uses server-side validation/authorization and normal Laravel CSRF protection. Registration/comment forms include honeypots. Duplicate normalized comment submissions by the same account/article/locale/parent are rejected, including retained trash, with a database fingerprint constraint. Limits use the existing Laravel cache store:

| Operation | Limit |
| --- | --- |
| Registration | 3/hour per IP |
| Login | 5/minute per email+IP, plus 20/minute per IP |
| Forgot password | 3/15 minutes per IP; broker additionally throttles recipients |
| Password reset | 5/15 minutes per IP |
| Verification resend | 3/15 minutes per account |
| Verification link | 6/minute route throttle |
| Profile update | 10/minute per account |
| Password change | 5/15 minutes per account |
| Comment create/edit attempts | 3/minute and 30/hour per account, plus 15/minute per IP |
| Like toggles | 30/minute per account |
| Reports | 5/hour per account |

Attempt limits also count invalid submissions; readers sharing an IP may share its quota. Use a shared cache and correct trusted-proxy/HTTPS configuration in deployment; local defaults remain unchanged. No paid CAPTCHA or autonomous moderation was added.

Authentication/account pages use noindex/nofollow/noarchive headers/meta, private/no-store caching and no-referrer. Public article/comment responses, authenticated public responses and Livewire responses are private/no-store to keep CSRF tokens, account state and reaction state out of shared page caches. Existing server-side market snapshot caching is unchanged; do not add a shared full-page cache over these responses. Public article SEO output remains unchanged. Authentication/profile URLs never enter public sitemaps, JSON-LD or hreflang metadata; reset tokens are not copied into language-switch links.

### Schema, files and verification

One additive migration, `2026_11_17_074605_add_reader_community_foundation.php`, adds nullable avatar/suspension fields and safe locale/notification defaults to Users, plus Comments, Comment Likes, Comment Reports and Comment Moderation Events. It uses portable schema-builder foreign keys/indexes/unique constraints for SQLite/MySQL 8+. Existing user identity/role/authentication columns and all content tables are retained. Raw hard-deletion foreign keys protect integrity; CMS removal uses soft deletion and preserves relationships.

Local SQLite migration succeeded after `.tools/phase06-before-20261117.sqlite`, an ignored consistent backup. Comparing all original business-table columns/rows afterward showed no changes to users, articles, article/category translations (including Armenian), tags, links, editorial settings or prior events. Integrity and foreign-key checks passed. No migrate:fresh, real account creation or demo community seeding was run; generated factories support isolated tests and generated seeders are intentionally empty.

| Area | Created / modified files |
| --- | --- |
| Accounts | User; ReaderAccounts; ReaderAuthController; ReaderAccountController; bootstrap/app.php; PrivateCommunityPage; routes/web.php |
| Community domain | CommentStatus; Comment/CommentLike/CommentReport/CommentModerationEvent models and factories/empty seeders; Article comments relationship; two policies; CommunityComments; CommunityLimits; config/community.php; additive migration |
| Notifications | VerifyReaderEmail, ResetReaderPassword, CommentReplyApproved |
| Public UI | ArticleComments Livewire class; community/livewire Blade views; feedback component; scoped community.css/app.css import; article/layout/header additions; FrontendLinks |
| Moderation | Comments and CommentReports Filament resources/pages; Users suspension controls; EditorialUsers email re-verification handling |
| Languages/tests/docs | Eight community catalogs, seven localized validation catalogs; ReaderAuthenticationTest, ReaderAccountTest, CommunityCommentsTest, CommunityModerationTest; ArticleSeoTest now distinguishes required external Livewire JS from strictly checked inline JSON-LD; README and architecture guide |

```powershell
php artisan test
php vendor/bin/pint --dirty --format agent
php artisan view:cache --no-interaction
php artisan view:clear --no-interaction
npm run build
php artisan route:list
php artisan schedule:list
```

Final verification: **360 tests / 13,068 assertions**, zero failures/errors/skips, including all 291 previous tests and 69 community cases. Production build, Pint, Blade compilation, migration status, full route listing and scheduler inspection passed. Forty local homepage/listing/authentication HTTP checks passed across all eight languages; account guest redirects, the admin login, an existing article discussion and its Livewire runtime asset also passed. Market data remains DEMO and local mail remains log-only.

Browser navigation and screenshot APIs failed, so desktop/mobile visual fidelity and actual browser interaction were not verified. Native Filament/Livewire forms/actions, moderation, pagination and security were exercised against isolated SQLite databases. MySQL, real SMTP deliverability and production multi-worker contention require deployment acceptance. Account deletion/export, comment revision history, attachments, deeper threads and automatic abuse classification are outside this phase. Public uploads/old files are intentionally retained; no storage garbage collection was introduced.

Implementation references: [Laravel 13 authentication](https://laravel.com/framework/docs/13.x/authentication), [email verification](https://laravel.com/framework/docs/13.x/verification), [password resets](https://laravel.com/framework/docs/13.x/passwords), [Livewire 4 security](https://livewire.laravel.com/docs/4.x/security) and [Filament 5 actions](https://filamentphp.com/docs/5.x/actions/overview).

Phase 06 is complete. Phase 07 has not started.

## Phase 07 news discovery

### Safe startup and offline demonstration

The installed Laravel 13.42.0 / PHP 8.4 / Filament 5.11.2 / Livewire 4.4.8 stack is retained. No dependency was added/upgraded, no publisher source was seeded and no real publisher or AI endpoint was contacted. Discovery starts **disabled**, with zero sources/candidates/runs. AI generation and automatic publishing are hard-disabled in configuration and in the `AiDrafting` entry point, even if a caller tampers with configuration. Market mode remains DEMO and mail remains log-only.

From the VS Code workspace root, the existing `scripts/composer.ps1 run dev` workflow still starts the server, queue, Vite and scheduler. Separate terminals can instead run `php artisan serve --host=127.0.0.1 --port=8000`, `php artisan queue:work --tries=3 --timeout=60`, `npm run dev` and `php artisan schedule:work`. Do not start duplicate services already running. Long-running queue/scheduler processes should be restarted after deploying changed code; restarting the combined dev script restarts those processes too.

Run the deterministic demonstration without enabling anything:

```powershell
php artisan news:demo --no-interaction
php artisan news:status --no-interaction
php artisan news:discover --no-interaction
```

`news:demo` reads the clearly fictional local RSS fixture, shows normalized headlines/languages/rule topics/snippets, makes no HTTP calls and writes no sources, candidates or Articles. It may read existing category labels. `news:status` is read-only; its accounting columns distinguish reserved estimates from recorded actual amounts. With discovery disabled, `news:discover` queues zero work. The Atom fixture also lives in `tests/Fixtures`; both fixtures are development/test metadata and are never published.

### Sources and operator permissions

Use an existing administrator at <http://127.0.0.1:8000/admin>; if needed, the unchanged interactive `php artisan app:create-admin` command creates an administrator with a hidden confirmed password. No default account/password was added.

| Admin destination | Administrator | Editor | Reader |
| --- | --- | --- | --- |
| `/admin/news-sources` | Add/edit/approve/enable/disable; queue due fetch | Denied | Denied |
| `/admin/news-candidates` | View and review | Explicit candidate-review permission | Denied |
| `/admin/ingestion-runs` | View and retry eligible failures | View sanitized run history | Denied |
| `/admin/ai-news-control-center` | Real statistics, settings and manual dispatch | Read-only statistics/settings | Denied |

The resources sit under the existing Editorial navigation group. No public route, role flag, Filament authentication rule, community feature or public design was replaced. Candidate review permission is explicitly defined for existing editorial users; it does not grant source management, spending or publication permissions.

Before adding a source, verify its official publisher RSS/Atom endpoint and permitted use. Enter publisher website separately from feed URL, source type, original language, optional existing editorial category/topic hint, priority (1 highest, 5 lowest), polling interval, item cap and usage/attribution notes. Confirm approval against the current administrator. Endpoints must resolve publicly over HTTPS, use no embedded credentials and require no redirect/paywall bypass. A changed endpoint requires a fresh successful safety check; edits invalidate queued configurations. Sources can be disabled immediately using the dedicated action even during a DNS/provider outage. Source deletion is not exposed, preserving provenance.

Summary storage defaults off. Enable it only when documented publisher rights permit a snippet. At most 1,000 plain-text characters are retained; full article bodies, attachments, images and RSS `content:encoded` are not imported. Source names, licensing notes and external metadata are displayed as escaped text. This phase does not acquire or verify publisher licenses automatically.

Once approved sources exist, explicitly enable discovery in the control center. Defaults are 60-minute source polling, at most three sources per dispatch and 25 entries per source run. Administrators can configure 15–1,440-minute polling, 1–5 sources per dispatch and 1–100 entries per source run; per-source item caps also apply. Priority only affects polling/review context, never automatic editorial approval. The control center uses real source/candidate/run data and displays no invented AI analytics or costs.

### Ingestion, normalization and duplicates

`NewsDiscovery` reserves a due source/run transactionally, creates a unique queued `IngestNewsSource` job and stores a lease/token/configuration version. A worker rechecks discovery state, current source approval, administrator authority and source version before requesting a feed and before committing candidates. Disabling discovery/source while queued prevents requests; changes during a request prevent its result from being committed. Publisher conditional ETag/Last-Modified requests are supported; 304 records a successful fetch without creating candidates.

The parser accepts UTF-8 RSS 2/RDF-style items and Atom entries, bounds document structure/items and extracts only metadata. Normalization validates absolute HTTPS article links against the approved publisher/feed hosts, preserves the original language and real publication time, strips unsafe markup/control characters, and retains a snippet only with permission. Missing, invalid, relative or future timestamps stay null with an explicit review warning; discovery time is a separate genuine timestamp. Relative article URLs, arbitrary cross-publisher redirects, compressed/non-XML responses and non-UTF-8 feeds are deliberately unsupported in this phase.

Classification uses explainable keyword/source rules for 21 Crypto, Metals and Global Markets topics. Matched terms, method and uncertainty are stored. It reuses an existing matching editorial category where available and never seeds duplicate categories. A source-assigned topic remains a hint requiring review; ambiguous or unknown topics do not become invented confident classifications. No AI classification or automatic translation occurs. Original-language metadata can include non-public language tags without adding public locales.

Duplicate handling is conservative:

- A source/external-ID hash and a global normalized URL hash prevent repeated candidate creation. Missing GUIDs fall back to normalized URL. Tracking parameters/fragments are removed, meaningful query values remain, and duplicate query-key order is preserved.
- A shared URL attaches another `CandidateSourceReference`, retaining source, original headline/URL/language, external ID and first/last-seen times.
- Normalized headline and stable metadata fingerprints flag a separate candidate as a possible duplicate rather than merging different URLs. Short/generic headlines are not exact-match signals.
- Bounded same-language similarity checks require at least eight shared words, at least 90% token overlap, matching numbers and publication times within a day. Merely mentioning the same asset does not create a duplicate.

Database uniqueness, a shared candidate-store lock and transactional writes provide idempotency across repeated and concurrent imports. The whole candidate-save batch rolls back on a storage failure. Unusable individual entries are skipped with counted error codes; raw feeds and provider error bodies are not retained.

### Candidate review and future drafting

The separate states are New, Review Required, Approved for Draft, Rejected, Duplicate and Processing Failed. Candidates never enter public Articles or sitemaps. Editors/admins can inspect original references, licensed snippets, classification evidence, duplicate warnings and ingestion errors, then approve for future drafting, reject, return to review or link a duplicate. Duplicate links point to an earlier candidate to avoid cycles; overriding a warning for drafting requires an explanation. Native modal validation displays errors against the relevant field.

`CandidateReviewService` authorizes fresh editorial users, locks records and writes minimal review audit events. Approval for Draft is **not** article approval, and creates no Article, translation, queue request to an AI provider or publication. Review transitions are idempotent. Existing ArticleEditor/ArticlePublication, SEO, media and scheduling services remain the only future editorial creation/publication boundaries.

### Queue, polling and security limits

The scheduler checks `news:discover` every fifteen minutes with overlap protection and an enabled-state condition. The source's own due time still governs fetching. Dispatcher/source/host/candidate locks reuse the existing cache; production discovery rejects non-database/non-Redis stores, and multiple nodes must share that selected store. Global manual dispatch/retry batches are at least 60 seconds apart; publisher hosts are also limited to one request per 60 seconds. Source leases prevent one queued job per repeated click from becoming many requests. No discovery work runs on public requests.

Jobs use the existing database/default queue, a 45-second timeout and one queue execution attempt. Failed ingestion is recorded in its run; an explicit authorized retry uses the same run, a new token, cooldown and at most three total reserved attempts. There are no hidden HTTP retries. Polling pauses automatically after three consecutive failures until operator intervention; manual operations retain source cooldowns. HTTP 429/503 Retry-After delays of up to seven days are honored; longer deferrals disable that source pending operator review rather than retrying early. Expired leases are recoverable on a later due poll; stale jobs/tokens cannot commit or fetch new work.

```powershell
# Queue a bounded batch of due approved sources, only after explicit enablement:
php artisan news:discover --no-interaction
# Restrict the trusted console operation to a single due source:
php artisan news:discover --source=1 --no-interaction
# Retry only an eligible failure as a real current administrator:
php artisan news:retry 12 --admin=3 --no-interaction
php artisan news:status --no-interaction
php artisan schedule:list --no-interaction
```

IDs above are examples; use real IDs from your panel. Console commands are trusted operator entry points. Manual commands do not bypass disabled discovery, approval, intervals, host limits or attempt caps. Changing settings does not start a scheduler/worker by itself.

Network safeguards include strict URL/host/IP validation, all-answer DNS checks, cURL address pinning, TLS verification, disabled proxies/redirects, 3-second connect/12-second request limits, bounded response headers/body handling, a 1 MiB streaming sink, XML type/encoding checks, no DTD/entities/external resolution and bounded XML depth/elements. HTTP errors are short allowlisted codes; raw payloads/credentials are not logged. Installed Guzzle 8.5/Psr7 3.4 request-option compatibility is tested by constructing its real cURL transfer without executing it. DNS/HTTP results in tests are deterministic fakes; real publisher transport acceptance remains an operator task. PHP's OS DNS resolver can have platform-specific latency. Windows lacks pcntl, so production hard worker timeouts require a suitable worker platform; explicit HTTP/body/parser limits still apply locally.

### AI provider and budget boundary for Phase 08

`AiDraftProvider` is an unbound typed contract only. `AiDrafting::isOperational()` always returns false, and `generate()` throws without calling a provider. `config/ai.php` keeps generation/automatic publication hard-disabled; its provider/model choices, emergency stop, integer micro-USD daily/monthly caps, daily article allowance and maximum translation-language count are server-side configuration. Defaults are provider `none`, empty model, emergency stop on and zero allowances. `.env.example` documents non-secret settings; `.env` and credentials were not changed.

`AiBudgetLedger` is a database accounting boundary for future trusted work. It requires administrator authority, an Approved-for-Draft candidate, a unique request UUID, positive estimate, cleared emergency stop and available daily/monthly/article allowances. Ordered period locks and transactions reserve estimates without recording them as actual charges. Settlement records actual micro-USD/token usage separately; release cancels a reservation. Settlement/release are idempotent and cannot rewrite finalized accounting. Actual overruns are recorded honestly and block further reservations through the configured totals. One USD is 1,000,000 micro-USD; no floating-point currency calculations are used.

Reservations remain held until explicitly settled/released; they are not silently expired. An existing finalized idempotency key does not authorize another provider execution. Phase 08 must validate provider responses/usage, enforce request/token/language ceilings before paid calls, reconcile actual charges and integrate drafts through existing CMS services. This ledger is not a provider billing verifier or permission for autonomous publication. No real reservations, spending, generation, summaries or translations were produced in Phase 07.

### Migration, files and verification

`2026_11_17_082408_add_news_discovery_foundation.php` adds eight tables: news_sources, news_candidates, candidate_source_references, ingestion_runs, candidate_reviews, discovery_settings, ai_budget_periods and ai_spend_reservations. It changes no existing table. Portable foreign keys, unique hashes/keys and indexes support SQLite and MySQL 8+. Local SQLite migration succeeded after a consistent ignored `.tools/phase07-before-20261117.sqlite` backup; no migrate:fresh or real seed was run. Generated factories support isolated tests; generated seeders intentionally create nothing.

| Area | Created / modified files |
| --- | --- |
| Domain/schema | Eight models/factories/empty seeders above; CandidateStatus/IngestionStatus; additive migration |
| Feeds/security | FeedFailure, FeedDnsResolver, FeedUrlSafety, FeedHttpClient, FeedParser, NewsNormalizer |
| Discovery/review | NewsClassifier, NewsCandidateStore, NewsSourceManager, NewsDiscovery, CandidateReviewService, DiscoverySettingsManager; three policies |
| Jobs/commands/config | IngestNewsSource; DiscoverNews, RetryNewsIngestion, NewsDiscoveryStatus, DemoNewsDiscovery; discovery/ai configuration; console schedule; non-secret .env.example defaults |
| Filament | NewsSources/NewsCandidates/IngestionRuns resources and pages; AiNewsControlCenter and its Blade view; EditorialForm action-field error mapping with existing page behavior preserved |
| Future AI accounting | AiDraftProvider interface, non-operational AiDrafting, AiBudgetLedger |
| Fixtures/tests/docs | Two offline XML fixtures; FeedSecurityTest, FeedParsingTest, NewsDiscoveryTest, DiscoveryAdministrationTest, AiBudgetSafetyTest; README and architecture guide |

```powershell
php artisan test
php vendor/bin/pint --dirty --format agent
php artisan view:cache --no-interaction
php artisan view:clear --no-interaction
npm run build
php artisan route:list
php artisan schedule:list
```

Final verification: **461 tests / 13,478 assertions**, zero failures/errors/skips, including all 360 previous tests and 101 new discovery/security/accounting cases. Production build, Pint, Blade compilation, full route listing, migration status and scheduler inspection passed. Sixteen local public homepage/listing HTTP checks passed across eight locales; all four new admin destinations correctly redirect guests to the existing admin login. Native authenticated administrator/editor forms, actions and validation errors were exercised automatically.

Every original business-table column/row still matches the pre-migration backup, including articles, historical Armenian translations, users, taxonomy, community and editorial records. SQLite integrity/foreign-key checks passed. All eight new tables remain empty locally: no approved sources, candidates, runs, settings or financial reservations were fabricated. Discovery stays disabled, AI generation stays unavailable and market mode stays DEMO.

No live publisher/DNS/provider/license acceptance test or real MySQL/multiple-worker stress test was performed. Native Filament/Livewire workflows are covered in isolated SQLite tests; browser navigation failed, so visual inspection was not performed. Summary rights and source notes require human review; source policy history is not a complete versioned licensing archive. Cross-language semantic duplication, relative-link/encoding conversion, vector search, scraping and AI drafting remain outside this phase.

Implementation references: [Laravel 13 HTTP client](https://laravel.com/framework/docs/13.x/http-client), [queues and shared locks](https://laravel.com/framework/docs/13.x/queues), [PHP XML safety flags](https://www.php.net/manual/en/libxml.constants.php) and the installed Filament/Guzzle package APIs. No vendor code or dependency manifest was changed.

Phase 07 is complete. Phase 08 has not started.

## Phase 08 AI-assisted editorial pipeline

Phase 08 adds a working **OpenAI Responses API adapter**, an offline fake provider, private English drafting, human verification and seven independent translation drafts. Paid generation and scheduling remain stopped. Automatic publishing is unavailable and has no implementation. No additional dependency was installed.

### Safe defaults and provider configuration

The website chooses its API model through server configuration and administrator controls. The model used by Codex does not choose the website's model. Use a Responses-compatible model that supports strict structured outputs and is available in your own OpenAI account. No model is selected by default; unsupported account/model/schema combinations fail closed.

The following settings are in `.env.example`; credentials belong only in your ignored `.env` or production secret store:

```dotenv
AI_PROVIDER=openai
AI_MODEL=
OPENAI_API_KEY= # Leave empty until separately approved; inject securely.
AI_EMERGENCY_STOP=true
AI_CANDIDATE_PROCESSING_ENABLED=false
AI_GENERATION_ENABLED=false
AI_TRANSLATION_ENABLED=false
AI_SCHEDULED_GENERATION_ENABLED=false
AI_DAILY_LIMIT_MICROUSD=0
AI_MONTHLY_LIMIT_MICROUSD=0
AI_MAX_ARTICLES_PER_DAY=0
AI_MAX_TRANSLATION_LANGUAGES=0
AI_MAX_CONCURRENT_JOBS=1
AI_REQUEST_TIMEOUT=30
AI_MAX_INPUT_TOKENS=32000
AI_MAX_OUTPUT_TOKENS=5000
AI_PRICING_MODEL=
AI_PRICING_VERIFIED_AT=
AI_INPUT_MICROUSD_PER_MILLION=0
AI_CACHED_INPUT_MICROUSD_PER_MILLION=0
AI_CACHE_WRITE_MICROUSD_PER_MILLION=0
AI_OUTPUT_MICROUSD_PER_MILLION=0
```

Budgets use integer micro-USD: **1,000,000 = USD 1**. Prices use integer micro-USD **per million tokens**. For example, USD 0.75 per million input tokens becomes `750000`, not `0.75`. Price/model/date must match the chosen model; verified pricing expires after 30 days. Set cache-write pricing when your provider/model reports billable cache writes; otherwise nonzero write usage is treated as uncertain. Review account-specific pricing, service tier and context limits rather than copying a permanently assumed rate.

In **Admin → Editorial → AI News Control Center**, configure the matching model, actual editorial author, processing/generation/translation/scheduling flags, budgets, article allowance, language limit and concurrency. Each enable flag requires both its server flag and its saved administrator switch. Either emergency stop blocks new paid requests. Effective spending/article/language/concurrency limits use the lower of server and administrator values. Saving UI settings cannot add credentials or authorize automatic publishing. Provider readiness is a local configuration check, not proof of external API acceptance.

### Editor workflow

1. An administrator records source permissions in News Sources: separate AI-processing rights, licensing/attribution notes, official-primary status and a lowercase publisher ownership group. Two brands owned by the same group are not independent corroboration.
2. Discovery continues to import permitted metadata without scraping full articles. Original source publication time and retrieval time are retained on each reference.
3. In Verified Evidence, an editor inspects the original permitted sources and writes independently worded factual claims, supported entities/symbols, exact numerical strings, reference IDs and verification notes. Each save requires explicit confirmation and records the real verifier. Headlines alone are rejected.
4. Generation requires an explicitly approved-for-draft candidate, 2–12 verified claims (at least 160 total characters), eligible recent dated sources, and either an official primary source or independent corroboration for material claims. Evidence older than 168 hours, missing dates/rights, duplicate warnings without an explained override, disabled sources and changed evidence block processing.
5. An administrator selects an actual editorial author and queues English drafting. An optional explicit delegation permits that author to request future regeneration under the authorizing administrator's limits. No AI account or privileged service identity is created.
6. A bounded job reserves estimated cost, sends one structured request, records usage, validates the versioned schema, screens quality and saves a standard **Articles** draft through the existing ArticleEditor and ArticlePublication services. Successful English drafts enter Pending Review; candidate approval never becomes publication approval.
7. In Articles, inspect the provenance, sources, reports, private preview and saved text. **Verify / reject AI draft** requires a human factual review and meaningful notes. Numbers, currencies, dates, entities, quotations, conflicting information, permissions and attribution must be checked against sources. Deterministic screening is not semantic fact verification.
8. Only a currently reviewed English draft can generate ru, fr, de, es, it, ja and zh drafts. Each language is queued, stored, reviewed and regenerated independently. A failed language preserves successful drafts. Human-edited translations require explicit overwrite confirmation; edits made after queueing are protected even when an earlier overwrite was authorized.
9. Submit/approve/schedule/publish through the existing CMS permissions. Only administrators can publish or schedule, and current English human verification is mandatory. Pending/rejected translations remain private and are omitted from article alternates, listings, comment eligibility and XML sitemaps. Published AI-assisted articles display a localized disclosure.
10. Section regeneration produces a private proposal in Generation Runs. Applying a reviewed proposal needs separate confirmation and an unchanged English source. Human edits reset the affected review; English changes invalidate all public-language translation reviews. Historical Armenian records are retained.

All eight public locales continue to work. Legacy articles without pipeline provenance keep their existing publication behavior.

### Offline demonstration and cost estimate

```powershell
php artisan ai:demo --no-interaction
php artisan ai:estimate --model="YOUR_ACCOUNT_MODEL" --input=6000 --output=2000 --translation-input=3500 --translation-output=2500 --languages=7 --articles=100 --no-interaction
```

`ai:demo` requires local/testing plus SQLite and creates a **new isolated** `.tools/ai-demo-UUID/preview.sqlite`. It runs fictional English generation, a clearly simulated human review, all seven translations and review simulations. Eight UTF-8 `en.html` … `zh.html` private previews are written beside that database, marked noindex. Generated stories and usage are labeled SIMULATED, never billable, and cannot be published. No main-database accounts, articles, sources or usage records are seeded. No external request or credential is needed. These private files persist until you choose to remove them.

`ai:estimate` requires verified model-specific rates but needs no API key or generation activation. It performs no network call. Workload tokens include prompts/evidence/schema/context overhead; actual reasoning/output usage and retries can differ.

For an illustrative standard-rate example verified **2026-11-17**, [OpenAI pricing](https://developers.openai.com/api/docs/pricing) lists gpt-5.4-mini at USD 0.75 input, USD 0.075 cached input and USD 4.50 output per million tokens. English at 6,000 input/2,000 output plus seven translations at 3,500 input/2,500 output each totals **30,500 input + 19,500 output tokens**, approximately **USD 0.110625 per eight-language article / USD 11.0625 per 100** before retries, taxes, tools or cache discounts. This is a calculation, not actual spending, a model recommendation or a default configuration.

### Accounting, queues and recovery

The existing transactional AiBudgetLedger remains authoritative. UTC day/month rows and idempotent reservations protect paid allowance. Reservations use conservative input-byte bounds including schema overhead and the configured output ceiling. Known usage is settled at the **snapshotted configured rates**, separately recording total input, cached input, cache writes and output tokens. English generation counts toward the daily article allowance; translations and section proposals consume spending budget without counting as new articles. Simulated usage is stored separately and excluded from paid statistics.

Each provider request has a unique run/reservation and a durable request-sent marker. Shared per-run, admission and concurrency locks prevent duplicate execution. Short database transactions surround state/accounting changes; HTTP runs outside them. Provider timeouts are capped at 45 seconds; jobs have one execution attempt and a 75-second timeout. Shared locks and job uniqueness use 90 seconds. Production needs shared database/Redis locks and a queue retry interval longer than worker/job timeouts; use at least the existing 90 seconds and re-evaluate timing for your infrastructure.

- Completed reliable usage is recorded even for refusals, incomplete generation or invalid model output; such responses may still be billed.
- Known permanent credential/configuration/schema/quality/budget failures are not retried automatically.
- Explicit 429 retries honor Retry-After (seconds or HTTP date), exponential backoff and a three-attempt chain. Use Generation Runs → Retry, or `php artisan ai:retry RUN_ID --admin=EXISTING_ADMIN_ID --no-interaction`. Only eligible rate-limited or unsent queue failures qualify; no hidden HTTP retry occurs.
- Timeouts, 5xx/unknown responses and unreliable usage produce **uncertain_cost**. Funds remain held; no automatic expiry, zero-cost assumption or paid retry occurs. Compare provider records, then use the administrator-only reconciliation action with verified usage and notes. Reconciliation does not resend the request.
- A saved response survives a worker interruption and can resume validation/storage without another paid call. Generation Runs → Resume saved stage handles this. Source, ownership and content hashes are checked again.
- `ai:dispatch` resumes up to 20 pending stages and audits old requesting states. New scheduled English work requires both scheduling flags, a real administrator/author and full generation readiness. It never republishes or automatically translates/publishes content, and never retries failed/uncertain candidates on its own.

Application reservations are preventive controls, **not a guaranteed external billing cap**, especially for requests whose final outcome is unknown. Set provider-account budgets/alerts separately, reconcile billing and investigate any actual usage exceeding a reservation.

For local startup in PowerShell:

```powershell
.\scripts\composer.ps1 run dev
```

The development script now consumes both `default` and `ai` queues. Existing terminals continue with their old command until restarted by the operator. For separate terminals:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work --queue=default,ai --tries=1 --timeout=85
php artisan schedule:work
```

Windows cannot enforce POSIX worker signals/timeouts in the same way as Linux. Production workers need supervised Linux processes, shared cache/queue storage, private logs/backups, HTTPS, and graceful worker restart on deployment. Keep HTTP timeout < AI job timeout < queue retry interval.

### Stop immediately and activate deliberately

To stop new paid calls immediately, click **Stop all new AI requests** in the Control Center. This database stop is checked at execution time, including immediately before a provider request. Alternatively set `AI_EMERGENCY_STOP=true`, refresh cached configuration and restart long-lived workers. Stopping cannot recall a provider request already in flight. Saved drafts remain intact; already received responses may finish private validation/storage.

Production activation is an operator action, not performed by this phase:

1. Back up the database and APP_KEY, run additive migrations, install from existing lockfiles, build assets and run the full test suite. Maintain encrypted run records and restricted backups.
2. Establish publisher AI-processing rights and attribution, source timestamps, reliable evidence verification and human editorial responsibility.
3. Choose an account-accessible model, verify its Responses/structured-output support, set the server-only key and exact current model prices/date. Confirm prompt/context/output limits, API account retention and billing policies.
4. Configure small nonzero server and Control Center budgets, article/language/concurrency limits, actual author and administrator. Set only the needed enable flags in **both** locations; clear emergency stops only when intentionally ready. Leave scheduling off during initial verification; automatic publishing remains unavailable.
5. Start supervised `default,ai` workers and Laravel's scheduler with shared locks. Run one explicitly authorized test candidate and verify the actual response, sanitization, usage, provider bill and human review before increasing limits. No such real API acceptance test has been performed here.
6. Monitor failed/uncertain runs, reconciled costs, provider-account limits and source permissions. Check translations professionally and publish only through the existing administrator workflow.

### Phase 08 files and validation

| Area | Main files created or modified |
| --- | --- |
| Provider and gates | `config/ai.php`, `AiDraftProvider`, `OpenAiDraftProvider`, `FakeAiDraftProvider`, `AiSettings`, `AiPricing`, `AiFailure` |
| Pipeline and verification | `AiDrafting`, `AiEvidence`, `AiOutputSchema`, `AiQuality`, `AiEditorialReviewService`, `AiDemoFixtures`; `AiBudgetLedger` extensions |
| Persistence | `2026_11_17_092110_add_ai_editorial_pipeline.php`; AiPipelineSetting, CandidateEvidence, AiGenerationRun, AiEditorialReview models/factories/policies; safe settings seeder; existing article/source/candidate/reservation models |
| Jobs and commands | GenerateAiDraft, ValidateAiDraft, StoreAiDraft; DemoAiPipeline, EstimateAiCost, ScheduleAiDrafts, RetryAiDraft; console schedule and development queue script |
| CMS | Verified Evidence and Generation Runs resources; extended Control Center, News Sources/Candidates and standard Articles; `AiEditorialActions` |
| Public integrity | ArticleEditor/ArticlePublication, PublicUrl, Sitemap, minimal comment-visibility query; eight localized AI disclosures and article disclosure |
| Quality and setup | AiTestCase plus six new AI test classes; existing budget test updated for explicit Phase 08 activation; `.env.example`, `phpunit.xml`, README and architecture guide |

The expanded suite uses a **test-only 512 MB PHP limit** for repeated Filament/Livewire component rendering; production PHP configuration is unchanged. Tests force AI activation flags off and clear credentials, use isolated SQLite, HTTP fakes and fictional fixtures.

Final verification: **563 tests / 14,168 assertions**, zero failures/errors/skips, including the existing 461 tests and 102 new AI cases. Pint, Composer validation, production build, Blade compilation, route listing, migration status and scheduler checks pass. The full offline demonstration completes all eight languages. Sixteen running local homepage/listing HTTP checks return 200 with valid UTF-8; the three AI administration destinations redirect guests to the existing login. Native administrator/editor forms, review/regeneration actions, source/ownership checks, budget reservations, uncertain-cost reconciliation and recovery are exercised with authenticated Livewire and deterministic HTTP fakes.

Every original business row/column matches the consistent pre-migration backup across 23 tables; SQLite integrity and foreign-key checks pass. The four new live pipeline tables remain empty. Actual source discovery, paid generation, translation generation and scheduled AI generation remain disabled, the emergency stop remains active, and markets remain in DEMO mode. Existing user-owned development processes were left running; the updated queue command takes effect on the operator's next launch.

Official implementation references: [Responses API](https://developers.openai.com/api/reference/resources/responses/methods/create), [structured outputs](https://developers.openai.com/api/docs/guides/structured-outputs), [API quickstart](https://developers.openai.com/api/docs/quickstart), [pricing](https://developers.openai.com/api/docs/pricing).

No real API/model acceptance, provider billing reconciliation, external licensing review or MySQL/multiple-worker stress test was performed. Human factual and linguistic review remains necessary: numeric/entity/citation/script/similarity screens cannot prove semantic accuracy, independence or originality. The fake provider proves workflow behavior, not real model quality. Browser navigation and screenshot capture failed in the available browser bridge, so **visual verification was not performed**; authenticated Filament/Livewire workflows are covered by automated tests.

Historical Phase 08 boundary: paid services and automatic publishing remain stopped. The current Phase 09 implementation is documented next.

## Phase 09 — reader engagement

Phase 09 adds personal watchlists, saved news, LIVE-only price alerts, private notifications and opt-in editorial digests. The approved newspaper layout, all eight public languages (en/ru/fr/de/es/it/ja/zh), Armenian archive, market cache/providers and AI review gates are preserved. No subscriptions, watchlists, notifications or campaigns have been seeded into the application database. Markets remain DEMO; AI generation, newsletter scheduling and real email sending remain disabled.

### Reader pages

Sign in using the existing reader account. The account navigation now links to:

| Path | Use |
| --- | --- |
| /{locale}/account | Existing profile, password and comment history |
| /{locale}/account/watchlist | Add/remove/move supported assets; cached prices, changes and related published news |
| /{locale}/account/saved | Paginated saved articles, with saved-language preference and safe available-language fallback |
| /{locale}/account/alerts | Create/edit/pause/remove alerts and view genuine crossing history |
| /{locale}/account/notifications | Private notification history, mark read and channel preferences |
| /{locale}/account/newsletter | Confirmed subscription preferences and withdrawal |
| /{locale}/newsletter | Public guest/reader subscription form |

Use <http://127.0.0.1:8000/en/newsletter> for the public form or <http://127.0.0.1:8000/en/account/watchlist> after reader login. Replace en with any public locale. Account and newsletter/token pages are private/no-store and noindex/nofollow/noarchive. Public homepage/news/article SEO and the existing sitemap boundaries remain unchanged.

The watchlist uses the existing 25 instruments: BTC, ETH, SOL, BNB, XRP, ADA, DOGE, TRX, AVAX, LINK, TON, DOT, LTC, BCH, XLM, NEAR, UNI, SUI, APT and ETC; Gold/XAU, Silver/XAG, Platinum/XPT, Palladium/XPD and Copper/HG. The maximum is 30 unique assets per reader; today's catalog contains 25. Cryptocurrencies use USD/coin, the four precious metals USD/troy ounce, and copper USD/pound. Quotes come from the shared cache only; visits do not call external providers. DEMO labels and existing source attribution remain visible. Move-up/down forms work without JavaScript.

Article pages have a save action. A bookmark is unique per user/article, preserves the chosen language and never bypasses publication or translation review. If a saved story is unpublished or removed, the list shows an unavailable placeholder with a remove action. It does not disclose the private headline or body.

### Price alert behavior

Each reader may enable at most 20 alerts. Thresholds must be positive plain USD decimals, at most 10 fractional places and at most 1,000,000,000,000,000. Currency/unit derive from the existing catalog. Duplicate asset/unit/currency/direction/threshold combinations are rejected. Pausing or editing clears observation continuity; deleting soft-deletes the alert while retaining its triggered history. Recreating the same deleted combination restores that owned alert with a new version.

Evaluation requires **both** PRICE_ALERTS_ENABLED and the administrator control, MARKET_DATA_MODE=live, provider rights/readiness, a current validated cached quote, matching symbol/unit/currency and non-future timestamps. Freshness is the existing group interval plus 60 seconds. Delayed, stale, unavailable, fictional, invalid or mismatched quotes clear the baseline. The first fresh observation only establishes state. Above requires previous <= target and new > target; below requires previous >= target and new < target. Equal prices do not trigger. Repeated timestamps are ignored; changed/backward timestamps and gaps over twice the group interval plus 120 seconds break continuity. A later return across the threshold can rearm the alert.

Locked transitions create one immutable event per alert version/provider observation, plus an idempotent delivery outbox. Send-time checks repeat mode, freshness, current alert version, permissions and preferences, so queued work cannot deliver after switching back to DEMO or pausing. Observed provider floats are normalized to 10 decimal places; this does not increase the precision of the provider's original quote. Thresholds and stored observations use canonical decimal **strings** to avoid SQLite numeric affinity rounding.

### Subscription consent and digest selection

Subscription is optional and separate from registration. Guests and readers explicitly choose a language and daily/weekly frequency, and check an initially unchecked consent box. The endpoint returns the same generic response for eligible/repeated requests, limits IP/email attempts, uses a honeypot and applies a 30-minute confirmation cooldown.

A confirmation link contains a random 64-character token whose hash is stored, a public UUID and a relative URL signature, expiring after 48 hours. GET only displays a confirmation form; CSRF-protected POST confirms email ownership. Repeated valid confirmation is idempotent. Existing active preferences are not changed by an unauthenticated re-request; the new preferences require confirmation. Verified account email owners can update confirmed preferences in their account. Changing account email does not silently move a subscription to another address.

Every digest includes a signed unsubscribe link with an independent random secret. GET displays the withdrawal form; POST withdraws once, revokes pending confirmation, increments the subscriber version and cancels eligibility of queued digests. A new request and confirmation are needed to resubscribe; old unsubscribe links are rotated. Consent, confirmation, withdrawal and preference changes have private audit rows. No administrator action can manufacture a reader's consent.

Daily selection uses the previous complete UTC day; weekly selection uses the previous complete Monday–Sunday UTC week. At most six real, currently published localized stories are selected: editor-pick tag first, then newest publication and article ID. Fictional cryptonews-demo stories, simulated AI, drafts, future publications and unreviewed pipeline content are excluded. No fallback English digest is sent for a Japanese subscription. Campaigns retain translation fingerprints and recheck unchanged public content before preview and sending. The inline ivory/red HTML email and plain text alternative contain branding, headlines, short excerpts (maximum 240 characters), publication dates, article links and unsubscribe; no tracking pixel or full article copy is included.

### Local preview, queues and mail outcomes

Defaults in config/engagement.php and .env.example:

```dotenv
PRICE_ALERTS_ENABLED=false
NEWSLETTERS_ENABLED=false
ENGAGEMENT_REAL_EMAIL_ENABLED=false
ENGAGEMENT_EMAILS_PER_MINUTE=60
ENGAGEMENT_BATCH_SIZE=100
MAIL_MAILER=log
MAIL_TIMEOUT=10
```

Existing .env credentials and values were preserved. Each enable flag requires its corresponding administrator switch too. Confirmation mail can be queued after an explicit reader request even while scheduled newsletters are disabled; SMTP still requires the separate real-email switches.

Preview eligible stories locally without creating a campaign, subscriber or delivery, without provider requests and without sending mail:

```powershell
php artisan engagement:preview --locale=en --frequency=daily
php artisan engagement:preview --locale=ja --frequency=weekly
```

The command prints a private .tools/engagement-preview-UUID.html path. Open the file locally in a browser; do not publish .tools. An empty preview is expected when no real stories qualify in that period/language. The original sample news is fictional and is intentionally excluded. To test a confirmation locally, submit your own email explicitly at /en/newsletter while MAIL_MAILER=log, run the engagement worker, then inspect the private storage/logs/laravel.log for the confirmation link. Log mail contains personal addresses and token links: keep logs private. This phase did not submit subscriptions or send messages against the application database.

The current combined startup command remains:

```powershell
.\scripts\composer.ps1 run dev
```

Or use four VS Code terminals from the workspace root:

```powershell
php artisan serve --host=127.0.0.1 --port=8000
npm run dev
php artisan queue:work --queue=default,ai,engagement --tries=1 --timeout=85
php artisan schedule:work
```

The Composer script now includes the engagement queue. Existing user-owned processes were not stopped or restarted; restart old workers yourself when ready to load the new queue list/code. Keep workers' retry_after greater than their timeout (existing 90 seconds versus maximum 85 seconds). Engagement jobs have one attempt and a 60-second timeout; per-delivery/evaluation shared locks and unique jobs bound concurrency.

The scheduler runs engagement:dispatch every minute to dispatch due outbox records, audit interrupted sending and resume audience batches. Price checks and digest builders are separate unique engagement jobs gated by their controls. Newsletters default to daily 08:00 UTC and weekly Monday 09:00 UTC, configurable in the control center. These are UTC, independent of the reader's browser timezone. A unique locale/frequency/period campaign, unique campaign/subscriber delivery, audience cursor, bounded batches, minute-rate lock and row locks protect duplicate execution. Production uses the existing discovery/shared cache selection and requires database or Redis locking.

Delivery states have precise meanings:

| State | Meaning |
| --- | --- |
| queued | Pending worker execution; no claim of sending |
| stored | Database notification stored once under the outbox UUID |
| local | Log/array output only; no inbox delivery |
| accepted | Configured SMTP transport returned an acceptance/message ID, or an administrator recorded verified transport evidence; no inbox receipt claim |
| failed | Known unsent worker/queue failure eligible for bounded explicit recovery |
| uncertain | Send may have occurred; never automatically resend |
| skipped | Preferences, consent, publication, mode, freshness or transport permission changed |
| closed | Administrator audited an unknown outcome and closed it without resending |

Email is globally rate-limited (60/minute by default) and sent in bounded batches (100 by default); administrator values cannot exceed server ceilings. At most one application mail attempt is made per delivery. Rate-limited unsent work is deferred without consuming an attempt. Queue-push failures back off and stop after three automatic push failures; explicit unsent recovery remains bounded by six cumulative dispatch/recovery failures. A worker interrupted after its send marker is treated as uncertain after five minutes. Consult SMTP/queue logs and the X-CryptoNews-Delivery-ID header; record verified evidence in the private delivery investigation action. Unknown outcomes cannot be resent through recovery controls. This favors avoiding duplicates over silently retrying possibly accepted mail; SMTP does not provide exactly-once inbox delivery.

### Administrator controls and production activation

Only administrators can access:

- /admin/engagement-control-center — stored subscriber/watchlist/alert/notification/failure counts, UTC schedules, rates, switches and stop controls.
- /admin/newsletter-subscribers — private email lookup, consent audit and subscription withdrawal.
- /admin/newsletter-campaigns — build a draft, private preview, approve, queue an eligible audience, cancel, or queue a rate-limited test to the administrator's own verified address.
- /admin/price-alerts — read-only overview, with personal mutations confined to the owning reader account.
- /admin/engagement-deliveries — outcomes, sanitized failure codes, unsent recovery and documented unknown-outcome closure.

Newsletter automation requires explicit server NEWSLETTERS_ENABLED=true and administrator enablement. Once enabled, the schedule builds/approves the documented objective selection under the current administrator's authority; a manual draft can be inspected/approved separately. Real email additionally needs MAIL_MAILER=smtp, configured credentials/from address, ENGAGEMENT_REAL_EMAIL_ENABLED=true and the administrator SMTP permission. Verify the sender domain, SPF/DKIM/DMARC, TLS, bounce/complaint handling, SMTP account limits, consent policy and actual transport acceptance in a controlled test before enrolling a real audience. No SMTP account, paid provider or inbox delivery was verified here.

Price alerts need licensed LIVE provider access and fresh validated shared snapshots plus both alert controls; leave DEMO in place until separately authorized. Do not activate paid AI or automatic publishing for engagement. Deploy with migrations/backups, HTTPS/canonical APP_URL, stable APP_KEY, private logs/previews, shared persistent locking, supervised queues and one scheduler. Test on the intended MySQL version and real multiple-worker setup. No real market/provider request, SMTP message or unsolicited marketing send occurred during this phase.

### Privacy, migrations and changed files

Subscriber email and unsubscribe secret are encrypted; email lookup uses keyed hashes. Raw confirmation tokens exist only in encrypted queued payloads and private mail output, and confirmation URL payloads are removed on terminal delivery. Expired tokens cannot confirm. Personal pages query the current owner only, all mutations use CSRF/authorization, server-owned IDs/state are not mass assignable, and subscriber email addresses are limited to administrators. Never expose .env, application logs, private previews, database backups or queue payloads.

No automatic user-data deletion or retention purge runs. Confirmation links expire in 48 hours. Operators should review unconfirmed subscriptions/expired unsent records after 30 days, minimize local mail/access-log retention, redact token URLs from access telemetry, and approve a retention period for consent/suppression and delivery audit records before production. Keep unsubscribe suppression sufficient to prevent accidental re-enrollment; remove/anonymize personal data only through an explicitly authorized privacy workflow. Existing account deletion policy remains unchanged (no public account-delete route). A genuine authorized server-side user deletion withdraws linked or verified-email-owned subscriptions, removes notifications and cascades personal watchlist/bookmark/alert records; audit rows retain minimal consent evidence.

Two applied additive migrations:
2026_11_17_111114_add_reader_engagement_foundation.php creates watchlist_items, saved_articles, price_alerts, price_alert_events, notifications, newsletter_subscribers, newsletter_consents, newsletter_campaigns, engagement_deliveries and engagement_settings, and adds two user notification preference booleans. 2026_11_17_114406_preserve_exact_engagement_prices.php stores thresholds/observations as canonical strings across SQLite/MySQL; its down method deliberately retains exact strings rather than reintroducing rounding. Do not roll back the foundation against user engagement data.

The implementation is grouped under existing directories: Support/{ReaderEngagement,PriceAlerts,PriceThreshold,NewsletterSubscriptions,NewsDigests,EngagementDeliveries,EngagementSettings}; nine related models/factories, six policies; five reader controllers; PriceThresholdCrossed notification; EngagementMessage mail; three engagement jobs; two Artisan commands; four Filament resources and EngagementControlCenter; community/account components, HTML/text email views and eight engagement language catalogs. User, routes, private-page middleware, locale links, the homepage newsletter CTA, article save action, scoped community CSS, mail/config examples, Composer queue script and PHPUnit configuration were extended. Composer/npm dependencies and lockfiles were not changed. Eight engagement feature test classes plus the shared test case cover the new workflow; the former inactive-newsletter homepage assertion now checks the functional consent link.

### Phase 09 verification

Final verification: **688 tests / 16,512 assertions**, zero failures/errors/skips, including all 563 prior cases and 125 new Phase 09 cases. Laravel Pint, Composer validation, Blade compilation, route listing, scheduler listing and migration status passed. The Vite 8.3.8 production build passed: CSS 101.12 kB (20.07 kB gzip), JS 4.00 kB (1.51 kB gzip). All 24 local homepage/news/newsletter HTTP checks across eight locales passed with valid UTF-8 and no unresolved engagement labels; account/admin guest redirects passed. English daily and Japanese weekly offline previews created no campaign, subscriber or delivery. All 25 original business tables match their pre-migration original rows/columns; SQLite integrity and foreign-key checks pass, and the ten engagement tables remain empty.

Browser navigation and screenshot capture failed in the available Chrome bridge, so **visual verification was not performed**. Automated rendered-page, native Filament/Livewire action, ownership and eight-language email tests do not substitute for real desktop/mobile or mail-client visual acceptance. No MySQL/distributed-worker or real SMTP acceptance test was performed. The mailer remains log, markets remain DEMO, all three engagement enable flags are false and no real email or external API request was made for Phase 09.

Historical Phase 09 boundary: its verified behavior is preserved. The current advertising phase is documented next.

## Phase 10 — advertising and sponsorship

Phase 10 adds administrator-managed direct image campaigns, sponsored editorial disclosure, a disabled AdSense integration boundary, audited ads.txt configuration and honest direct-delivery reporting. The approved masthead, tickers and newspaper layout remain. Ads render no empty boxes while disabled. Eight public languages and Armenian historical records are preserved. No advertiser, campaign, sponsored story, seller entry, publisher ID, revenue or tracking activity has been seeded into the application database.

### Local entry points and safe preview

The existing startup command is unchanged:

```powershell
.\scripts\composer.ps1 run dev
```

Open <http://127.0.0.1:8000/en> or <http://127.0.0.1:8000/en/news>. No advertising appears with current defaults. Public <http://127.0.0.1:8000/ads.txt> returns a plain-text comment with no seller record until actual authorization is configured.

Administrators can sign in at /admin/login and use the **Advertising** group:

| URL | Function |
| --- | --- |
| /admin/advertising-control-center | Settings, shutdown, real stored counts, readiness and private change audit |
| /admin/advertisers | Actual business/public brand, private contacts and notes, verification status |
| /admin/ad-placements | Enable a configured position; choose direct or future AdSense and an actual slot ID |
| /admin/ad-campaigns | Draft creation/edit, image upload, private preview, manual approval and pause |
| /admin/sponsored-contents | Paid articles linked to the existing Article CMS and publishing workflow |
| /admin/ad-delivery-totals | Aggregated origin slot requests and eligible server HTML deliveries |

Readers and editors cannot access these destinations. There is no advertiser login, self-service publication, payment processing or billing system.

To preview a banner safely, create an **actual** advertiser/campaign as an administrator, leave the campaign as a draft, then use its **Preview** action. /admin/advertising/campaigns/{campaign}/preview/{locale} is authenticated, policy-protected, no-store/noindex/nofollow/noarchive. It renders the sanitized local creative and validated destination without loading scripts, recording metrics or activating a campaign. Preview the actual saved file; re-encoding may remove original image metadata/color/orientation information. Test fixtures exist only in isolated tests; no fictional advertiser account was created locally.

Public direct-ad preview is a separate explicit operator choice: set ADVERTISING_MODE=preview, ADVERTISING_ENABLED=true and DIRECT_ADVERTISING_ENABLED=true on a local environment, enable global/direct controls and the selected placement in the control center, then manually approve the actual campaign. Restore flags/switches afterward. This phase did not enable these controls. Native preview does not require any of them.

### Positions, design and density

Nine fixed registry positions are installed disabled, with server-controlled geometry:

| Registry key | Location | Reserved desktop size |
| --- | --- | --- |
| home_after_hero | After the editorial hero when sufficient stories exist | 728 × 90 |
| home_between_news | After latest news, before the newsletter section | 728 × 90 |
| home_sidebar | Desktop front-page sidebar | 300 × 250 |
| news_inline | Between listing rows after four remaining cards, when more cards follow | 728 × 90 |
| news_sidebar | Desktop support column of a populated first-page feature | 300 × 250 |
| article_before_body | Before the article body | 728 × 90 |
| article_inline | Between substantial complete article sections | 728 × 90 |
| article_after_body | After body content | 728 × 90 |
| article_sidebar | Desktop reading sidebar | 300 × 250 |

The reusable ad-slot/direct-banner components use warm ivory, thin dividers, compact Advertisement labels, fixed reserved heights, bounded local images and object-fit:contain. Inline mobile slots reserve 100 pixels; desktop-only positions hide below 1024 pixels. No global overflow hiding, overlay, popup, autoplay, extra frontend package or ticker change was added.

A maximum of two filled slots per origin page render prevents excessive density. Document order determines which eligible positions consume that budget. Desktop-hidden placements may consume a server slot even when not visible on mobile; these are still reported only as server deliveries. AdSense is intentionally excluded from desktop-only hidden positions. A missing, disabled or ineligible campaign emits no placeholder.

Article insertion uses complete sanitized top-level blocks before a later h2, with at least 800 visible characters on each side and at least 1,800 total. It never splits lists, tables, links, paragraphs or media containers. Short/wrapped bodies skip inline insertion. Article text, reading estimates, heading IDs, canonical URLs and SEO are derived before ad markup. Ads are limited to named public home/list/article routes, with no placement in authentication, newsletter/account forms, administration or private editorial previews.

### Advertiser and direct campaign workflow

Advertisers hold business name, public brand, optional safe website, encrypted contact/notes and pending/active/paused status. Campaigns contain an actual advertiser, sanitized creative, safe HTTPS destination, selected placements, selected public locales and truthful text per locale, UTC start/end, priority, sensitive category, private encrypted contract amount and currency. Contract amounts are commercial reference values, never measured revenue.

Only administrators can create/update/approve/pause. Images must be static JPEG/PNG/WebP, 80×40 to 2400×1600, input at most 2 MB. PHP GD decodes and re-encodes to a generated local PNG without the original metadata or embedded payload; GIF/SVG/executable uploads, animation and oversized images are rejected. Sanitized output is capped at 5 MB. GD is installed here; another environment must provide it. Stored media uses the existing public disk: even draft banner assets are public files, so do not upload confidential material. Files are never automatically deleted or overwritten.

Destination validation reuses the existing HTTPS/public-host URL inspection without making DNS or HTTP requests. Credentials, private/local/IP hosts, fragments, unsafe schemes, unexpected ports and credential-bearing query keys are rejected. Banner links go directly to that validated destination with sponsored/nofollow/noopener/noreferrer and no-referrer; no application click redirect or tracking URL is introduced. Review the actual landing page and any downstream redirects manually.

New or edited campaigns are drafts with approval cleared. Approval requires a verified active advertiser, unexpired campaign, unchanged creative, placements, manual rights/claims/no-guaranteed-return/territory/platform attestations and meaningful evidence notes. Sensitive categories are explicit: crypto investment, trading platform, financial product, token sale, yield claims and unverified investment offer. Unverified offers are blocked. Recognized prohibited guarantee claims are screened, but text screening cannot inspect every pixel or prove truth; the administrator must inspect the full image and destination. No category is automatically approved.

Language targeting is **not** geographic restriction. The reviewer must verify legality and policies for every territory actually served. Do not approve a territorially restricted promotion until a genuine country-restriction integration exists; none was fabricated here. These controls record human review and do not supply automatic legal approval.

Eligibility rechecks active status, actual administrator approval, advertiser status, content/creative/targeting fingerprint, selected locale/placement and start <= now < end. Editing resets approval; pausing or globally stopping removes eligibility on subsequent origin responses. Priority is descending, then stable campaign ID; there is no invented bidding or revenue optimization.

Only candidate IDs are shared-cached for 30 seconds by locale/settings revision. Fresh models and exact dates/approval are rechecked per request, so expiration and pauses are not served from a stale model snapshot. Mutations bump the revision; future approved candidates remain available for exact start-time checks. Pools and placements are eager-loaded once per request, without one query per campaign. Responses containing advertising are private/no-store; still purge any independently configured upstream caches when withdrawing ads. A stop cannot recall already loaded browser content.

### Sponsored editorial content

The existing Article form has an administrator-only sponsorship section: sponsored/advertisement classification, actual sponsor association and accurate disclosure for every available public translation. There is no second CMS or advertiser publishing pipeline. Save changes, submit through the existing review action, review the **saved sponsorship**, then use existing editorial approval/publish/schedule.

Commercial review requires the actual active sponsor and the same rights/claims/territory evidence checks. It stores an administrator/content fingerprint. Material content, tag, image, authorship, category or disclosure changes invalidate that review and return a sponsored article to draft; unchanged form saves preserve review. Editors may edit their authorized drafts but cannot change paid classification or review/publish it. Existing AI authorization, fact-review and translation review gates still apply.

Public cards, breaking stories, saved lists and article readers show a translated Sponsored/Advertisement label. The article additionally names the sponsor, separates paid material from independent reporting and displays the actual localized disclosure. External body links get sponsored/nofollow/noopener/noreferrer. Sponsored articles use Article structured data with sponsor/genre rather than implying independent NewsArticle classification. Existing slugs/canonicals/language alternates remain.

Paid articles are excluded from independent editorial picks/analysis/opinion selections and existing newsletter digests. They cannot enter a digest without a future explicitly designed sponsorship policy. Global banner shutdown never hides the paid disclosure of a published article. Use the existing editorial unpublish action to withdraw the article itself.

### AdSense, consent and ads.txt

Defaults in config/advertising.php and .env.example are:

```dotenv
ADVERTISING_ENABLED=false
DIRECT_ADVERTISING_ENABLED=false
ADVERTISING_MODE=disabled
ADSENSE_ENABLED=false
ADSENSE_PUBLISHER_ID=
```

Actual .env and dependency lockfiles were preserved. No publisher ID, seller account or provider approval was invented. Publisher identifiers are public configuration, not an API credential. Configure only the real ca-pub identifier issued for the account; slot IDs come from the account's actual ad code.

Optional AdSense markup uses Google's responsive display code pattern. It is gated by server and administrator switches, production environment, ADVERTISING_MODE=live, MARKET_DATA_MODE=live (never DEMO), valid actual publisher/slot IDs, recorded account/policy review by a current administrator and per-visitor authorization from a real consent adapter. No AdSense API, dashboard scrape, revenue fetch, custom click/impression tracker or approval bypass exists.

AdvertisingConsent is the integration contract. NoAdvertisingConsent is the default binding and **always denies external advertising**, even if request/cookie flags claim consent. It does not impersonate a Google-certified CMP. A future genuine CMP adapter must implement verified visitor signals, relevant regional requirements, script ordering, refusal and withdrawal; changing a boolean is not a complete integration. Authorization-bearing responses are not public-cacheable. Consent integration must be independently reviewed and approved before loading real scripts.

Google documents certified CMP requirements for personalized ads in the EEA, UK and Switzerland. The shipped implementation conservatively blocks external ads for every visitor until an integration exists. Google approval, consent compliance and financial promotion permissions have **not** been established. See [Google CMP requirements](https://support.google.com/adsense/answer/13554116?hl=en), [responsive code guidance](https://support.google.com/adsense/answer/9183363?hl=en) and [ad-code modification rules](https://support.google.com/adsense/answer/1354736?hl=en).

The control center accepts at most 50 distinct authorized seller lines, validates public domain/account/relationship/optional certification fields and requires explicit verification notes. A Google DIRECT record must match the real configured publisher and Google's certification identifier. Blank/unverified/invalid or revoked-authority settings publish no seller records. /ads.txt is text/plain UTF-8 with nosniff and a five-minute cache lifetime, never private notes. Verified seller authorization remains independent of a temporary banner shutdown. Obtain the exact seller record from the real account; see [Google ads.txt guidance](https://support.google.com/adsense/answer/7532444?hl=en). Publishing a line does not establish account approval.

### Reporting, privacy and shutdown

AdDeliveryTotal aggregates UTC day, placement, locale and optional direct campaign only. Slot requests and eligible server HTML deliveries have separate rows/counters. No visitor IP, account ID, cookie/session ID, user agent, referrer or click identifier is stored. No private account/community data is appended to advertiser destinations or passed into network configuration.

These are best-effort **origin rendering counters**, not visible/rendered client impressions, unique reach, advertiser clicks, CTR, CPM, RPM, payouts or revenue. Browser visibility, mobile-hidden slots, ad blockers, network failure and edge cache behavior prevent that inference. Unmeasured metrics are unavailable, and AdSense reporting is not connected. Do not use these counters as proof of billable visible impressions.

The control center shows real stored active-date/future/expired campaigns, configured/enabled positions, sponsored articles including drafts, delivery summaries, readiness and recent encrypted audit evidence. Advertiser contacts, commercial terms, reviewer notes and audit detail are administrator-only and encrypted/hidden from serialization. No automatic advertiser/campaign deletion or visitor tracking runs. Define commercial-record retention and authorized asset cleanup before production.

Use **Stop all banner and network advertising** for immediate new-response shutdown. Server enable flags can also be restored to false; clear cached configuration and restart long-lived application workers after deployment/config changes. Do not change existing market, AI or newsletter controls for direct banners. Stopping cannot recall browser-loaded ads; sponsored editorial withdrawal remains an Article workflow action.

### Schema, changed files and production readiness

Migration 2026_11_17_122540_add_advertising_and_sponsorship_foundation.php creates advertisers, ad_placements, ad_campaigns, ad_campaign_ad_placement, ad_delivery_totals, advertising_settings and advertising_audits. It adds six nullable sponsorship association/disclosure/review fields to Articles. Appropriate foreign keys, uniqueness, schedule indexes and aggregate keys preserve existing data. Only nine disabled system positions were seeded; the seeder skips existing operator settings:

```powershell
php artisan migrate --no-interaction
php artisan db:seed --class=AdPlacementSeeder --no-interaction
```

Do not run migrate:fresh against business data. A consistent private backup is in .tools/phase10-before-20261117.sqlite.

New files are grouped under existing directories: six models/factories, five policies, AdvertisingService, AdPlacementResolver, CampaignEligibilityService, AdvertisingStatisticsService, SponsoredContentService, AdvertisingManager, AdvertisingSettings, AdCreative and the consent contract/default; two controllers; five Filament resources and AdvertisingControlCenter; shared review forms; ad/banner/disclosure/preview views; eight advertising translation catalogs; config/advertising.php; two safe configuration seeders; eight feature test classes and AdvertisingTestCase. Existing Article/editor/publication/content/SEO/feed/digest services, Article admin form, public views, saved lists, middleware, provider binding, routes, scoped CSS, env example and PHPUnit settings were extended. No dependency, existing design reference, market provider, queue or scheduler was replaced.

Before production activation:

1. Verify actual advertisers, creatives, claims, rights, target territories and financial-promotion rules. Do not infer permission from language.
2. Verify migration/backup behavior on the intended MySQL 8+ server, shared persistent caching and concurrent writes; local tests use SQLite.
3. Use HTTPS and stable APP_KEY, protect private audit/contact/contract data and previews, and configure public storage to serve raster files without script execution.
4. For reviewed direct campaigns, deliberately configure live mode/server flags, administrator switches, enabled placements and approval. Test actual desktop/mobile creative appearance and expiration.
5. For AdSense, obtain actual account/site approval and IDs, publish only verified seller records, integrate a genuine suitable CMP and validate visitor-specific consent/withdrawal before enabling network code. This phase does not provide that approval or CMP.
6. Keep LIVE markets, paid AI and scheduled newsletters unchanged unless separately authorized. No advertising purchase, payment, tracking activation or real network serving occurred here.

### Phase 10 verification

Final verification passes **785 tests / 17,098 assertions**, with zero failures/errors/skips: all 688 prior cases plus 97 new advertising cases. Existing editor saves and delegated AI regeneration retain their authorized behavior, while paid reclassification remains administrator-only. A failure-injection test also proves campaign pause and its required audit commit atomically.

Laravel Pint, Composer validation, Blade compilation, route listing (88 application routes), scheduler listing and migration status pass. The Vite 8.3.8 production build passes: CSS 102.45 kB (20.33 kB gzip), JS 4.00 kB (1.51 kB gzip), with no new dependency. Thirty-two local homepage/news/newsletter/login HTTP checks across all eight languages pass with valid UTF-8, no unresolved advertising labels, no ad slots and no external advertising code under the default disabled configuration. The public ads.txt endpoint has no seller records, and all six advertising admin destinations redirect guests to login.

All original columns/rows in 35 existing business tables match the pre-migration backup. SQLite integrity/foreign-key checks pass. The seven advertising tables contain only nine disabled system positions; advertisers, campaigns, commercial settings/audits and delivery counters remain empty. No article has been reclassified as sponsored. The actual .env, Composer/npm manifests and both lockfiles match their pre-phase hashes. Market DEMO, paid-AI stop and disabled newsletter settings remain intact.

Browser navigation and screenshot capture failed, so **visual verification was not performed**. MySQL execution, distributed stress, actual Google/CMP acceptance, production financial-ad review and real campaign visibility remain operator acceptance tasks. No advertising network or tracking script was enabled, and no publisher/seller account or revenue was invented.

Phase 10 is complete. Phase 11 has not started.

