# Hostinger Premium Deployment

## Status

This is the initial deployment runbook for the planned Laravel application. No
production deployment has been performed from this scaffold. Hostinger plan
capabilities and command paths can change; verify each prerequisite inside the
actual account before relying on it.

The design intentionally avoids a production dependency on Docker, PostgreSQL,
Redis, Supervisor, a permanent Node.js process, or a self-hosted inbound
WebSocket server.

## 1. Verify the hosting account

Before implementation is considered deployable, verify in hPanel and SSH:

- The supported CLI and web PHP versions include the application's selected
  version, at least PHP 8.2.
- Required PHP extensions are enabled: OpenSSL, PDO MySQL, Mbstring, Tokenizer,
  XML, Ctype, JSON, BCMath, Fileinfo, Intl, Curl, GD or Imagick, and Zip as
  required by selected packages.
- Composer 2 is available over SSH.
- A MySQL or MariaDB database and least-privilege database user can be created.
- SSH/SFTP or the chosen Git deployment route is available.
- Cron frequency and the maximum number and duration of jobs are sufficient.
- The domain document root can point to Laravel's `public` directory, or the
  safe split-directory layout below can be used.
- Symbolic links work for the required storage layout; otherwise configure a
  storage provider that does not need one.
- PHP memory, upload size, POST size, execution time, storage quota, and inode
  quota satisfy expected images and documents.
- Outbound HTTPS works for mail, payment, SMS, WhatsApp, media, and optional
  hosted-broadcast providers.
- Public HTTPS callback URLs are reachable by each configured provider.

Record the verified values in private operational notes. Do not put account
credentials or secret values in this document.

## 2. Production layout

### Preferred layout

Keep the complete application in a private directory and point the domain
document root directly to its `public` directory:

```text
/home/ACCOUNT/domains/EXAMPLE.COM/pisfa/
    app/
    bootstrap/
    config/
    public/              <-- domain document root
    resources/
    routes/
    storage/
    vendor/
    .env
```

Only the `public` directory must be web-accessible.

### Safe fallback when the document root is fixed

If Hostinger requires `public_html` as the domain root, keep the application as
a sibling private directory and deploy only the contents of Laravel's `public`
directory into `public_html`:

```text
/home/ACCOUNT/domains/EXAMPLE.COM/pisfa/       <-- private application
/home/ACCOUNT/domains/EXAMPLE.COM/public_html/ <-- public assets and index.php
```

Adjust only the two bootstrap paths in the deployed `public_html/index.php` so
they resolve the private application's `vendor/autoload.php` and
`bootstrap/app.php`. Recheck these paths after framework upgrades.

Never copy the following into a publicly served location:

- `.env`
- Application source directories
- `storage` logs or private documents
- Backups
- Tests
- Git metadata
- Package caches
- Provider credentials

Do not solve a document-root problem by moving the entire Laravel project into
public access or adding permissive rewrite rules.

## 3. Database

Create a dedicated database and database user in hPanel. Grant only the access
needed by this application. Use InnoDB and `utf8mb4`.

Production environment values must select MySQL or MariaDB, not the default
SQLite configuration that may be present in a fresh Laravel scaffold:

```dotenv
DB_CONNECTION=mysql
DB_HOST=<host-from-hpanel>
DB_PORT=3306
DB_DATABASE=<database-name>
DB_USERNAME=<database-user>
DB_PASSWORD=<strong-secret>
```

Store timestamps in UTC and set the business display timezone to
`Africa/Kampala` in application configuration.

Before each release:

1. Review migrations for locks, table rewrites, data loss, and reversibility.
2. Take and verify a database backup.
3. Test the exact migrations against a recent staging copy.
4. Use `php artisan migrate --force` only after the code and backup are ready.

Never run `migrate:fresh`, destructive seeders, or an unreviewed destructive
migration in production.

## 4. Build the release

Validate an immutable release locally or in CI with development dependencies
installed. PHPUnit and Pint are development tools, so removing them before the
quality gates makes the gate impossible to run:

```bash
composer install --prefer-dist
npm ci
composer validate --strict
composer audit
npm audit --audit-level=high
php artisan test
vendor/bin/pint --test
npm run build
```

