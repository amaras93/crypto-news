# Phase 11 deployment readiness

Audit date: **2026-11-18**. Scope: the existing CryptoNews workspace, Phases 01–10, with no deployment or integration activation. This report supersedes earlier readiness claims; historical phase documentation is retained.

**Release decision: BLOCKED pending staging evidence.** The local application and automated checks can pass without proving MySQL, browser geometry, production infrastructure, delivery or licensing. No verified P0 was found in this bounded review. This is an ASVS-informed source review and regression exercise, not penetration-test certification or complete ASVS conformance. The review used the [OWASP ASVS stable guidance](https://asvs.owasp.org/) and [ASVS releases](https://github.com/OWASP/ASVS/releases).

## Architecture and unchanged safety state

Laravel 13.42.0 / PHP 8.4.22, Filament 5.11.2 / Livewire 4.4.8, Tailwind 4.3.5 / Vite 8.3.8. Public Blade pages, an authenticated editorial panel, eight localized routes, SQLite locally, MySQL configuration for production. Application services own publication, discovery, AI accounting, engagement and advertising decisions; native Filament forms call those services. Database constraints and transactions protect relationships and idempotency keys. Database/Redis caches provide production lock compatibility; deployment across real hosts remains untested.

Markets remain **DEMO**. Paid AI, translation and scheduled generation remain disabled, AI emergency stop remains enabled, and automatic publishing remains unavailable. Discovery is disabled; scheduled newsletters, price alerts and real engagement email remain disabled. Local mail uses the log transport. Advertising and AdSense remain disabled; `NoAdvertisingConsent` denies external advertising and no CMP is connected. No API credentials, SMTP messages, paid calls, subscriptions or external monitoring were created. No dependency or schema changes were made. The local `.env`, existing business records and design references are preserved.

Public locales remain `en`, `ru`, `fr`, `de`, `es`, `it`, `ja`, `zh` (`zh-Hans` HTML/hreflang). Historical `hy` translations remain stored. Public articles require an actual published, reviewed and visible translation; the audit did not invent missing content.

## Findings and remediation

| ID | Severity | Finding / evidence | Resolution / status |
| --- | --- | --- | --- |
| SEC-01 | P1 | Two different valid-length Unicode passwords sharing the first 72 UTF-8 bytes verified against the same bcrypt hash. Registration accepted them before the fix. | **PASS — fixed.** Shared `SafePasswordBytes` validation rejects NUL and bcrypt inputs over 72 bytes in registration, reset, change, administrator creation/editorial management and both login surfaces. Valid shorter Unicode passwords still authenticate. Existing hashes were not modified. |
| SEC-02 | P2 | A NUL-containing registration password and an array-valued reader login email produced HTTP 500 before the fix. | **PASS — fixed.** Validate before hashing/normalization; malformed registration/profile/current-password inputs return validation errors. Filament also validates password type. |
| SEC-03 | P2 | Public HTTP responses lacked the audited browser security headers. | **PASS — fixed.** `nosniff`, SAMEORIGIN, referrer and permissions policies plus a compatible CSP baseline. Existing private-page `no-referrer` is preserved. HSTS is opt-in for production HTTPS only. |
| SEC-04 | P1 release requirement | Unrestricted proxy trust or host spoofing could invalidate deployment assumptions. Current local HTTP is intentionally a development configuration. | **PASS for regression checks; BLOCKED for deployed infrastructure.** Production trusted hosts derive from APP_URL plus explicit literal hosts. Only explicit nonzero IP/CIDR proxy ranges are trusted; forwarded Host is ignored. Secure/encrypted session defaults apply in production. Test the real proxy and origin firewall before release. |
| PERF-01 | P2 | Advertising campaign table: 14 queries for one row; 59 for ten, including 10/55 repeated user queries. | **PASS — fixed.** Request-scoped advertising permission lookup reduces this to 5/5 queries and 1/1 user query. Mutation services still reload the actor. Role revocation on a subsequent request and before writes is regression-tested. |
| DB-01 | P2 | Two separate PHP processes reserving the shared SQLite AI budget reproduced `database is locked` under deferred lock upgrades. No overspend was observed. | **PASS — fixed locally.** SQLite uses IMMEDIATE transactions and a 5,000 ms busy timeout. Competing requests commit one 600-microUSD reservation against a 1,000-microUSD allowance; duplicate keys return the same reservation. This is not MySQL concurrency evidence. |
| DEP-01 | P2 application exposure; upstream High | npm reports six High package entries associated with one picomatch ReDoS advisory, GHSA-fc5q-39xc-vjpf. Installed affected versions: 4.0.7 and 2.3.2. | **BLOCKED upstream.** The advisory lists no patched release at audit time. These are build/dev dependency paths; production npm audit reports zero findings, and PHP requests do not evaluate visitor-supplied picomatch patterns. Use trusted build inputs, keep Vite private, and recheck before release. No forced Vite/plugin downgrade or dependency suppression was applied. |
| OPS-01 | P1 release gate | MySQL server/client, Docker and a local MySQL service are unavailable; localhost:3306 is closed. | **BLOCKED.** Execute disposable MySQL 8+ migration, integration, collation, locking and restore checks in staging. |
| QA-01 | P1 release gate | Chrome bridge could inventory tabs, but creating the local QA tab failed with `Cannot read properties of undefined (reading 'timeoutMs')`. | **BLOCKED.** No actual page rendering, screenshots, viewport checks or visual sign-off occurred. Use the [staging checklist](STAGING_QA_CHECKLIST.md). |
| OPS-02 | P1 release gate | Windows PHP lacks PCNTL; no production worker supervisor, cron, TLS/public-root deployment or off-host backup system exists here. | **BLOCKED.** Configure and verify on the intended Linux staging infrastructure. |
| SEC-05 | P1 account migration gate | A legacy bcrypt hash cannot reveal whether its original password exceeded 72 bytes. Rejecting future ambiguous input cannot reconstruct an old truncated suffix. | **BLOCKED for affected legacy accounts.** Before public launch, have any account created with an overlong password reset through the secure workflow. Existing accounts/passwords were preserved; no forced reset or real email was sent. |
| SEC-06 | P2 | CSP compatibility baseline intentionally has no `script-src`/nonce enforcement. Public raster uploads retain their original metadata; public upload URLs are public even before article publication. | **NOT TESTED for stricter CSP / confidential media requirements.** Stage a nonce policy with Livewire/Alpine before tightening it. Upload only material approved for public storage; confidential originals belong on the private disk. PHP execution from upload directories must be disabled at the server. |

PHP's [bcrypt documentation](https://www.php.net/manual/en/function.password-hash.php) specifies the 72-byte input limit; the [OWASP password storage guidance](https://cheatsheetseries.owasp.org/cheatsheets/Password_Storage_Cheat_Sheet.html) informed the non-truncating validation. See the current [picomatch advisory](https://github.com/advisories/GHSA-fc5q-39xc-vjpf). SQLite documents [deferred upgrade contention and IMMEDIATE transactions](https://www.sqlite.org/lang_transaction.html) and the bounded [busy timeout](https://www.sqlite.org/c3ref/busy_timeout.html).

## Security boundaries reviewed

| Area | Evidence and limits | Status |
| --- | --- | --- |
| Authentication / privilege separation | Reader role fields are protected; verified-email gates, session regeneration, hashed reset tokens, single-use reset behavior and server-side policies. Existing tests cover reader/editor/admin boundaries and another editor's drafts. New tests exercise Unicode credentials, malformed input and CSRF outside PHPUnit's normal bypass. | PASS — automated |
| CMS / private previews | Own-draft editor scoping, administrator publication approval, scheduled visibility, soft deletion, independent policy/service checks. Previews require authentication/authorization, noindex/no-store and a real requested translation. | PASS — automated |
| Injection / HTML / files | Bound ORM/filter inputs, sanitized article HTML and URL allowlists, escaped comments, UUID-named bounded raster uploads, no SVG/executable creative uploads; advertising creatives are re-encoded. No generic user-input unserialization endpoint was found. Public raster originals/metadata require the operational treatment above. | PASS for covered regressions; broader penetration testing NOT TESTED |
| Discovery / SSRF | HTTPS allowlists, all-address public-IP checks, pinned DNS resolution, redirects disabled, bounded response sink, connect/read timeouts, XML external entity/network/doctype rejection, source permissions, deduplication and leases. Deterministic tests only; no source was fetched. | PASS — fakes/source review |
| AI / budget / evidence | Independent activation gates, emergency stop, integer microUSD reservations, sorted locked period rows, unique work/request keys, evidence snapshots/digests, untrusted source text, structured validation, separate human review, protected human translations. Unknown usage/outcomes remain uncertain and reserved; no self-publishing. New two-process budget tests use disposable SQLite and no provider. | PASS — deterministic/SQLite; MySQL contention BLOCKED |
| Market isolation | Existing adapters/cache/scheduler retained. Batched crypto, supported metals/unit validation, provider timestamps, stale/last-good behavior, no visitor refresh calls and no demo fallback in LIVE. DEMO previews include 20 crypto + 5 commodities with fixed icons. | PASS — automated; live licenses/API acceptance BLOCKED |
| Email / engagement | Verification/reset fakes, double opt-in, consent records, hashed tokens, unsubscribe/version checks, current-quote restrictions, recipient preferences, unique delivery records, no automatic resend of an uncertain SMTP outcome. Log/array transport is reported as local, not accepted delivery. | PASS — fakes/local only; SMTP BLOCKED |
| Advertising / privacy | Shutdown gates, admin policies, review hashes, date eligibility, raster restrictions, localized disclosure, sponsored link attributes, sanitized ads.txt, separate request/eligible HTML counters. No external script without trusted consent; no fabricated revenue/impressions. | PASS — automated; Google/CMP/legal acceptance BLOCKED |
| Logs / sensitive data | Provider failures persist controlled codes instead of raw transport payloads. Private application data stays out of public views. Local log mail deliberately includes message/token URLs and is not a production delivery strategy. Failed-job payloads, logs and database backups remain sensitive operational records. | PASS for code paths reviewed; infrastructure retention/redaction NOT TESTED |

## Database and restore evidence

All 13 existing migrations are applied locally. No migration was added or changed in Phase 11. Source inspection covers foreign keys, cascade/restrict/null actions, locale/slug and idempotency uniqueness, publication/date indexes, JSON fields, integer accounting and exact canonical string prices. Indexed composite sizes fit modern InnoDB limits by inspection. Production uses configured `utf8mb4` / `utf8mb4_unicode_ci`, strict SQL and UTC application timestamps.

**MySQL 8 execution is BLOCKED.** SQLite success does not validate MySQL case/accent-insensitive slug collisions, JSON comparison semantics, timestamp limits/timezones, foreign-key DDL, decimal-to-string upgrades, deadlocks or row locks. Run against a disposable MySQL database with a restricted test account and production charset/collation. Preflight imported slug/email collisions before moving existing data. Do not use destructive schema refresh commands against the local or production database.

`DisposableRestoreTest` creates a new named SQLite connection and runs the full migrations there. It creates synthetic articles/translations/comments/tags, reservation periods, campaigns/placements and encrypted consent/contact records. `VACUUM INTO` produces a consistent backup; restoring it to a separate file compares row counts and SHA-256 content digests for **all 50 tables**. Integrity and foreign-key checks pass; Japanese, Chinese and Armenian text and encrypted fields round-trip using an explicit test-only key. Three public/private/inventory fixture files survive an archive/extract/hash check. No real secrets or business backup is committed. This is a tested **SQLite fixture restore**, not a MySQL or production recovery rehearsal. See [backup operations](PRODUCTION_RUNBOOK.md#backup-and-disposable-restore).

The final read-only comparison against `.tools/phase11-before-20261118.sqlite` confirms identical original columns/rows in **42 business tables**. The actual database reports integrity `ok` and zero foreign-key violations. Runtime session/cache/job bookkeeping is excluded from business comparison. The local `.env`, Composer/npm manifests and both lockfiles match their pre-audit hashes; no business record was deleted, reset or seeded during this phase.

## Frontend, locales, SEO and performance

Source/HTTP tests cover all eight languages, localized navigation/auth/community/newsletter interfaces and existing search/filter/pagination behavior. Fonts are locally/system sourced; actual CJK glyph appearance and navigation wrapping are **BLOCKED** with visual QA. Actual stored Armenian records are preserved and excluded from new public alternates/sitemaps. Missing article translations remain missing.

SEO tests cover configured absolute canonical URLs, supported real hreflang alternates, Open Graph/Twitter cards, NewsArticle/BreadcrumbList JSON-LD, sitemaps, robots, legacy redirects, draft/missing-translation exclusion and private noindex. `/admin` remains authenticated and private. The production domain, Google indexing and search-console acceptance are **NOT TESTED**; no domain was invented.

The public query-count regression compares 12 versus 36 published fixtures after warm-up: home **13/13**, news listing **6/6**, article with related stories/comments **17/17**. These fixtures have no optional category/sponsorship records; counts are bounded regression evidence, not a universal query budget. Market view components share one request snapshot. Existing CMS widget tests cover eager loading, and comments/listings are paginated. No broad homepage cache was added that could leak personalized or consent-dependent markup.

The production build reports CSS **102.45 kB / 20.33 kB gzip**, public JS **4.00 kB / 1.51 kB gzip** (Filament/Livewire runtime assets are separate). Article images declare dimensions/aspect ratios and below-fold images use lazy loading; the approved newspaper layout and ticker code were preserved. No new optimization dependency, CDN or image-rewrite pipeline was installed.

**LCP, INP, CLS, Lighthouse, mobile animation/overflow, rendering and load capacity are NOT TESTED/BLOCKED.** Server-side timings and PHPUnit query counts cannot substitute for browser metrics. Capture staging traces and real-device screenshots before claiming performance targets.

Local PHP development-server HTTP sampling (five requests each, debug enabled, while the automated suite was also running) measured median complete HTML response times: home **743.91 ms**, listing **639.92 ms**, and the stored English demo article **688.48 ms**. HTML sizes were 92,331 / 94,679 / 102,042 bytes. These are host/load-specific observations with no controlled before/after latency comparison. An independently running local Vite server/hot-file was present at the final check and was left untouched; this was not a production-built browser performance test.

## Evidence locations and repeatability

Ignored local evidence lives in `.tools/phase11-*` and `storage/framework/testing/phase11-*`, including baseline/final JUnit, dependency audits, command output, query measurements, disposable restore and concurrency evidence. Test artifacts contain only deliberate fixtures; the pre-audit business backup stays ignored and access-restricted. Do not publish raw logs/backups as public artifacts.

The verified baseline was **785 tests / 17,098 assertions**, zero failures, errors or skips. Final verification is **829 tests / 17,358 assertions** in 161.47 seconds, zero failures/errors/skips: 44 additional cases and 260 additional assertions. Main changes: credential validation and eight error translations; security/proxy middleware and production cookie defaults; configuration-only readiness command; SQLite contention configuration; request-scoped advertising policy checks; five new feature test files plus the administrator-command regression; these three operation documents and README/architecture links. Pint also normalized whitespace in two pre-existing advertising tests.

`php artisan app:check-production-readiness --json --no-interaction` checks configuration without provider calls or secret output. P1 configuration failures return exit 1; P2 advice appears in the report. It does **not** certify credentials, real DNS, MySQL execution, queue supervision, browser QA, SMTP, provider rights, backups or go-live approval. Local defaults intentionally fail production requirements. Fresh `.env.example` recommends a 180-second queue retry interval; the existing local `.env` was preserved.

## Verification matrix

| Check | Status | Actual result |
| --- | --- | --- |
| Laravel full suite | PASS | 829 tests, 17,358 assertions; no errors, failures or skips. All 785 baseline cases retained. |
| Pint | PASS | `php vendor/bin/pint --dirty --format agent`, final result `passed`. |
| Composer validation / locked audit | PASS | Strict manifest validation; zero PHP advisories/abandoned packages. |
| npm production-only audit | PASS | Zero vulnerabilities with `--omit=dev`. |
| npm complete audit | FAIL / BLOCKED remediation | Six High build/dev entries from one upstream unpatched picomatch advisory; P2 assessed application exposure. |
| Production asset build | PASS | Vite 8.3.8, 5 modules, 642 ms build; manifest/CSS/JS emitted successfully. |
| Blade compilation | PASS | `php artisan view:cache --no-interaction`, cached successfully. |
| Route / schedule / migration inspection | PASS | Listings complete; all 13 migrations applied, configured gated schedules preserved. |
| Isolated config/route caching | PASS | Dedicated fixture caches built and booted with production settings/fake key; local `.env` unchanged. |
| Local production-config command | FAIL — expected local setup | Exit 1: local/debug/HTTP/SQLite, insecure local HTTP cookies, current retry default, no PCNTL and active Vite hot-file are unsuitable production settings. Shared-store selections and all tested safety gates pass configuration checks only. |
| Public/auth HTTP locale checks | PASS | 32 requests, all 200, UTF-8/correct HTML language, security headers, private noindex, no Armenian hreflang. |
| Queries / parallel ledger / fixture restore | PASS — SQLite scope | Bounded public query counts, advertising 5/5 queries, two-process reservation/idempotency, 50 restored tables and three file hashes. |
| Business data / protected files | PASS | 42 original business tables unchanged, integrity ok, no FK violations; `.env` and dependency files unchanged. |
| MySQL 8 migrations/integration/concurrency/restore | BLOCKED | MySQL/Docker unavailable. Source/schema inspection is not execution. |
| Six-width browser visual QA | BLOCKED | Browser bridge failed before rendering; no screenshots/visual verification. |
| LCP / INP / CLS / Lighthouse / load capacity | NOT TESTED | No functioning browser trace or production infrastructure. |
| Linux workers, real shared locks, cron/TLS/root/backups | BLOCKED | No intended production environment available. |
| SMTP/provider licenses/paid API/Google/CMP acceptance | BLOCKED | No credentials/rights/account/delivery acceptance tested; optional integrations remain off. |

### Changed files by responsibility

- Credentials: `app/Rules/SafePasswordBytes.php`, `app/Support/ReaderAccounts.php`, `app/Support/EditorialUsers.php`, `app/Http/Controllers/ReaderAuthController.php`, `app/Console/Commands/CreateAdmin.php`, `app/Filament/Pages/SecureLogin.php`, `app/Providers/Filament/AdminPanelProvider.php`, and `lang/{en,ru,fr,de,es,it,ja,zh}/community.php`.
- Configuration/perimeter: `app/Http/Middleware/{SecurityHeaders,TrustConfiguredProxies}.php`, `bootstrap/app.php`, `config/{security,session,database}.php`, `.env.example`, `app/Support/ProductionReadiness.php`, `app/Console/Commands/CheckProductionReadiness.php`.
- Measured authorization optimization: `app/Support/AdvertisingAuthorization.php`, `app/Policies/{AdCampaign,Advertiser,AdPlacement,AdDeliveryTotal,AdvertisingAudit}Policy.php`.
- Regression coverage: `tests/Feature/{ProductionSecurity,ProductionConfiguration,ProductionPerformance,ProductionConcurrency,DisposableRestore}Test.php`, `tests/Feature/CreateAdminTest.php`; formatting only in `AdvertisingSecurityTest.php` and `AdsenseSafetyTest.php`.
- Operator documentation: `README.md`, `docs/architecture.md` and the three Phase 11 documents. No public design, route feature, provider adapter, scheduler, dependency or schema change was made.

## Mandatory requirements before Phase 12 / go-live

1. Provision isolated MySQL 8+ staging; run migrations, full integration tests, legacy-data collision preflight, concurrent ledger/publication/delivery checks and a MySQL restore with restricted credentials.
2. Complete every width/locale/role screenshot and accessibility check in [STAGING_QA_CHECKLIST.md](STAGING_QA_CHECKLIST.md). Record real browser performance and overflow evidence against `design-references/newspaper-homepage.png`.
3. Verify Linux PCNTL workers, retry windows, shared session/cache/locks, scheduler heartbeat, failed-job recovery and emergency stops across multiple processes/hosts.
4. Configure the real domain/TLS/proxies, origin access rules, public-only document root, secret injection, secure cookies, storage permissions and log redaction/retention. Run the configuration command on that deployment artifact.
5. Establish encrypted off-host backups and key custody, restore MySQL plus media/private files in a separate environment, measure recovery time and approve rollback/recovery owners.
6. Reset affected legacy overlong-password accounts through the secure workflow; review privileged-account perimeter/MFA policy before public access. No automatic password mutation is authorized here.
7. Recheck the unpatched build-tool advisory and record remediation or an explicit build-only risk decision. Keep build inputs trusted and development servers private.
8. For public reader registration, verify transactional SMTP sender/domain authentication and bounce handling with separately authorized test delivery. Until then, keep staging mail trapped. Optional AI, LIVE markets, scheduled engagement and advertising stay disabled until their separate activation prerequisites are met.

These are recommended staging tasks for a separately authorized next phase. Phase 11 does not deploy or begin Phase 12.
