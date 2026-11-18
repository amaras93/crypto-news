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

## MariaDB 10.11 migration compatibility and empty-staging recovery

Compatibility correction, 2026-11-18. This section addresses SQLSTATE 42000 / error 1059 from `2026_11_17_082408_add_news_discovery_foundation` and supersedes the historical lack of MariaDB DDL execution evidence for this specific correction. It does not authorize production deployment or change the other readiness gates.

### Correction and verification scope

All 13 migration files were inspected and their complete `up`/reverse-order `down` statements compiled through the installed Laravel 13 MariaDB and MySQL grammars. One generated identifier exceeded the 64-character limit:

| Old generated name | Length | Explicit replacement | Length |
| --- | --- | --- | --- |
| `candidate_source_references_news_candidate_id_news_source_id_index` | 66 | `csr_candidate_source_index` | 26 |

The replacement remains a non-unique index on `(news_candidate_id, news_source_id)`, with unchanged column order. Columns, relationships, other forward indexes and application behavior are preserved. The discovery migration's rollback drops the owning table, so the renamed index needs no separate name-based drop. Other rollback references were checked against the constraints actually created.

Full rollback testing also exposed existing SQLite errors when indexed columns were dropped before their indexes. The editorial-user, community, AI and sponsorship `down()` methods now drop the affected indexes explicitly before dropping columns. The AI candidate foreign key is removed before its unique index and column. Those constraint/index names are unchanged; the array-based foreign-key drop preserves SQLite compatibility and generates the same named MariaDB/MySQL constraint. No new migration or data backfill is introduced. Already-applied databases are not automatically renamed or modified by editing these historical migration files.

`tests/Feature/MigrationIdentifierTest.php` provides:

- Complete-chain SQL identifier checks for MariaDB 10.11.6 and MySQL 8.0, including generated indexes, unique constraints and foreign keys, plus matching name-based foreign-key rollback references. PDO execution is forbidden in these compilation tests.
- An actual isolated in-memory SQLite migration/rollback/reapply/final-rollback check, including the index's name, column order, non-unique property and foreign-key preservation.
- An optional real local MariaDB/MySQL test, requiring an explicitly named `cryptonews_migration_test_*` empty database and loopback host. It refuses existing tables and never wipes them. Without `CRYPTONEWS_MIGRATION_TEST_CONFIG`, only this optional integration case is skipped.

Real MariaDB **10.11.6** execution passed all 13 migrations, full rollback, reapplication and final rollback on a separately initialized, loopback-only disposable server. The official archive's SHA-256 was verified; no Windows service or application dependency was installed. The test also reproduced error **1059**, proved that the table survives the failed index, and proved that a blind corrected rerun fails with error **1050** (table already exists). Only deliberately created fixture tables were cleaned up; production was never contacted. The broader application suite continues to use isolated SQLite; this is not a claim that all feature/data/concurrency workflows ran on MariaDB. MySQL 8 SQL compilation passed, but a real MySQL 8 server was not used.

Verification completed with **833 tests / 17,410 assertions**, zero failures/errors/skips, with the real MariaDB integration case enabled. Pint passed. On another newly created disposable MariaDB database, standard Artisan `migrate`, `migrate:status`, `migrate:rollback --step=13`, `migrate` and `migrate:status` also exited successfully; all 13 migrations showed `Ran` after reapplication. The local `.env`, local SQLite database, dependency manifests and lockfiles remained unchanged. No frontend code changed, so a frontend build was not required for this database-only correction.

