# CryptoNews production runbook

Prepared in Phase 11 on 2026-11-18. These are instructions for a separately authorized staging/deployment operation. **Nothing was deployed.** Follow the release gates in [DEPLOYMENT_READINESS.md](DEPLOYMENT_READINESS.md) and the [QA checklist](STAGING_QA_CHECKLIST.md). Substitute operator-controlled paths/accounts; the example domain is a placeholder, not a configured production domain.

## Runtime, secrets and web perimeter

Use Linux PHP **8.4+** matching the lockfile and enabled ctype, curl, DOM/XML, fileinfo, filter, hash, intl, mbstring, OpenSSL, PDO MySQL, session, tokenizer, zip and GD. Production queue workers also need **PCNTL**; it is unavailable on this Windows machine. Use MySQL 8+ with InnoDB, UTC and utf8mb4. Use the committed lockfiles with a current Composer and Node 22.12+ for asset builds. Do not expose the Vite development server publicly or run it as the production asset server.

Inject secrets through the deployment platform or an access-controlled environment file outside public serving. Inventory secret **names, owners, rotation dates and vault references**, never their values, in operations records. Preserve the established APP_KEY for restored encrypted fields and queues. Do not run `key:generate` on an existing deployment or restore. Keep APP_KEY and database credentials in a separately protected recovery vault. Changes to APP_URL or key material also affect signed links and unsubscribe identity; test planned migrations explicitly.

Required production settings, to configure in staging first:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://your-domain.example
DB_CONNECTION=mysql
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
CACHE_STORE=database
MARKET_CACHE_STORE=database
NEWS_DISCOVERY_CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=180
REDIS_QUEUE_RETRY_AFTER=180
MAIL_MAILER=log
LOG_CHANNEL=daily
LOG_LEVEL=warning
LOG_DAILY_DAYS=14
MARKET_DATA_MODE=demo
MARKET_DATA_RIGHTS_CONFIRMED=false
AI_EMERGENCY_STOP=true
AI_CANDIDATE_PROCESSING_ENABLED=false
AI_GENERATION_ENABLED=false
AI_TRANSLATION_ENABLED=false
AI_SCHEDULED_GENERATION_ENABLED=false
NEWSLETTERS_ENABLED=false
PRICE_ALERTS_ENABLED=false
ENGAGEMENT_REAL_EMAIL_ENABLED=false
ADVERTISING_ENABLED=false
DIRECT_ADVERTISING_ENABLED=false
ADVERTISING_MODE=disabled
ADSENSE_ENABLED=false
HSTS_ENABLED=false
HSTS_MAX_AGE=31536000
```

The log mailer above is a **restricted staging default**. It writes complete message bodies/secret links and is unsuitable for public account verification/reset delivery. Before public registration is enabled, separately authorize a transactional SMTP test, verify the sender/domain, TLS, SPF/DKIM/DMARC, bounce handling and sensitive-log policy, then choose SMTP. Engagement sending still requires its separate disabled controls. Do not treat local log output, SMTP acceptance or queue success as inbox delivery.

Set DB_HOST/PORT/DATABASE/USERNAME/PASSWORD and APP_KEY through secret injection, without putting values into command arguments, reports or Git. Use a non-root runtime DB account; reserve schema-change privileges for the deployment account. Require database TLS when the network requires it and test the actual certificate chain/hostname. Redis is an existing supported alternative for cache/session/queue drivers; confirm the installed PHP client and private authenticated connectivity before choosing it. All app nodes and workers must use the **same store and cache prefix**, including market/discovery overrides. Do not mix process-local array/file caches with multi-worker uniqueness locks.

Set `TRUSTED_PROXIES` to the actual comma-separated load-balancer IP/CIDR allowlist. Empty means trust none. Wildcards, `REMOTE_ADDR` and `/0` catchalls are rejected. Restrict direct origin access so outsiders cannot impersonate an allowed proxy. Forward only the intended For/Proto/Port headers; this application ignores forwarded Host. Set `TRUSTED_HOSTS` only for explicit additional literal hosts; APP_URL's host is included automatically. Unknown hosts are rejected outside local/testing, including health-check requests, so configure the load balancer to send the canonical Host.

Terminate HTTPS at the verified edge, redirect HTTP there and test generated canonical/auth/signed URLs under the trusted proxy. Do not force HTTPS globally in local development. Enable `HSTS_ENABLED=true` only after HTTPS/proxy/certificate checks; the application sends HSTS only on secure production requests, without preload or includeSubDomains. The compatible CSP baseline protects base/object/frame embedding; it does not enforce scripts. Tighten a nonce-based policy only after testing Filament/Livewire/Alpine and any separately approved integrations.

Point the web document root to **`<release>/public`**, as required by [Laravel deployment guidance](https://laravel.com/framework/docs/13.x/deployment). Only public assets and `public/index.php` should be served/executed. Deny dotfiles, source/repository/vendor paths, private storage, database files and PHP execution below uploaded-storage paths. Do not symlink all of storage; `public/storage` may point only to `storage/app/public`. Disable directory listings. Store confidential originals and private exports on the private disk and expose them only through explicitly authorized endpoints. Existing editorial raster uploads are public assets, including draft-associated images, and may contain original image metadata.

Use a dedicated service account. Give the web/queue processes write permission only to intended storage and bootstrap cache paths, not the application source or secret inventory. Use restrictive file/umask settings (for example secrets 0600, directories 0750, files 0640 where the service group permits access); do not use recursive 0777. Verify public-cache rules exclude logged-in, account, token, preview, article/comment Livewire and consent-dependent responses.

## Build, verify and release sequence

Build from a reviewed version in an isolated artifact/staging directory, with provider flags disabled. Production secret-bearing configuration caches are private deployment files and must never become public build artifacts.

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
npm ci
npm run build
php artisan app:check-production-readiness --json --no-interaction
php artisan migrate:status --no-interaction
php artisan route:list --except-vendor --no-interaction
php artisan schedule:list --no-interaction
```

