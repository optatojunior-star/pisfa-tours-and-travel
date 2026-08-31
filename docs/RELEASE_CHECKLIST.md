# Release checklist

Work top to bottom. A box that cannot be ticked is a blocker, not a note for
later. Record the release SHA, date, and operator at the bottom.

## 1. Before you start

- [ ] Every change is merged to `main` and CI is green on that commit
- [ ] `docs/FEATURE_TRACEABILITY.md` updated for anything this release changes
- [ ] Feature documentation updated (`docs/<FEATURE>.md`)
- [ ] No feature was moved to **Verified** without linked evidence for all twelve artifact classes
- [ ] Breaking or destructive migrations reviewed by a second person
- [ ] Rollback path decided **before** deploying (see §6)

## 2. Quality gates

Run locally, or confirm the CI run for the exact release SHA:

```bash
composer validate --strict
vendor/bin/pint --test
php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
php artisan test
npm run build
```

- [ ] `composer validate --strict` passes
- [ ] Pint reports no formatting drift
- [ ] PHPStan passes — **and the baseline did not grow.** `phpstan-baseline.neon` may shrink, never expand
- [ ] Full suite green on SQLite
- [ ] Full suite green on **MariaDB** (`php artisan test --configuration=phpunit.mysql.xml`) — SQLite cannot exercise row locks or InnoDB constraint races
- [ ] `npm run build` succeeds and `public/build/manifest.json` is regenerated
- [ ] `composer audit` and `npm audit --omit=dev` show no unresolved advisory

## 3. Security review

- [ ] Every new route has authentication, role middleware, **and** a policy or scoped binding
- [ ] Every new customer/driver record access is ownership-checked, and a foreign reference returns **404**
- [ ] Every new mutation writes an audit entry, with sensitive keys redacted
- [ ] No new secret, credential, or token is committed; `.env` is still git-ignored
- [ ] Any new private file is served through an authorized controller or expiring signed URL — never a public path
- [ ] Any new webhook verifies signature, timestamp/replay window, amount, currency, ownership, and idempotency **before** changing state
- [ ] Any new money field is an integer minor unit with a currency column; no floats
- [ ] Any new status change is guarded by an explicit transition graph with a test for an invalid jump

## 4. Data and migrations

- [ ] Migrations are forward-safe and additive where possible
- [ ] `php artisan migrate --pretend` reviewed for anything destructive
- [ ] Migration rollback tested on staging (`migrate:rollback --step=N`)
- [ ] **No `migrate:fresh`, no destructive seeder, no unreviewed destructive migration** — production data is not recoverable from CI
- [ ] Long-running migrations on large tables have a stated expected duration and a maintenance window if needed

## 5. Backup

- [ ] **Fresh database backup taken and verified** (`docs/BACKUP_AND_RESTORE.md`)
- [ ] `gunzip -t` and "Dump completed" check both pass
- [ ] Private media backed up
- [ ] Current `.env` and `APP_KEY` archived in the secret store
- [ ] Previous release SHA recorded, so §6 is executable

## 6. Rollback plan

Decide and write down which applies **before** deploying:

- [ ] **Code-only rollback** — no migration in this release: redeploy the previous SHA, re-cache, done
- [ ] **Code + schema rollback** — a reversible migration: redeploy previous SHA, `migrate:rollback --step=N`, re-cache
- [ ] **Restore from backup** — an irreversible migration: full restore per `docs/BACKUP_AND_RESTORE.md`. Note the expected data-loss window

Rollback command reference:

```bash
php artisan down --secret="<long-random-string>"
git checkout <previous-sha>
composer2 install --no-dev --optimize-autoloader
php artisan migrate:rollback --force --step=<n>   # only if reversible
php artisan config:cache && php artisan route:cache
php artisan event:cache  && php artisan view:cache
php artisan up
```

## 7. Deploy

Per `docs/HOSTINGER_DEPLOYMENT.md`.

- [ ] `php artisan down --secret="…"` if the release is not backward compatible
- [ ] Code deployed (Git or SFTP)
- [ ] `composer2 install --no-dev --optimize-autoloader`
- [ ] Prebuilt Vite assets deployed — **`npm` must not run in production**
- [ ] `.env` correct: `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` https, secure cookies
- [ ] `php artisan migrate --force`
- [ ] `php artisan storage:link` (first deploy, or if the link is missing)
- [ ] `config:cache`, `route:cache`, `event:cache`, `view:cache`
- [ ] `storage/` and `bootstrap/cache/` writable
- [ ] Document root points at `public/`; `.env`, source, storage, logs, backups, `.git`, and tests are all unreachable over HTTP
- [ ] Cron entries present and using the verified PHP CLI path
- [ ] Webhook/callback URLs registered with every configured provider
- [ ] `php artisan up`

## 8. Post-deploy verification

Smoke, not assumption. Actually load each page.

- [ ] `/up` returns 200
- [ ] `php artisan about` shows production env, debug off, expected drivers
- [ ] `php artisan migrate:status` — nothing pending
- [ ] Home, About, Contact, Privacy, Terms, Request a Quotation all render
- [ ] Public service pages render: tours, car hire, airport transfers, flights
- [ ] **Click every header and footer link** — no dead link, no 404
- [ ] Register → verify email → sign in works
- [ ] Password reset email arrives and the reset completes
- [ ] A 2FA-required role is challenged and can complete TOTP
- [ ] One booking or enquiry submitted end to end per live domain
- [ ] Customer portal shows only that customer's records; a foreign reference 404s
- [ ] Admin console loads for staff; customer and driver are refused
- [ ] A private document downloads only through its authorized endpoint
- [ ] Scheduler fires within two minutes (`schedule:list`, then confirm effects)
- [ ] Queue drains: `queue:monitor database`, `queue:failed` empty
- [ ] A test email actually arrives
- [ ] `storage/logs/laravel.log` has no new errors
- [ ] Audit log shows entries for the actions just performed

## 9. Sign-off

| Field | Value |
|---|---|
| Release SHA | |
| Date (UTC) | |
| Operator | |
| Backup timestamp used | |
| Rollback path chosen | |
| Features moved to Verified | |
| Known issues shipped | |

---

## Standing blockers

These prevent **any** release being declared production-complete against the
project definition of done. They are listed here so a release cannot quietly
claim more than it delivers.

- **0 of 30 features are Verified.** See `docs/FEATURE_TRACEABILITY.md`.
- **F14 payments does not exist.** No checkout, no reconciliation, no refunds.
  Every amount panel correctly states that no payment is collected online.
- **No PWA** — no manifest, service worker, offline page, or branded icons (F30).
- **No browser tests** for any role journey (F30).
- **No health checks** beyond `/up`, and no failed-job UI (F29).
- **No automated backup or completed restore drill** (F29) — recovery time is
  currently unknown.
- **No audit viewer or settings UI** (F26).
- **Hostinger staging deployment has not yet been performed**, so §7 and §8 are
  unrehearsed.
