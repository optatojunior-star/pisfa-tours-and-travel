# Testing

## Commands

```bash
composer validate --strict
vendor/bin/pint --test                                      # formatting
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress   # static analysis
php artisan test                                            # SQLite, fast
php artisan test --configuration=phpunit.mysql.xml          # MariaDB, production engine
npm run build
```

`composer check` chains validate → lint → analyse → test.

## Browser tests

Laravel Dusk drives a real Chrome. These cover what an HTTP test cannot: whether
the page works once JavaScript has run, whether the skip link actually becomes
visible on focus, and whether the customer sees their own data.

```bash
# 1. A driver matching the installed Chrome. Check the version first:
#    (Get-Item 'C:\Program Files\Google\Chrome\Application\chrome.exe').VersionInfo.ProductVersion
php artisan dusk:chrome-driver <major-version>
./vendor/laravel/dusk/bin/chromedriver-win.exe --port=9515   # leave running

# 2. The application, served against the browser-test environment.
php artisan migrate --env=dusk.local --force                 # first run only
php artisan serve --env=dusk.local --host=127.0.0.1 --port=8000

# 3. The suite.
php artisan dusk
php artisan dusk --filter chat_widget                        # one journey
```

`DuskTestCase` reuses a driver that is already listening on the driver port
rather than starting its own. That is what CI does — the driver is a service
there — and on Windows Dusk's own process spawning does not reliably start the
bundled binary.

**`php artisan dusk` swaps your `.env`.** It moves `.env` to `.env.backup`,
copies `.env.dusk.local` into place, and restores it when the run ends. If you
kill a run part-way through, that restore never happens and your `.env` is left
as the browser-test one — the symptom is `Unsupported cipher or incorrect key
length` on every page. The fix is `cp .env.backup .env && rm .env.backup`. Let a
run finish rather than interrupting it.

The browser suite is slow — roughly 85 seconds per test, because each one starts
a fresh browser — so it is kept to seven journeys and runs last in CI, after
every cheaper check is already green. Screenshots and console logs from a
failure are uploaded as CI artifacts; locally they land in
`tests/Browser/screenshots` and `tests/Browser/console`.

`.env.dusk.local` is committed. It holds no real credentials, points at its own
SQLite database so a browser run never touches the one you are developing
against, and leaves every external integration unconfigured so a browser test
can never reach a payment gateway, a mail server, or WhatsApp.

## Accessibility checks

`tests/Feature/Accessibility/PublicPageAccessibilityTest` runs
`Tests\Support\AccessibilityAudit` over every public page and the customer
portal. It checks eleven things that markup alone can prove: page title, `lang`,
one `h1` with no skipped levels, `alt` on every image, an accessible name on
every control, a label on every field, link text that says where it goes, no
positive `tabindex`, no duplicate ids, exactly one `main` landmark, and headers
and a caption on every data table.

It is deliberately not a claim that the site is accessible. Colour contrast,
focus visibility, and whether alt text is *useful* need a browser or a person —
the first two are covered in `tests/Browser`, the third by review. Two of its
tests check the checker itself, because a checker that passes everything is
worse than none.

## Two test configurations, and why

`phpunit.xml` pins `DB_CONNECTION=sqlite` with an in-memory database. That is the
fast local loop, but SQLite **cannot** exercise `lockForUpdate`, InnoDB
unique-constraint races, or MySQL date semantics — precisely the guarantees the
booking, assignment, and idempotency code depends on.

`phpunit.mysql.xml` therefore omits every `DB_*` override, so the connection can
only come from the environment. A misconfigured run fails to connect instead of
quietly passing against the wrong engine. CI runs the suite **both** ways, plus
`migrate` and `migrate:rollback` against MariaDB.

Until a MariaDB run is recorded for a given feature, its concurrency claims are
unverified — that is why every feature slice lists "MySQL race evidence" as an
outstanding gap in `docs/FEATURE_TRACEABILITY.md`.

## Static analysis

Larastan/PHPStan at **level 5**, configured in `phpstan.neon`. Adopting it on an
existing codebase produced 581 findings, captured in `phpstan-baseline.neon` so
CI fails on **new** findings only.

The baseline is a debt ledger: it may shrink, never grow. If a change adds an
entry, fix the code rather than regenerating the baseline. Level 6 produced
1000+ findings and is the next target once the remaining slices land.

## Current implementation evidence

The current suite covers authentication, profile management, PISFA role and
account-state enforcement, TOTP setup and login challenges, recovery codes,
staff invitation and access administration, typed settings, audit creation and
redaction, the first public-marketing persistence flows, the F03 Tours and
Safaris vertical slice, and the in-progress F04 Car Hire vertical slice. The
feature traceability ledger remains the source of truth: a passing local suite
does not imply that any of the 30 modules is complete or production-verified.