Static analysis remains a planned gate; do not claim a PHPStan result until a
compatible analyzer and maintained configuration have been added to the
repository. After all current gates pass, prepare production dependencies:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
```

Deploy the generated `public/build` assets. Production must not need Node.js or
`npm run dev`.

If production dependencies are installed on Hostinger rather than packaged in
the release, run Composer 2 from the private application directory with
`--no-dev --prefer-dist --optimize-autoloader`. Confirm that Composer scripts do
not assume unavailable binaries.

## 5. Environment configuration

Create `.env` directly in the private application directory. Begin with the
maintained `.env.example`, then set production values. At minimum review:

```dotenv
APP_NAME="PISFA Tours and Travels"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
APP_TIMEZONE=UTC
APP_BUSINESS_TIMEZONE=Africa/Kampala

LOG_CHANNEL=stack
LOG_LEVEL=warning

SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SESSION_ENCRYPT=true
AUTH_PASSWORD_TIMEOUT=900

CACHE_STORE=database
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=120

MAIL_MAILER=smtp
MAIL_SCHEME=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=587
MAIL_USERNAME=<mailbox-address>
MAIL_PASSWORD=<mailbox-password>
MAIL_FROM_ADDRESS=<verified-sender-address>

PISFA_TWO_FACTOR_REQUIRED_ROLES=super_admin,manager
PISFA_TWO_FACTOR_CHALLENGE_TTL=300
PISFA_STAFF_INVITATION_EXPIRY_HOURS=72
PISFA_SUPPORTED_CURRENCIES=UGX,USD
PISFA_TOUR_CANCELLATION_CUTOFF_HOURS=48
PISFA_TOUR_MAXIMUM_BOOKING_TRAVELERS=50
PISFA_TOUR_REMINDER_LEAD_MINUTES=1440
PISFA_TOUR_REMINDER_WINDOW_MINUTES=15
PISFA_TOUR_MAIL_MAX_PER_MINUTE=8
PISFA_CAR_HIRE_MAXIMUM_DAYS=90
PISFA_CAR_HIRE_MINIMUM_NOTICE_HOURS=2
PISFA_CAR_HIRE_CANCELLATION_CUTOFF_HOURS=48
PISFA_CAR_HIRE_PENDING_HOLD_MINUTES=1440
PISFA_PRIVATE_DOCUMENT_DISK=local
PISFA_CAR_HIRE_DOCUMENT_MAX_KB=5120
PISFA_CAR_HIRE_CONTRACT_VERSION=2026-08-20
PISFA_CAR_HIRE_RETURN_REMINDER_LEAD_MINUTES=1440
PISFA_CAR_HIRE_RETURN_REMINDER_WINDOW_MINUTES=15
PISFA_CAR_HIRE_MAIL_MAX_PER_MINUTE=8
PISFA_AIRPORT_TRANSFER_MAXIMUM_PASSENGERS=50
PISFA_AIRPORT_TRANSFER_MAXIMUM_LUGGAGE=100
PISFA_AIRPORT_TRANSFER_MINIMUM_NOTICE_HOURS=2
PISFA_AIRPORT_TRANSFER_MAXIMUM_ADVANCE_DAYS=365
PISFA_AIRPORT_TRANSFER_REQUEST_EXPIRY_MINUTES=1440
PISFA_AIRPORT_TRANSFER_CANCELLATION_CUTOFF_HOURS=4
PISFA_AIRPORT_TRANSFER_RATE_MINIMUM_DURATION_MINUTES=15
PISFA_AIRPORT_TRANSFER_RATE_MAXIMUM_DURATION_MINUTES=1440
PISFA_AIRPORT_TRANSFER_GUEST_CONFIRMATION_EXPIRY_HOURS=168
PISFA_AIRPORT_TRANSFER_MAIL_MAX_PER_MINUTE=8
PISFA_AIRPORT_TRANSFER_PICKUP_REMINDER_LEAD_MINUTES=1440
PISFA_AIRPORT_TRANSFER_PICKUP_REMINDER_WINDOW_MINUTES=15
```

F03 accepts only the configured intersection of `UGX` and `USD`; adding another
currency to `PISFA_SUPPORTED_CURRENCIES` does not enable it for tours. Set
`PISFA_TOUR_MAIL_MAX_PER_MINUTE` at or below the rate verified for the selected
mailbox/provider, verify its daily allowance, and use a suitable transactional
provider or hosting upgrade when expected volume exceeds those limits.

F04 uses the same configured currency intersection. Its private document disk
must resolve outside the public web root and remain writable by PHP. Set the
car-hire email throttle at or below the verified provider allowance. Preserve
`APP_KEY`, because F04 encrypts identity and permit numbers with it.

F05 also uses the configured UGX/USD intersection. Preserve `APP_KEY`, because
exact transfer addresses and flight numbers are encrypted. Set the transfer
mail throttle at or below the verified provider allowance, and configure
approved airports, service locations, rates, capacities, and durations in the
protected application rather than copying legacy demonstration fares.

Also configure, as implemented:

- Database credentials
- Mail provider
- Payment-provider credentials and webhook secrets
- SMS provider
- Meta WhatsApp identifiers and secrets
- Public/private media provider
- Hosted broadcasting provider, if used
- Analytics identifier
- Exchange-rate provider or managed rate
- Backup destination and encryption

Use sandbox provider credentials on staging and production credentials only on
the production domain. Never print `.env`, upload it into public storage, commit
it, send it in a ticket, or expose values through a health endpoint.

Generate `APP_KEY` exactly once for a new environment:

```bash
php artisan key:generate
```

Back up the production key securely. Replacing it later can make encrypted
application data unreadable, including TOTP secrets and recovery codes, and
invalidate sessions.

Do not run `key:generate` during ordinary releases, restores, or server moves.
Restore the exact existing production `APP_KEY` from the protected secrets
backup before serving traffic.

Use a real SMTP transport in production. Do not leave `MAIL_MAILER=log` enabled:
invitation emails contain one-time setup links, and the log mailer would write
those links into application logs.

This deployment profile assumes `SESSION_DRIVER=database`,
`CACHE_STORE=database`, and `QUEUE_CONNECTION=database`. Apply the migrations
that create the sessions, cache/locks, jobs, and failed-jobs tables before
traffic is enabled. Database sessions are part of the staff-security design:
role or status changes delete the affected user's stored sessions immediately.
The database cache provides shared throttling and scheduler-overlap locks across
web and cron processes. Do not switch either driver to local files on a
multi-process deployment without revalidating those security and locking
properties.

## 6. First deployment

From the private application directory:

```bash
php artisan optimize:clear
php artisan migrate --force
php artisan storage:link
php artisan config:cache
php artisan event:cache
php artisan route:cache
php artisan view:cache
```

If symbolic links are not supported, do not expose private storage. Select a
compatible public-media strategy and retain authorized controller downloads for
private files.

Ensure the web process can write only where Laravel requires it, normally
`storage` and `bootstrap/cache`. Avoid world-writable permissions. Confirm that
all other source, configuration, and private data remain non-public.

Do not run demonstration seeders in production. From a private interactive SSH
session, create the first super administrator with:

```bash
php artisan pisfa:create-super-admin
```

The command hides password input, validates a strong password, creates an
active email-verified account, marks 2FA as required, and writes an audit event.
Sign in immediately and configure TOTP before using protected administration
tools. Do not paste the password into shell arguments, deployment scripts, or
support tickets.

## 7. Scheduler and queue cron jobs

Discover the actual PHP CLI path over SSH; do not assume the web PHP and CLI PHP
versions match. Likewise, replace every placeholder below with the absolute
private application path.

Configure the scheduler every minute. In a crontab-compatible interface the
complete entry is:

```text
* * * * * <PHP_BINARY> <APP_PATH>/artisan schedule:run --no-interaction
```

If hPanel provides separate schedule fields, enter `* * * * *` in those fields
and only the command portion in the command field. F03 schedules
`tours:send-departure-reminders` every minute with overlap prevention. Its
selection window is configurable and defaults to fifteen minutes; the unique
booking-event marker makes duplicate or overlapping invocations safe.
F04 also schedules `car-hire:expire-pending-bookings` and
`car-hire:send-return-reminders` every minute with overlap prevention and
durable booking-event deduplication.
F05 schedules `airport-transfers:expire-pending-requests` and
`airport-transfers:send-pickup-reminders` every minute with the same overlap
prevention and durable event-marker contract.

F29 schedules two more through the same single cron entry: `pisfa:heartbeat`
every minute, which is what the Scheduler health check reads, and `pisfa:backup`
nightly at 02:15 Kampala time. Nothing extra needs to be added to cron for
either — `schedule:run` dispatches both.

The heartbeat is worth understanding: a scheduler that has stopped is a silent
failure, because nothing errors and reminders simply stop going out. Once the
site is live, **Admin → Operations** showing a recent heartbeat is the fastest
confirmation that this cron entry is genuinely firing.

Run one short-lived database queue worker every five minutes:

```text
*/5 * * * * <PHP_BINARY> <APP_PATH>/artisan queue:work database --stop-when-empty --max-time=180 --tries=3 --timeout=90 --no-interaction
```

Set `DB_QUEUE_RETRY_AFTER=120` (or higher) so the database reservation always
outlives the 90-second worker timeout. The timeout must remain several seconds
shorter than `retry_after`; otherwise a slow job can be reserved by a second
worker while the first process is still being terminated.

The five-minute interval means a newly queued notification normally waits no
more than five minutes before a worker starts, plus processing and any provider
rate-limit delay. The 180-second process budget leaves a normal buffer before
the next invocation. Configure this worker command only once, use any cron
overlap protection offered by the account, and monitor for processes that outlive
their slot. It must exit after draining available jobs; no permanent worker or
Supervisor is assumed.

Tour, car-hire, and airport-transfer email jobs use their respective configured
per-minute throttles; database
notifications are not delayed by that mail-channel throttle. Confirm the actual
mailbox/provider per-minute and daily allowances in the hosting account before
choosing the value. Confirm the server/hPanel cron timezone as part of staging;
the application itself stores timestamps in UTC and displays business time in
`Africa/Kampala`.

This design assumes hPanel cron can invoke the same supported PHP CLI version
used by the web application and can reach the private application directory.
It does not assume daemon management, Redis, or an always-running worker. If the
plan cannot run the scheduler frequently enough for a workflow's timing
requirement, change the product expectation or hosting plan rather than hiding
the delay.

Requirements for scheduled code:

- Use overlap prevention where supported by the configured cache store.
- Persist sent/processed markers and idempotency keys in MySQL.
- Use bounded retries, backoff, and timeouts.
- Make duplicate cron invocations safe.
- Expose failed jobs and an authorized retry action.
- Provide a synchronous or administrator-triggered fallback for critical work.

Verify jobs by dispatching controlled staging examples and checking business
state and redacted delivery logs. A cron entry visible in hPanel is not proof
that it executes successfully.

## 8. Payments, WhatsApp, and external callbacks

Every callback URL must use HTTPS and the exact production route generated by
Laravel. Configure providers only after the route is live.

Before enabling production traffic, verify:

- Provider signature or message-authentication checks
- Timestamp and replay-window checks where supported
- Idempotency and unique provider transaction constraints
- Server-side amount, currency, service, and ownership checks
- Duplicate callback behavior
- Pending, success, failure, cancellation, refund, and retry behavior
- Safe redacted logging
- CSRF exceptions limited to the exact webhook routes
- Appropriate rate limits
- Meta verification challenge and request-signature checks

Do not mark a payment successful based on the browser redirect alone.

## 9. Health checks and monitoring

Two levels, both implemented.

**Public liveness — `https://DOMAIN/health`.** Point uptime monitoring here.
Returns `{"status":"ok"}` / 200, or `{"status":"down"}` / **503**. It names no
subsystem and exposes no configuration: an endpoint that reports a connection
error names the database host, and one that lists subsystems tells an attacker
what to aim at. Only an unreachable database or an unset `APP_KEY` make it
report down, because those are the failures that mean the site cannot serve a
request at all.

