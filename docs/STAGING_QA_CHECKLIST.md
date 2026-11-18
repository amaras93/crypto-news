# Phase 11 staging QA checklist

Date: 2026-11-18. Current visual status: **BLOCKED**. The Chrome bridge failed while creating the local QA tab (`timeoutMs` error). No local page was actually rendered or photographed by browser automation. HTTP status/HTML checks and automated source tests do not establish visual fidelity, animation behavior, fonts, keyboard interaction or Core Web Vitals.

Use the approved `design-references/newspaper-homepage.png` and preserved article references. Save evidence outside the public web root, with no account tokens, private messages, real subscriber data or credentials in screenshots. Record browser/version, OS, device, zoom, viewport dimensions, locale, URL, date, tester and PASS/FAIL/BLOCKED/NOT TESTED for each row. Use disposable staging users/content and trapped mail. Retain DEMO/disabled integration settings throughout this checklist.

## Screenshot matrix

Every width below is currently **BLOCKED**, including header/ticker verification. Capture homepage, news, a real translated article, auth, account/watchlist/newsletter and accessible admin at each width. Do not tick a box merely because the page returned 200.

| CSS viewport width | Home / masthead | Dual tickers / navigation | News / article / comments | Auth / account / watchlist | Admin / newsletter |
| --- | --- | --- | --- | --- | --- |
| 1920 px | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED |
| 1440 px | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED |
| 1024 px | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED |
| 768 px | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED |
| 390 px | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED |
| 320 px | BLOCKED | BLOCKED | BLOCKED | BLOCKED | BLOCKED |

- [ ] Compare ivory background, centered masthead, editorial type, thin rules, hero/sidebar proportions and alignment against the approved reference. Preserve the existing design.
- [ ] Verify `document.documentElement.scrollWidth <= document.documentElement.clientWidth` at rest and throughout ticker motion. Inspect any wider element; do not conceal defects with global body overflow clipping.
- [ ] Check both independent ticker strips for fixed 20 px icons, compact prices/change/unit alignment, visible DEMO disclosure, seamless loops without large gaps, no text overlap and no visible play/pause control.
- [ ] Test pause on hover, keyboard focus and touch; touch-scroll the contained strip. Test reduced-motion preference and short-content static fallback. Inspect icon-error neutral fallback and decoded-image/font layout stability.
- [ ] Verify navigation/language-switcher wrapping, readable focus indicators, mobile menu and long translated labels at 200% zoom. Check keyboard tab order and screen-reader labels/duplicate marquee accessibility.
- [ ] On home/news cards, check bounded 16:9 images, readable headline/excerpt/category/date/reading time, consistent spacing, accessible full-card links and no crop hiding text.
- [ ] Inspect article headline, byline, hero, sanitized body tables/links, long CJK text, related stories, breadcrumb, tags and paginated comments; verify local image dimensions prevent shifts.
- [ ] Inspect errors, empty states, long values and narrow form layouts; confirm no account data appears in public cached views.

## Eight-language pass

Run this matrix for `en`, `ru`, `fr`, `de`, `es`, `it`, `ja`, `zh`. Automated route/translation coverage is available; visual columns remain **BLOCKED** until checked.

| Surface | Required evidence |
| --- | --- |
| Home/news | Correct HTML language, readable Cyrillic/CJK glyphs, localized dates/numbers, navigation, search/filter/pagination, meaningful empty states where no actual translation exists. |
| Language switching | Preserve the appropriate route and real article translation, reset pagination, never invent a translated slug or advertise an unsupported translation. |
| Auth/account | Login/register/forgot/reset/verify, validation messages including Unicode password byte limit, profile/avatar/password changes, private noindex/no-store. |
| Community | Correct localized comment form, moderation state, own-comment editing, safe escaped text, pagination and notification preferences. |
| Engagement | Watchlist ordering/removal, alert DEMO restrictions, saved articles, newsletter double opt-in/confirmation/withdrawal/unsubscribe and account privacy. |
| SEO | Configured canonical, only real supported hreflang translations, `zh-Hans`, OG/Twitter/JSON-LD, sitemap/robots; historical `hy` records remain in the database and are absent from new public alternates. |

