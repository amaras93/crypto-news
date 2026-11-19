# Automated AI news publishing: implementation and deployment

Prepared 2026-11-19. Local implementation only; production activation was not performed. Existing public templates and design are preserved. No dependency change, credential change, paid AI request or production test article was made. The previous phase's production-readiness report remains historical; its AI section described the former mandatory-review implementation.

## Workflow and verification

The existing `news:discover` schedule ingests only enabled, administrator-approved RSS/Atom sources. Normalization, ownership attribution, source rights, freshness and duplicate checks remain in place. The existing `ai:dispatch` schedule now also advances automatic work:

1. Queue candidate verification on `ai`; require a supported topic, selected existing category, fresh timestamp, no duplicate flag and a permitted primary source. No per-candidate human approval is required.
2. Retrieve bounded HTML from the approved primary publisher's exact website host, reusing the discovery HTTP client: HTTPS, public-address DNS checks and DNS pinning, no redirects/proxies, response limit and timeouts. Separate `primary_material_permitted` permission is required; RSS permission alone does not permit fetching article pages.
3. Require a matching headline and primary publication timestamp (within five minutes of RSS metadata), and at least two substantial paragraphs from `article`/`main`. Otherwise hold automatically. Extracted assertions remain attributed to the primary publisher; this does not certify their independent truth. JavaScript-only pages, PDF-only filings, paywalls, missing dates and unsupported layouts are held rather than guessed. The adapter deliberately does not crawl arbitrary linked URLs.
4. Store `automated_primary` evidence with document/claim hashes, retrieval time, source version and URL. `verified_by` and human `verified_at` remain null. Changed evidence or revoked source permission invalidates subsequent stages.
5. Generate original English through the existing Responses API provider, JSON schema, reservations and quality checks. Category comes from the existing classifier; source citations are generated from evidence IDs, not invented URLs. Headline, excerpt, takeaways, body, SEO and publication metadata use existing article models.
6. Run a separate paid semantic verification of the saved English against the evidence. Reject unsupported facts, misleading attribution, copying, contradictions or uncertainty. Record `automated`, never a fabricated human reviewer.
7. Generate each of ru, fr, de, es, it, ja and zh. Preserve exact numerical strings, entities, dates and source references. Apply deterministic checks and separate semantic/meaning/language verification to each saved translation.
8. Publish through `ArticlePublication` only after all eight languages have current passing generation and verification records, matching hashes, valid evidence, safe URLs, and publication allowance. Publication is atomic, under database locks, and audited as `automatic_published` without a human reviewer. Existing public scopes expose these versions on the homepage, listings/categories/search, article routes, sitemap and canonical/hreflang tags.

Normal success uses **16 paid calls**: one English generation, seven translations and eight separate verifications. Verification is an automated model assessment, not an infallible fact guarantee. Originality, unsupported numbers/symbols/currency, direct quotations, prompt injection, unsafe predictions, script mismatch, translation entity/number preservation and citation checks remain enforced. The verifier additionally checks the actual requested language and factual meaning, including European languages that share the Latin alphabet.

Stages are durable and idempotent. Shared dispatcher/run/worker-slot locks and unique active/request keys prevent duplicate calls. Received/validated stages resume without reissuing the provider request. Only rate limits and unsent queue failures get automatic retries, after provider backoff, at most three total attempts. Unknown outcomes retain their full spending reservations and never retry automatically; administrator reconciliation requires actual provider billing evidence. Missing evidence, final translation/provider/quality failures and exhausted budgets are held for attention.

## Administrator controls

AI News Control Center provides separate automatic publishing, candidate processing, generation, automatic translation, scheduling, emergency stop, categories, author, model and budget controls. The enabled-sources link opens the existing source manager with per-source discovery/AI/primary-material permissions. No categories selected means no unattended candidates. Candidate/article tables display automation status and error reasons; generation runs show attempts, output, quality, usage and recovery. Held/unpublished content remains private.

Human review remains the default optional mode. Automatic mode requires both the server and database settings. Automatic articles remain editable, unpublishable and soft deletable. Any manual save, human review or unpublish pauses that article's automation; scheduler runs cannot republish it. A human-reviewed revision can use the existing manual publication workflow. Content changes invalidate translation reviews as before.

## Database migration