**Operational detail — Admin → Operations**, super administrators only. Database,
migrations, private storage, public storage, cache, queue, failed jobs,
application key, scheduler recency, and recent backups. Failed jobs can be
retried or discarded from here.

Each check proves itself by doing the thing rather than by reading
configuration — the storage checks write a file and read it back, because on
shared hosting a disk that is configured but not writable is the failure that
actually happens, and it looks identical to a working one until somebody uploads
a passport scan.

Three states. `Warning` means look at this today (queue backlog, failed jobs, a
deployment with no scheduler heartbeat yet); only `Failing` reports down. Provider
health is deliberately not part of liveness: PISFA staying up must not depend on
every payment gateway being up.

Thresholds are configurable — `PISFA_QUEUE_BACKLOG_WARNING`,
`PISFA_QUEUE_STALL_MINUTES`, `PISFA_SCHEDULER_STALL_MINUTES`.

**Log redaction is automatic.** `App\Logging\RedactSensitiveValues` is attached
as a Monolog processor to every channel that writes anywhere a person can read,
so credentials, tokens, TOTP data, signatures and card fields are stripped
before a record is written — including from the framework's own logging, and
from any code added later. It is not a rule each call site has to remember.

## 10. Backups and restore

Automated nightly at 02:15 Kampala time by the scheduler:

```bash
php artisan pisfa:backup          # database + private media, then prune
```

It covers the MySQL data and private media (the evidence that cannot be
regenerated: passport scans, signed contracts, inspection photographs).

**It does not include `.env`.** That holds `APP_KEY`, and an archive containing
both the encrypted data and the key that decrypts it is a single file that gives
away everything. Keep `APP_KEY` in a separate secret store — see
`docs/BACKUP_AND_RESTORE.md`.

**The dump uses PDO, not `mysqldump`.** Hostinger disables `exec()` on most
plans, and a backup that silently fails to run is worse than none because it is
believed. Nothing needs to be installed or on PATH.

Keep the backup path outside `public_html` — the default
(`storage/app/private/backups`) already is, and the deployment layout in §2 keeps
`storage/` unreachable over HTTP.

Retention prunes by count (`PISFA_BACKUP_KEEP`) and age
(`PISFA_BACKUP_MAX_AGE_DAYS`) but never removes the newest backup, so a
misconfigured limit cannot leave the account with nothing to restore.

Set `PISFA_BACKUP_DISK` to a remote filesystem disk for a genuine off-site copy.
Until you do, backups sit on the same account as the database: that survives a
bad migration, not a lost account. The command and the console both say so.

A backup is not verified until it has been restored into an isolated scratch
database and core records and documents have been checked. That drill is
documented in `docs/BACKUP_AND_RESTORE.md` and **has not yet been performed**
against a real Hostinger account, so the recovery time objective is still
unknown.