MariaDB documents the [64-character identifier limits](https://mariadb.com/docs/server/reference/sql-structure/sql-language-structure/identifier-names) and [implicit commits for DDL](https://mariadb.com/docs/server/reference/sql-statements/transactions/sql-statements-that-cause-an-implicit-commit). MySQL documents the corresponding [identifier limits](https://dev.mysql.com/doc/refman/8.0/en/identifier-length.html). Laravel allows explicit [index names](https://laravel.com/framework/docs/13.x/migrations#creating-indexes).

### Why an ordinary retry or rollback is unsafe

Laravel logs a migration as complete only after its `up()` succeeds. MariaDB can retain earlier CREATE TABLE/ALTER TABLE statements when a later index fails. Consequently, the failed discovery migration may be absent from the `migrations` table even though some of its tables exist, and `migrate:rollback` can target the preceding successful batch instead. Editing the index name neither removes those residual tables nor repairs their definitions.

Do not rerun against that partial schema, insert a fabricated successful migration-history row, suppress the error with table-exists checks, disable foreign-key checks or perform a blanket rollback. For any database that may contain valuable data, preserve a consistent backup and obtain an instance-specific repair plan based on the actual schema/history. No production repair SQL was run or assumed here.

### Exact safe procedure: a new, initially empty staging database

Use a **separate staging checkout and a newly created database**. Leave the VPS's existing database, its partial tables and its `.env` untouched. The explicit names below are dedicated examples; if either already exists, choose another unused name. The CREATE statements intentionally omit `IF NOT EXISTS`, so accidental reuse fails instead of proceeding.

1. On the MariaDB host, an authorized database administrator creates the dedicated staging database/user. Open the client interactively (`mariadb -u <database-administrator> -p`) and run the following, replacing the quoted password placeholder with a new staging-only secret. Never paste real credentials into Git, tickets or chat. Match the account host to `DB_HOST`; this example uses local TCP.

```sql
SELECT VERSION();
CREATE DATABASE cryptonews_staging_recovery_20261118
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'cryptonews_staging_20261118'@'127.0.0.1'
    IDENTIFIED BY 'REPLACE_WITH_A_NEW_STAGING_ONLY_SECRET';
GRANT ALL PRIVILEGES ON cryptonews_staging_recovery_20261118.*
    TO 'cryptonews_staging_20261118'@'127.0.0.1';
```

2. Use a **new directory** accessible to the staging operator. The `/srv/cryptonews-staging-recovery-20261118` path below is an example, not the production path. Authenticate Git with the operator's existing secure GitHub/deploy-key mechanism; do not put a token into the clone URL. If the destination exists, stop and choose a fresh directory.

```bash
git clone --branch main --single-branch git@github.com:amaras93/crypto-news.git /srv/cryptonews-staging-recovery-20261118
cd /srv/cryptonews-staging-recovery-20261118
git log -1 --oneline
composer install --prefer-dist --no-interaction
install -m 600 .env.example .env
```

Confirm this checkout contains the explicit `csr_candidate_source_index` correction before continuing. Do not copy an existing production `.env` or production configuration cache. The new staging file must contain only staging credentials and all existing integration shutdown defaults. Set these values using a secure editor:

```dotenv
APP_ENV=staging
APP_DEBUG=false
APP_URL=http://127.0.0.1
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=cryptonews_staging_recovery_20261118
DB_USERNAME=cryptonews_staging_20261118
DB_PASSWORD= # Set the new staging-only password securely here.
DB_CHARSET=utf8mb4
DB_COLLATION=utf8mb4_unicode_ci
CACHE_STORE=database
SESSION_DRIVER=database
QUEUE_CONNECTION=database
MAIL_MAILER=log
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
```

Also ensure `DB_URL` is unset: a supplied database URL can override the separate connection fields. Keep API keys/publisher IDs empty, discovery disabled and workers/scheduler stopped. Do not expose this migration-test checkout as a public site. The installed application supports both `mysql` and `mariadb` drivers; these instructions explicitly choose its MariaDB connection.

3. Using the **staging account**, confirm the selected database name, server version and absence of tables. The password prompt must remain interactive:

```bash
mariadb --protocol=TCP --host=127.0.0.1 --port=3306 --user=cryptonews_staging_20261118 --password cryptonews_staging_recovery_20261118
```

```sql
SELECT DATABASE(), VERSION();
SELECT COUNT(*) AS existing_tables
FROM information_schema.tables
WHERE table_schema = DATABASE();
```

Require the exact new database name and `existing_tables = 0`. If any table exists, stop; do not wipe it. Exit the SQL client. Check that no externally exported `DB_URL`/`DB_*`/`APP_CONFIG_CACHE` variables override the staging file, and inspect the effective non-secret Laravel settings:

```bash
php artisan config:clear --no-interaction
php artisan config:show database.default --no-interaction
php artisan config:show database.connections.mariadb.host --no-interaction
php artisan config:show database.connections.mariadb.database --no-interaction
```

Do not dump the entire database configuration, which contains credentials. An exported `APP_CONFIG_CACHE` must not point at the production artifact; resolve that before any cache command.

4. Only in this **new checkout with its new empty database and blank APP_KEY**, generate a staging-only key and run the normal migration command. This does not rotate the existing application's key.

```bash
php artisan key:generate --no-interaction
php artisan migrate --force --no-interaction
php artisan migrate:status --no-interaction
```

Require all 13 current migrations to show `Ran`, including discovery, AI, engagement, exact-price preservation and advertising. Do not run seeders, queue workers, scheduler or integration refresh commands for this verification.

5. Reconnect to the dedicated staging database and verify the actual index/constraints. `SHOW INDEX` should show two rows under `csr_candidate_source_index`, in order `news_candidate_id`, `news_source_id`, with `Non_unique = 1`.

```sql
SHOW INDEX FROM candidate_source_references;
SHOW CREATE TABLE candidate_source_references;
SELECT COUNT(*) AS overlong_index_names
FROM information_schema.statistics
WHERE table_schema = DATABASE() AND CHAR_LENGTH(index_name) > 64;
SELECT COUNT(*) AS overlong_constraint_names
FROM information_schema.table_constraints
WHERE constraint_schema = DATABASE() AND CHAR_LENGTH(constraint_name) > 64;
```

Both overlong-name counts must be zero. Record the deployed commit, MariaDB version and migration status. If a migration fails, retain that failed staging database for inspection and repeat with another newly created empty database after correcting the cause; do not blindly retry on its partial schema.

No DROP DATABASE, `migrate:fresh`, production data edit, migration-history fabrication, deployment, DNS change or paid-service activation is part of this procedure.

### Repeating the optional real-database regression

For automated integration testing, create a **different empty test database** named `cryptonews_migration_test_<unique_suffix>` with a user restricted to it. Put its credentials in an ignored, access-restricted local JSON file with fields `driver` (`mariadb` or `mysql`), `host` (`127.0.0.1`), `port`, `database`, `username` and `password`. Never point this at the populated staging application database. With PHP/PDO MySQL and dev dependencies installed:

```bash
CRYPTONEWS_MIGRATION_TEST_CONFIG=/secure/path/to/test-connection.json php artisan test --compact tests/Feature/MigrationIdentifierTest.php --no-interaction
```

The real-server case intentionally reproduces a failing overlong index on its own fixture table, confirms that a blind rerun is unsafe, removes only that test-owned partial table, then runs the entire up/down/up/down chain. It refuses a nonempty target before making changes, and leaves the dedicated test schema empty on success. Retain any failed test schema for diagnosis rather than dropping unknown tables automatically. Source-compilation and SQLite coverage do not require this JSON or a server.