Tests use SQLite in memory for fast feedback. Before release, the same critical
booking, payment, reporting, and concurrency journeys must also run against the
Hostinger-compatible MySQL version documented for staging.

## Security and staff-access checks required

The resumed identity slice is not release-ready unless automated checks cover:

- TOTP setup, confirmation, challenge success/failure, recovery-code
  consumption and regeneration, challenge expiry, throttling, and stale pending
  state after an account or credential change.
- Mandatory-2FA roles and per-account enforcement, including denial of disable,
  disabled remember-me behavior, and redirection to setup before protected
  administration access.
- Inactive and suspended login denial and revocation of existing database
  sessions after staff role or status changes.
- Super-admin-only staff listing, invitation, role/status updates, and resend;
  lower-role, unverified, unconfirmed-password, and missing-2FA denial paths.
- Hashed, expiring, single-use invitation tokens; strong password setup;
  activation and email verification; resend revocation; and safe invalid-token
  responses that do not leak account state.
- Self-demotion/deactivation prevention, last-active-super-admin protection,
  customer-role exclusion, mass-assignment resistance, and redacted audit
  events for each successful material mutation.

Use notification fakes in automated tests so raw invitation URLs are inspected
without being written by a log mailer. Repeat the critical session, lock, and
case-insensitive email checks against the production-compatible MySQL version
before release.

## Latest local verification

The 27 August 2026 local verification completed with:

- 354 automated tests passing with 1,819 assertions on SQLite in memory.
- Two intentional skips documenting that SQLite cannot reproduce the
  MySQL/MariaDB two-connection last-seat and last-vehicle locking races.
- Laravel Pint passing with no formatting differences.
- The Vite production build succeeding.
- Strict Composer manifest/lock validation succeeding.
- Composer and npm audits reporting no known vulnerabilities.
- Laravel config, event, route, and view caches building successfully, with the
  F03 and F04 routes resolving while production caches were active.
- The F03 and F04 migrations applying successfully to the local development
  database. The scheduler lists `tours:send-departure-reminders`,
  `car-hire:expire-pending-bookings`, and
  `car-hire:send-return-reminders` every minute.

F03 automated coverage includes public catalogue visibility and filtering,
exact money conversion, package/departure bounds, nested catalogue editing,
media URL safety, booking ownership and idempotency, capacity accounting,
customer cancellation, every booking-state transition, driver eligibility and
overlap rules, date/time boundaries, policy and route authorization, queued
notification contracts, reminder deduplication, and loyalty-event idempotency.

F04 automated coverage includes public interval-aware catalogue visibility,
covering-rate selection and exact price sorting; server-owned booking snapshots
and idempotency; half-open vehicle and cross-service driver conflicts; private
document type/size/content validation, owner-scoped downloads, safe headers and
download audits; encrypted and masked identity values; self-drive review and
original-document verification; versioned contract integrity and explicit
acceptance; every lifecycle prerequisite; queued notification contracts;
hold-expiry and return-reminder deduplication; route/policy role boundaries; and
responsive customer and staff Blade rendering.

This is local foundation evidence, not a production release sign-off. MySQL
race tests, real browser/accessibility and private-storage journeys,
SMTP/database-queue delivery, failed-job recovery, Hostinger cron/staging
checks, and stakeholder-approved car-hire operating terms remain required
before deployment.

## Local commands

```powershell
php artisan test
vendor\bin\pint --test
npm run build
php C:\path\to\composer.phar audit
npm audit --audit-level=high
```

Run an individual feature test while iterating:

```powershell
php artisan test --filter=RoleAuthorizationTest
```

## Release gates

A release is not eligible for staging unless:

- The full test suite passes.
- Production assets build from a clean `npm ci` installation.
- Pint reports no formatting differences.
- Composer and npm production audits report no unresolved vulnerabilities.
- New routes have authorization and ownership tests.
- New financial or scheduled workflows include duplicate/idempotency tests.
- Private uploads and downloads include negative authorization tests.
- The F01-F30 traceability matrix is updated with exact evidence.

Real-browser accessibility, MySQL concurrency, integration sandbox, mail/queue,
Hostinger cron, and backup/restore evidence must be added as their vertical
slices reach staging.

## Memory in the test process

`phpunit.xml` raises `memory_limit` to 512M. DomPDF holds font metrics and its
object graph for the life of the process, and PHPUnit runs the whole suite in
one, so the PDF renders across the billing, fleet, and car-hire suites
accumulate past PHP's 128M default.

This is a harness characteristic, not a production risk: a single render peaks
at roughly 32 MB, and each production request is a fresh process. If a single
render ever approaches the hosting limit, that is a real problem and the figure
above is where to check it.