`database/migrations/2026_11_19_082553_add_automatic_ai_publishing.php` adds automatic settings/category selection, explicit primary-material permission, candidate/article automation state, run provenance, and automated evidence metadata. Human verifier/time columns become nullable so automation does not impersonate a person. Existing records and safe disabled defaults are preserved. Rollback removes added fields but deliberately leaves verifier/time nullable to avoid inventing human verification on existing automated rows. Prefer a forward fix; do not roll old code back while automatic published content remains live.

Pre-push verification also ran the complete migration chain on a fresh, disposable localhost MariaDB 10.11.6 instance: up/down/up/down, foreign-key preservation and index cleanup passed. MySQL/MariaDB SQL compilation and SQLite round trips passed too. The forward migration adds fields/indexes and only relaxes verifier/time nullability; it does not delete or change existing business values or change the unsigned foreign-key type. This verifies compatibility with the tested MariaDB version, not the unseen production schema or its lock duration. It has not been applied to the business database or production. Take a verified backup and rehearse the upgrade against a disposable copy of the actual production schema before deploying. Never use `migrate:fresh`, `db:wipe`, broad seeding or `key:generate` in deployment.

## Pilot configuration (prepare with all gates stopped)

Keep server configuration and database switches disabled during deployment. Existing stored zero limits stay zero until deliberately configured. The settings seeder initializes a missing row with the pilot ceilings and emergency stop; it never overwrites an existing row.

```dotenv
AI_EMERGENCY_STOP=true
AI_CANDIDATE_PROCESSING_ENABLED=false
AI_GENERATION_ENABLED=false
AI_TRANSLATION_ENABLED=false
AI_SCHEDULED_GENERATION_ENABLED=false
AI_AUTOMATIC_PUBLISHING=false
AI_PROVIDER=openai
AI_MODEL=
OPENAI_API_KEY=
AI_DAILY_LIMIT_MICROUSD=1000000
AI_MONTHLY_LIMIT_MICROUSD=10000000
AI_MAX_ARTICLES_PER_DAY=1
AI_MAX_TRANSLATION_LANGUAGES=7
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
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
CACHE_STORE=database
```

Production needs PHP 8.4 on Linux with PCNTL, cURL, DOM/XML, intl, mbstring, PDO MySQL, persistent queues and shared database/Redis locks. Configure canonical HTTPS APP_URL, normal secure production settings and the existing application key; never regenerate it because evidence and run payloads are encrypted. Keep independent credentials in server secret storage. The AI queue uses the same application database, not another application's tables or workers.

The server ceilings and database ceilings are combined using the smaller value. Pilot is one English article/day, seven translation languages, USD 1/day and USD 10/month, one concurrent request. Generation, translation, semantic verification, retry attempts and uncertain outcomes all use the existing ledger. Actual overruns are recorded honestly and block further reservations. After successful real-world validation, an administrator may raise **both** article ceilings to three/day; keep spending ceilings unless a separate change is intended. Do not assume three articles fit the existing budget.

## Pricing and provider access