Execute the full automated tests in a separate verification checkout **with dev dependencies** and an isolated test database; production `--no-dev` installations do not include PHPUnit or Pint. Recheck `composer audit --locked`, `npm audit` and `npm audit --omit=dev`; do not apply `npm audit fix --force`. Phase 11's picomatch advisory is unpatched upstream and confined to trusted build/dev paths; record any remaining risk decision before shipping. Exclude `public/hot`, `.tools`, `.env`, fixture databases, test output and private backups from the artifact. `node_modules` is not a PHP runtime requirement after assets are built.

Back up before approved schema changes and review forward compatibility with the old application. On the intended deployment only, use the non-destructive migration command, then optimization and supervised restarts:

```bash
php artisan migrate --force --no-interaction
php artisan config:cache --no-interaction
php artisan route:cache --no-interaction
php artisan view:cache --no-interaction
php artisan queue:restart --no-interaction
```

These commands were **not** run against production. Phase 11 exercised config/route caches in separate fixture paths with an explicit fictional APP_KEY and disabled integrations; the local `.env`/normal config caches were preserved. Inspect the config check output and stop the deployment if P1 settings fail. A zero exit is configuration evidence only; manual/external release gates still apply.

Do not clear shared cache indiscriminately: it contains uniqueness locks, cooldowns, scheduler coordination and market snapshots. Never use `migrate:fresh`, `db:wipe`, `queue:flush`, `queue:clear`, or a blanket retry during normal recovery. The application has no Phase 11 schema change to roll back.

## Workers and scheduler