## 11. Release procedure

1. Confirm CI and acceptance tests are green.
2. Review the feature traceability and migration plan.
3. Put provider changes into a coordinated maintenance window if needed.
4. Take and verify a pre-release backup.
5. Upload the versioned release to a new private directory where possible.
6. Install optimized production dependencies and deploy prebuilt assets.
7. Clear stale Laravel caches in the new release, then apply reviewed migrations.
8. Switch the active release or document root atomically where hosting permits.
9. Build production caches after the migrations succeed.
10. Run smoke tests for public, customer, driver, staff, manager, and super-admin
    access.
11. Test one safe queue job and confirm scheduler recency.
12. Verify storage, email, and sandbox-safe/provider health checks.
13. Monitor logs, failed jobs, callbacks, and database errors.

Keep the previous code release until post-deployment verification finishes.

## 12. Rollback

Prepare rollback before deploying:

- Retain the previous release and its compiled assets.
- Classify migrations as backward compatible or requiring a data restore.
- Prefer expand-and-contract schema changes so old and new code can overlap.
- Never assume `migrate:rollback` safely restores transformed or deleted data.
- If the schema is incompatible, stop writes, restore the verified pre-release
  backup, restore matching private files, and reactivate the previous code.
- Rebuild Laravel caches after switching releases.
- Reverify callback endpoints and queue behavior.
- Record the incident and reconciliation work, especially for payments.

## 13. Production acceptance checklist

- HTTPS and canonical domain work without mixed content.
- Debug mode is disabled.
- Public root exposes only intended assets and `index.php`.
- Database uses a dedicated least-privilege user.
- Storage and cache paths are writable without unsafe permissions.
- All Laravel production caches build successfully.
- All migrations complete on a staging copy and production.
- Cron scheduler execution is recent.
- The short-lived queue worker processes and exits correctly.
- Failed jobs are visible and retryable.
- Email, SMS, WhatsApp, storage, and payment integrations are verified.
- Provider callbacks reject invalid signatures and tolerate duplicates.
- Private documents reject unauthorized access.
- Every role passes its principal browser journey.
- Accessibility and mobile layouts pass release checks.
- PWA caching does not store private pages or API responses.
- Audit records are generated by real mutations.
- Reports reconcile paid and refunded transactions.
- Backup and isolated restore have succeeded.
- Rollback steps and responsible operators are documented.

Do not launch solely because the homepage loads. Production approval depends on
the complete traceability matrix, security controls, operational checks, and
tested recovery procedure.