Checked the official [GPT-5.4 mini model page](https://developers.openai.com/api/docs/models/gpt-5.4-mini) on 2026-11-19: standard text input USD 0.75, cached input USD 0.075, output USD 4.50 per million tokens; Responses and structured outputs are supported. An example compatible snapshot is `gpt-5.4-mini-2026-03-17`. This is a deployment example, not an activated selection or proof of account access. Recheck the [official pricing page](https://developers.openai.com/api/docs/pricing), exact model availability, account tier and endpoint pricing immediately before activation. Regional/other pricing modifiers must be included in configured effective rates. Application prices have not been changed to these example rates.

For that standard-price example, after actual verification configure the same model in the Control Center, AI_MODEL and AI_PRICING_MODEL; input=750000, cached=75000, output=4500000 micro-USD per million tokens, write=0 when no separately priced cache writes apply. Set AI_PRICING_VERIFIED_AT to the actual UTC verification date. Missing, mismatched, future or more-than-30-day-old pricing blocks requests. Reverify before that expires. A missing cache-write rate with reported writes holds uncertain billing.

The offline estimator now includes all eight verifications and an explicit attempt multiplier:

```sh
php artisan ai:estimate --model=gpt-5.4-mini-2026-03-17 --articles=1 --languages=7 --attempts=1 --no-interaction
php artisan ai:estimate --model=gpt-5.4-mini-2026-03-17 --articles=3 --languages=7 --attempts=3 --no-interaction
```

Default workload assumptions: 6,000/2,000 English input/output tokens; 3,500/2,500 per translation; 10,000/1,000 per verification. At those example rates that is USD 0.206625/article and USD 0.619875/article with a three-attempt multiplier. Actual reservations use bounded payload/schema sizes and output maxima, not these illustrative averages. Taxes/provider adjustments are not part of the application's token ledger. No estimate proves actual model accuracy, availability or billing acceptance.

## GitHub publication

Repository origin: `https://github.com/amaras93/crypto-news.git`. The owner authorized a normal push directly to `origin/main` for this release. All 38 implementation files were reviewed and explicitly staged. The real `.env`, local databases, backups, credential files and generated runtime artifacts remain excluded. `.env.example` contains blank credentials and disabled activation flags. Redacted Gitleaks scanning of the staged change set passed.

```sh
git diff --cached --check
git diff --cached --stat
git commit -m "Implement guarded automatic multilingual AI news publishing"
git push origin main
git rev-parse HEAD
git ls-remote origin refs/heads/main
```

The local and remote SHAs must match after the push. No force push or VPS connection is part of this task. In CI, use only a disposable database and mocked providers; phpunit.xml forces AI credentials blank and activation off. GitHub publication does not authorize production deployment or activation.

## Deployment and dedicated AI worker commands (not executed)

The following uses `/var/www/cryptonews/current`, `/usr/bin/php8.4` and `www-data` as explicit deployment examples. Replace them in the Supervisor file with the actual CryptoNews checkout, PHP executable and app account before installing. Do not edit other applications' Supervisor files, run `update all`, restart Supervisor globally or restart unrelated workers.

On a disposable staging checkout run the migration and full tests with a dedicated test environment and fake providers. Back up the real database and existing application key before the production forward migration. Leave AI server flags stopped and database emergency stop enabled throughout deployment. Stop only an existing CryptoNews AI program before replacing application code, if one is installed (`sudo supervisorctl stop cryptonews-ai:cryptonews-ai_00`). Adjust the example checkout/PHP/account to the actual server before using the commands. Use a maintenance window: new indexes and nullable-column alterations can take locks. MariaDB [ALTER TABLE operations](https://mariadb.com/docs/server/reference/sql-statements/data-definition/alter/alter-table) can take locks, and [DDL statements cause implicit commits](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit); if a step fails, keep maintenance mode on and inspect migration status and the partial schema before attempting recovery. Do not blindly rerun or roll back the entire application.

```sh
set -eu
cd /var/www/cryptonews/current
php artisan down --retry=60 --no-interaction
git fetch origin
git pull --ff-only origin main
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci --ignore-scripts
npm run build
php artisan migrate --force --no-interaction
php artisan db:seed --class=AiPipelineSettingSeeder --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan schedule:list --no-interaction
php artisan app:check-production-readiness --json --no-interaction
sudo install -m 0644 scripts/cryptonews-ai.supervisor.conf /etc/supervisor/conf.d/cryptonews-ai.conf
sudo supervisorctl reread
sudo supervisorctl update cryptonews-ai
php artisan up --no-interaction
sudo supervisorctl status 'cryptonews-ai:*'
```

Supervisor autostart is false; STOPPED is expected after installation and its status command can return nonzero for that reason. The file owns only `queue=ai`, uses a single process, worker timeout 75 seconds, retry interval 180 seconds, and stop grace 120 seconds. Queue retries are one; application records own bounded provider retries. Do not add `ai` to additional workers without accounting for the concurrency gate. RSS ingestion remains on the existing default queue; that worker must already run. Preserve its configuration and other applications' services.

The readiness command retains its earlier phase requirement that paid integrations stay disabled; run it as a stopped-deployment baseline, not as certification of the later activated AI mode. It never checks live credentials or provider acceptance.

The existing Laravel scheduler needs one application-scoped cron entry (do not duplicate it):

```cron
* * * * * cd /var/www/cryptonews/current && /usr/bin/php8.4 artisan schedule:run --no-interaction >> storage/logs/scheduler.log 2>&1
```

These are commands for the later, separately authorized activation step, **not performed here**:

```sh
sudo supervisorctl start cryptonews-ai:cryptonews-ai_00
sudo supervisorctl status cryptonews-ai:*
```

Start the worker initially with all AI flags stopped to validate its health. Before enabling server/database gates, confirm production credentials securely, exact-model Responses access, pricing, provider project spending controls, source permissions, budgets, MySQL locks and scheduler/worker health. Then deliberately enable the five processing/translation/scheduling/automatic server flags, set server emergency stop false, rebuild config cache, and restart only `cryptonews-ai:cryptonews-ai_00`. In the Control Center select existing categories, enabled sources and the actual author, confirm pilot limits, enable matching database switches and release its emergency stop. This activation is an owner/operator action after those checks; normal qualifying articles require no editorial approval.

For incident response use Control Center emergency stop first, then `supervisorctl stop cryptonews-ai:cryptonews-ai_00`; keep uncertain cost holds. Inspect controlled error codes and private provider billing evidence before recovery. Article unpublish/edit/delete is always available. No automatic retry of an unknown paid outcome and no bulk production test publication is permitted.

## Validation and remaining blockers

Pre-push full regression run: **859 passed, zero skipped, 18,121 assertions**, exit 0, 212.03 seconds. The previously skipped real MariaDB integration was enabled against a new disposable localhost MariaDB 10.11.6 database. All 14 migrations completed up/down/up/down; SQL identifier compilation for MariaDB/MySQL and SQLite round trips also passed. This supersedes the earlier 857-pass/one-skip baseline and includes all 26 automated-publishing cases, including optional human-review takeover. Pint, git diff --check, the redacted staged Gitleaks scan and comparison against actual local secret values passed. No runtime paths or private environment/database/credential files are staged; the real .env, business database and dependency manifests/lockfiles are unchanged. Application tests use mocked HTTP providers/DNS and an in-memory SQLite database; only the opt-in migration test uses the separate empty localhost MariaDB fixture. Coverage includes discovery to automatic publication, eight languages, source/identity/date/format limits, missing evidence, duplicates, source revocation, semantic rejection, failed translations/providers, daily/monthly budgets, article/publication ceilings, emergency stop, bounded retries, response recovery, cache locks, administrator controls/unpublishing and all public SEO surfaces.

Remaining blockers: actual production API credentials/account/model access and pricing acceptance; documented approved primary-source permissions; Linux Supervisor/cron/shared-lock verification; staging checks against the actual production schema and real concurrency; real-world multilingual factual/translation quality and cost validation. Earlier unrelated staging/infrastructure blockers remain applicable. The testing-best-practices skill referenced by AGENTS.md was unavailable locally; repository PHPUnit conventions were followed. No paid request, real news publication, VPS connection, production migration or activation was executed. A disposable MariaDB test instance was used only on localhost and shut down after verification.


## Changed files

- Automation: `app/Support/AiAutomation.php`, `app/Support/AutomatedEvidence.php`, `app/Jobs/VerifyNewsCandidate.php`.
- Existing pipeline: `app/Support/AiDrafting.php`, `app/Support/AiEditorialReviewService.php`, `app/Support/AiEvidence.php`, `app/Support/AiOutputSchema.php`, `app/Support/AiQuality.php`, `app/Support/AiSettings.php`, `app/Support/OpenAiDraftProvider.php`, `app/Support/FeedHttpClient.php`, `app/Support/NewsSourceManager.php`, `app/Support/ArticleEditor.php`, `app/Support/ArticlePublication.php`, `app/Support/PublicUrl.php`.
- Commands: `app/Console/Commands/ScheduleAiDrafts.php`, `app/Console/Commands/EstimateAiCost.php`.
- Models: `app/Models/AiGenerationRun.php`, `app/Models/AiPipelineSetting.php`, `app/Models/CandidateEvidence.php`, `app/Models/NewsCandidate.php`, `app/Models/NewsSource.php`, `app/Models/Article.php`, `app/Models/ArticleTranslation.php`.
- Administration: `app/Filament/Pages/AiNewsControlCenter.php`, `resources/views/filament/pages/ai-news-control-center.blade.php`, `app/Filament/Resources/Articles/ArticleResource.php`, `app/Filament/Resources/NewsCandidates/NewsCandidateResource.php`, `app/Filament/Resources/NewsSources/NewsSourceResource.php`, `app/Filament/Resources/CandidateEvidence/CandidateEvidenceResource.php`.
- Configuration/schema: `.env.example`, `config/ai.php`, `database/seeders/AiPipelineSettingSeeder.php`, `database/migrations/2026_11_19_082553_add_automatic_ai_publishing.php`.
- Tests: `phpunit.xml`, `tests/Feature/AutomatedPublishingTest.php`.
- Deployment/report: `scripts/cryptonews-ai.supervisor.conf`, `docs/AUTOMATED_AI_PUBLISHING.md`.