The default database queue is available locally. Windows can run development workers, but it cannot verify PCNTL worker-kill semantics. Recommended Linux retry interval is **180 seconds**, comfortably above the longest 85-second job, consistent with [Laravel queue timeout guidance](https://laravel.com/framework/docs/queues#job-expirations-and-timeouts). On all nodes verify both configured `retry_after` and actual per-job timeout; a CLI worker option does not override a job's own timeout.

| Queue / work | Job timeout | Attempts / uniqueness | Recovery |
| --- | --- | --- | --- |
| `default`: crypto / metals refresh | 50 / 85 s | 1; unique 120 s; refresh lock 90 s | Last-good cached quotes persist, bounded adapter retries/cooldowns; next scheduler cycle respects due state. DEMO does no requests. |
| `default`: source ingestion | 45 s | 1; unique/lease 600 s, token-scoped | Controlled failed-run code, cooldown and explicit authorized `news:retry`; maximum attempts are bounded. |
| `ai`: generation / validation / storing, including translation stages | 75 s | 1; unique 90 s; database work/request keys | Resume recorded stages. Never retry uncertain provider cost as free work; reconcile accounting first. |
| `engagement`: delivery / digests / alerts | 60 s | 1; unique 90 s plus delivery keys and locks | Version/consent/quote checks before sending; uncertain SMTP outcomes are held, not resent automatically. |
| `default`: queued verification/reset/comment notification | Worker limit 85 s | Framework queued notifications; reset job payload encrypted | Inspect token validity/recipient and failed payload access; require controlled retry for transactional mail. |

For independently supervised worker processes, start commands such as:

```bash
php artisan queue:work database --queue=default --sleep=3 --tries=1 --timeout=85 --max-time=3600 --no-interaction
php artisan queue:work database --queue=ai --sleep=3 --tries=1 --timeout=85 --max-time=3600 --no-interaction
php artisan queue:work database --queue=engagement --sleep=3 --tries=1 --timeout=85 --max-time=3600 --no-interaction
```

Configure Supervisor/systemd automatic restart, correct working directory/PHP binary/environment, non-root identity, restricted logs, and a stop grace period of at least **120 seconds**. Size worker counts only after MySQL/shared-lock tests and measured load; the AI budget/concurrency settings are additional limits, not permission to add paid workers. Keep default transactional notifications separate from batch throughput when scaling. `queue:restart` requests graceful restart after the current job; a supervisor must start replacement workers.

Run one scheduler leader per deployment until multi-host scheduling is verified. The configured tasks use `withoutOverlapping`; provider/delivery jobs also have independent locks/keys. Shared cache must survive restarts. Cron example (replace `/srv/cryptonews/current`):

```cron
* * * * * cd /srv/cryptonews/current && /usr/bin/php artisan schedule:run --no-interaction >> /var/log/cryptonews-scheduler.log 2>&1
```

Schedules: approved article publication each minute; pending engagement dispatch each minute; gated price alerts/digests/AI each minute; discovery every 15 minutes when enabled; due market jobs each minute with their own 10/30-minute refresh intervals; stale batch bookkeeping daily after seven days. Advertising eligibility is evaluated against campaign dates on serving; there is no advertising cron to enable. `schedule:list` lists configured tasks even when runtime conditions currently disable them. No OS task/supervisor was installed here. Add and verify scheduler heartbeat/error alerting in staging.

## Monitoring and incident recovery

Use existing database/admin status pages plus restricted application/worker/scheduler logs. `/up` checks application boot, not database, mail, queue drain or provider availability. Pair it with synthetic staging checks and infrastructure health probes that do not send email or request paid providers.

| Signal | Inspect / response |
| --- | --- |
| Failed/stalled queues | `php artisan queue:failed --no-interaction`, Supervisor process state, backlog/oldest-job age in `default`, `ai`, `engagement`. `queue:monitor` can emit size-threshold events; alert transport/handlers still need operations configuration. |
| Scheduler | Cron exit/log/heartbeat older than two expected intervals; inspect overlap locks before changing them. Check PHP path, working directory, permissions and cache connectivity. |
| Discovery | `php artisan news:status --no-interaction`, source failure/lease/cooldown and ingestion controlled error codes. Revalidate publisher permissions/URLs before one authorized retry. |
| AI | Admin generation runs, evidence/quality state, emergency stop, exhausted budgets, held uncertain reservations, request-sent versus stored stages. Keep stop active during incidents; reconcile actual provider billing before release/settle/retry. |
| Markets | Snapshot fetched/provider timestamps, delayed/stale/unavailable status, last success, HTTP/auth/cooldown counters and attribution/license. Do not fill missing LIVE quotes with DEMO data or enable LIVE as a repair. |
| Engagement/mail | `queued/sending/local/accepted/uncertain/failed` delivery state, consent/version, provider message ID, queue failures and SMTP/bounce evidence. `accepted` is not inbox delivery; do not resend uncertain outcomes blindly. |
| Advertising | Global/env controls, placement/campaign review/date/creative hash, ads.txt verification and consent binding. Counters represent origin request/eligible HTML, not visible impressions or revenue. Leave shutdown active during an issue. |
| Database/storage | Connections, deadlocks, disk usage, backup age, FKs/orphans, media 404/error rates, encrypted-record decryption and restore checks. Escalate before deleting rows/files. |

Proposed alerts: unavailable application immediately; no active worker or rising old backlog after two minutes; scheduler heartbeat >3 minutes; any failed job, uncertain paid run, unexpected real integration activity, missed backup or restore-check failure. Tune thresholds on staging to actual workloads. No alert transport or paid monitoring account was connected in Phase 11.

Use daily logs with an approved retention period; 14 days is a starting proposal, not a legal retention determination. Restrict failed-job tables, backups and log readers because they may contain personal content. Never log Authorization headers, environment dumps, request/password bodies or provider payloads. At the edge record normalized route paths instead of full query strings/token paths for reset/verification/newsletter links. Keep local log-mail files private and out of public downloads/backups shared for diagnostics. Test actual proxy/access-log redaction and error reporting on staging; generic infrastructure exception logging is not certified leak-free.

For a failed job, record its UUID and business run/delivery state first. Fix the cause, confirm no external side effect already happened, then use the domain-specific authorized recovery action where available (`news:retry`, `ai:retry`, engagement unsent retry). Run each command's `--help --no-interaction` for installed arguments. Never `queue:retry all` for AI or email. Inspect failed payloads only in a restricted environment, never paste them into tickets. Review and approve any later failed-job pruning policy; only batch-bookkeeping pruning is currently scheduled.

## Backup and disposable restore

The following is a **proposed production policy**, not an installed backup service. Approve owners, region, access controls, retention, cost, privacy obligations and recovery targets before launch. Initial targets: database RPO ≤15 minutes with verified binlogs; full application RTO ≤4 hours. These are targets, not measured achievements.

| Item | Proposed frequency / retention | Protection |
| --- | --- | --- |
| MySQL | Daily consistent full backup; ship binlogs at least every 5 minutes for point-in-time recovery. Retain 30 daily backups and 12 monthly snapshots if approved. | Encrypted, access-controlled off-host storage; test InnoDB snapshot/binlog coordinates, backup role privileges, size and completion checks. |
| Public uploaded media | Hourly incremental/versioned backup plus daily manifest, aligned to DB recovery points; matching retention. | Preserve paths/content hashes; no PHP execution on restore; encrypted off-host copy. |
| Private files | Hourly/versioned backup plus daily manifest; privacy-driven retention. | Separate private destination/ACL, never public symlink; verify confidential-data access rules. |
| Secrets/config inventory | Backup vault/config changes immediately, version the inventory of names/owners/vault references. | Keep actual secrets in a separate encrypted recovery vault and restricted access process, never Git or this report. |
| Release artifacts | Retain the deployed artifact, both lockfiles, schema version and build manifest with each release. | Reproducible, immutable, no real `.env` or secret-bearing config cache in public artifact storage. |

For MySQL, use the platform's supported physical/logical backup method. A logical `mysqldump` needs a dedicated secret option file or secret manager, `--single-transaction --quick`, utf8mb4, and inclusion of relevant routines/triggers/events and binlog coordinates as required. Do not put passwords on command lines. Do not assume a dump's exit code proves restoreability. Large-database recovery may require a different method; measure it on staging.

The **executed** restore drill is `php artisan test --compact tests/Feature/DisposableRestoreTest.php --no-interaction`. It creates new disposable SQLite databases, uses consistent `VACUUM INTO`, copies only that backup to a different fixture file, restores a fixture ZIP to a distinct directory, compares all **50 tables** and **3 file hashes**, checks integrity/FKs, Unicode and decryption with an explicit fake key. `ProductionConcurrencyTest` separately starts two real PHP processes over a disposable SQLite database and proves budget contention/idempotency without paid requests. Tests never migrate or restore over the business database. Ignored artifacts live below `storage/framework/testing/phase11-*`.

Before production approval, repeat this procedure on a disposable **MySQL 8+** target:

1. Select a known backup/recovery point and corresponding media/private-file manifests; record expected rows, key version and artifact version without recording secret values.
2. Create an isolated target with no route to real email/AI/provider endpoints. Verify the target DB name/host is disposable and credentials are restricted before any import.
3. Restore MySQL there, restore public/private fixture files into separate roots, and inject the matching key from the recovery vault. Never replace the current application database or rotate its key during this drill.
4. Check FKs/orphans, row counts and selected digests; JA/ZH/HY text, JSON, dates, exact prices, encrypted newsletter/commercial fields, consent records, held AI budget amounts, campaign relationships and media hashes.
5. Boot the matching application artifact with integrations disabled; run isolated smoke/security/SEO tests. Keep old queued side effects paused until inspected; stale sessions, locks and delivery state need explicit recovery decisions.
6. Record recovery point, elapsed restore/validation time, failures, tester and resulting RPO/RTO. Obtain approval before deleting disposable recovery resources.

MySQL restore and off-host backup execution are **BLOCKED** in this workspace. The ignored pre-audit SQLite business snapshot exists for preservation only; it was not used to overwrite or restore business data.

## Rollback and emergency shutdown

Keep an immutable previous release and its asset manifest. For a code-only failed release, place the edge/application into the approved maintenance procedure, pause/stop supervised work gracefully, switch back to the compatible prior artifact, rebuild private config/route/view caches for that artifact and restart supervised workers. Preserve all current database rows, uploads and established key. Smoke-test health, auth, private previews, localized news and disabled integration flags before reopening traffic.

Do not run database down-migrations or restore an older database as a casual code rollback; that can erase new articles, consent, comments, accounting and delivery evidence. If schema compatibility is uncertain, remain in maintenance and choose a reviewed forward repair. A disaster database restore requires a separate approved recovery point, preserved current snapshot and reconciliation of every potentially sent email/charged request before replaying queues.

Emergency controls: keep `AI_EMERGENCY_STOP=true` and all generation flags off; disable discovery in its existing control center; keep markets DEMO; disable newsletter/price-alert/real-email controls; use advertising shutdown plus env gates. Refresh deployment configuration caches and gracefully restart workers after env changes. Inspect in-flight side effects rather than assuming a stop flag reverses an already sent request. Never activate an integration to test recovery during this phase.

## External activation prerequisites

- Core hosting: real DNS/domain, TLS, Linux workers/cron, MySQL, shared cache/session/locks, storage, backup/key vault, restricted logs and alert ownership. Transactional SMTP requires separately approved sender/account and delivery tests before public signup.
- LIVE markets: redistribution/commercial rights, verified provider tier/credentials/rate limits, actual supported quotes/units and required attribution; DEMO stays visible until a separately approved change.
- AI: approved funded account, independently verified model pricing and limits, evidence/source permissions, operator budget/reconciliation tests and explicit activation approval. Automatic publication remains unavailable.
- Discovery: approved publisher endpoints and rights, source ownership, metadata permissions and safe network egress; preserve disabled state until reviewed.
- Scheduled engagement: verified SMTP/consent/unsubscribe/bounce workflow, editorial audience approval and authorized delivery tests; no newsletter scheduling activation here.
- Advertising: reviewed advertisers/creative/destinations and regional financial-ad requirements; approved AdSense account/publisher/seller records if chosen; a genuine applicable certified CMP and tested consent/refusal/withdrawal flow. Phase 11 makes no Google approval, CMP certification or legal-compliance claim.

Phase 11 stops at documented implementation and local verification. Provisioning, deployment, provider purchases, real messages, integration activation and Phase 12 require a new instruction.