Do not create missing production translations merely to make this matrix appear populated. Use labeled, disposable test fixtures for all eight languages when needed.

## Roles and workflows

- [ ] Guest cannot open private previews/account/admin, including guessed IDs and direct Livewire actions. Private previews never enter sitemaps or shared response caches.
- [ ] Reader cannot reach CMS, moderation, advertising administration, AI controls or another reader's account/comments/watchlists/alerts.
- [ ] Editor can manage the permitted own drafts but cannot publish, promote roles, inspect another editor's private draft, approve advertising, change AI shutdown flags or inspect administrator-only commercial details.
- [ ] Administrator can use native CMS review/publish/schedule, moderation, newsletter previews and advertising controls. Use offline AI fixtures only; preserve emergency stop and no self-publishing.
- [ ] Revoke a disposable admin's role and verify the next request and direct mutation are denied; test logout/session expiry/back-button behavior.
- [ ] Exercise CSRF failure, malformed fields, oversized/unsafe uploads, expired/used password reset tokens and safe localized error pages. Never include actual passwords in test notes.
- [ ] Inspect browser Network: no market/provider calls on page views, no paid AI, no ad/CMP scripts with current disabled consent, no real email delivery or unexpected remote fonts.

## MySQL / workers / operations

Current execution status: **BLOCKED**. Use isolated staging resources; no command below authorizes destructive use against business databases.

- [ ] Restrict a test DB user to a clearly named disposable database. Apply all migrations, seed only test fixtures and rerun integration tests with MySQL 8+, `utf8mb4_unicode_ci`, UTC and strict SQL.
- [ ] Test Japanese/Chinese/Armenian text/JSON round-trips, case/accent/normalization slug collisions, uniqueness, restrict/cascade/null foreign keys, exact price strings and timestamp boundaries.
- [ ] Use two independent connections/workers for same-key replay, competing AI budgets, evidence changes, article publication, alert delivery and newsletter consent withdrawal. Record deadlock/retry outcomes and committed rows.
- [ ] Verify real shared locks from two application hosts, queue timeout below retry interval, one attempt for provider-facing jobs, Supervisor restart and scheduler heartbeat/overlap protection.
- [ ] Test interrupted/uncertain AI and SMTP recovery with deterministic fakes/trapped transport. No blind retry of uncertain charged work or email.
- [ ] Restore a MySQL backup plus public/private fixture files into a new environment using the correct separately-held key; validate rows/FKs/encrypted fields/hashes and measure duration.
- [ ] Check public root, HTTPS redirects, unknown Host, spoofed forwarding headers from outside the allowed proxy, Secure/HttpOnly/SameSite cookies, HSTS opt-in and no access to `.env`, private storage, repository files or executable uploads.

## Measured browser performance

Current LCP/INP/CLS/Lighthouse status: **NOT TESTED**, because page rendering was blocked. Measure production-built staging pages with recorded device, network/CPU profile and cache state. Capture a trace for initial load, ticker startup, menu/language interactions, article reading and comment/account interactions. Inspect actual image/font loading and layout shifts. Separate lab results from field measurements and define targets with the release owner; do not invent a score from server timings.

## Sign-off record

For each failure, record severity, reproduction, affected locale/width/role, evidence, owner and retest. Release requires closing P0/P1 findings and recording decisions for remaining P2 issues. The [readiness report](DEPLOYMENT_READINESS.md) and [runbook](PRODUCTION_RUNBOOK.md) list external prerequisites. No visual, MySQL, SMTP, provider-license, Google/CMP or production-operations sign-off has been granted by Phase 11.
