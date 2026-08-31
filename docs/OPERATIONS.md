# Operations

Day-to-day running of PISFA on Hostinger Premium. Deployment itself is in
`docs/HOSTINGER_DEPLOYMENT.md`; backups are in `docs/BACKUP_AND_RESTORE.md`.

## Runtime shape

Hostinger Premium gives shared PHP-FPM behind LiteSpeed, MariaDB, SSH, and cron.
It does **not** give Docker, Redis, Supervisor, root, a persistent Node process,
or inbound WebSockets. Everything below is designed around that.

| Concern | Choice |
|---|---|
| Queue | `database` driver, drained by a short-lived cron worker |
| Cache | `database` |
| Sessions | `database`, secure cookies |
| Scheduler | Hostinger cron calling `schedule:run` |
| Assets | Prebuilt in CI and deployed; `npm` never runs in production |
| Real-time | Hosted broadcasting when configured, AJAX polling otherwise |

## Cron

Two entries. Replace `USERNAME` and `DOMAIN`, and confirm the PHP CLI path with
`which php` on the actual account before pasting — the binary is often
`/usr/bin/php` but may be version-suffixed.

```cron
# Scheduler — every minute
* * * * * /usr/bin/php /home/USERNAME/domains/DOMAIN/public_html/artisan schedule:run >> /dev/null 2>&1

# Queue — short-lived worker, exits when the queue drains
*/5 * * * * /usr/bin/php /home/USERNAME/domains/DOMAIN/public_html/artisan queue:work database --stop-when-empty --tries=3 --timeout=90 --max-time=240 >> /dev/null 2>&1
```

`--stop-when-empty` and `--max-time` guarantee the worker exits, so overlapping
cron firings cannot accumulate processes on a shared plan.

### Verifying cron actually runs

Cron silence is indistinguishable from success, so check positively:

```bash
php artisan schedule:list                      # what should run
php artisan queue:monitor database --max=50    # backlog size
php artisan queue:failed                       # failures
```

The scheduler also writes a heartbeat every minute (`pisfa:heartbeat`), which
the Scheduler health check reads. A scheduler that has stopped is a silent
failure by nature — nothing errors, reminders simply stop going out, and the
first anybody hears of it is a customer who did not get their pickup reminder —
so **Admin → Operations** shows how long ago it last ran. That is the fastest
way to confirm cron is alive.

If the scheduler has not fired, the usual causes are a wrong PHP path, a wrong
project path, or the account's cron being disabled — in that order.

## Scheduled work

Defined in `routes/console.php`, all `everyMinute()` with
`withoutOverlapping(10)`:

| Command | Purpose |
|---|---|
| `tours:send-departure-reminders` | Reminder before a tour departure |
| `car-hire:expire-pending-bookings` | Release expired vehicle holds |
| `car-hire:send-return-reminders` | Vehicle return reminder |
| `airport-transfers:expire-pending-requests` | Expire unconfirmed transfer requests, release the team |
| `airport-transfers:send-pickup-reminders` | Pickup reminder |
| `loyalty:process-awards` | Award points for settled bookings and qualified referrals |
| `loyalty:expire-points` | Monthly expiry sweep, with a warning first |
| `reviews:send-requests` | Invite customers to review a completed booking, once each |
| `quotations:expire` | Relabel sent quotations past their validity date |
| `fleet:send-alerts` | Report service due, vehicle paperwork, and driver licences expiring |
| `content:publish-scheduled` | Publish journal posts whose scheduled time has passed |

Every one is **idempotent**: state changes are re-checked under a row lock, and
notifications are gated by a durable marker row (`*_events` tables written with
`firstOrCreate`). Running a command twice sends nothing twice. Each can be run by
hand as an administrator fallback:

```bash
php artisan airport-transfers:expire-pending-requests
```

**Not yet scheduled** (features do not exist): property reminders, daily
analytics aggregation, weekly management emails, abandoned-booking reminders,
queue/failed-job cleanup.

## Queue operations

```bash
php artisan queue:work database --stop-when-empty --tries=3 --timeout=90
php artisan queue:failed                 # list failures
php artisan queue:retry <uuid>           # retry one
php artisan queue:retry all              # retry everything
php artisan queue:forget <uuid>          # discard one
```

Notification jobs use `$tries = 120` with `$backoff = [60, 300, 900]` and
`$maxExceptions = 3`. The high try count with long backoff is deliberate: a
Hostinger SMTP hourly cap should delay a message, not drop it. `afterCommit()`
prevents dispatching a job for a transaction that later rolls back.

Failed jobs are also listed in the console at **Admin → Operations**, with retry
and discard per job. Retrying pushes the job back onto the queue rather than
running it in the web request — a job that failed on a timeout would fail the
same way inside the request and take the operator's page down with it.

Each failed job is work that did not happen: a confirmation email nobody
received, a document never filed. Fix the cause before retrying.

## Health checks

Two endpoints, deliberately different.

**`/health`** — public, unauthenticated, throttled. Returns `{"status":"ok"}`
with 200, or `{"status":"down"}` with **503**. It names no subsystem and gives
no detail: an endpoint that reports `database: connection refused to
db1.example.com:3306` hands an attacker the topology, and one that lists
subsystems tells them what to aim at. 503 rather than 500 because a monitor and
a load balancer both read 503 as "not ready". Point uptime monitoring here.

Only two things make it report down — the database being unreachable and
`APP_KEY` being unset — because those are the failures that mean the site
genuinely cannot serve a request.

**Admin → Operations** — super administrators only. The full report: database,
migrations, private storage, public storage, cache, queue, failed jobs,
application key, scheduler heartbeat. Each check proves itself by doing the
thing — the storage checks write a file and read it back, because a disk that is
configured but not writable behaves exactly like a working one until somebody
uploads a passport scan.

Three states, not two. `Warning` means look at this today; only `Failing` makes
`/health` report down. A *missing* scheduler heartbeat is a warning (a new
deployment has none yet); a *stale* one is a failure, because reminders have
stopped going out.

`/up` remains as Laravel's framework endpoint — it confirms the application
boots and nothing more.

After a release, also confirm `APP_ENV=production`, `APP_DEBUG=false`, and
HTTPS-only secure cookies:

```bash
php artisan about                # env, drivers, cache state
php artisan migrate:status       # every migration ran
curl -s -o /dev/null -w '%{http_code}\n' https://DOMAIN/health
```

## Logs

```bash
tail -f storage/logs/laravel.log
php artisan pail                # live tail, local/staging only
```

Audit entries are the operational record for privileged and financial mutations:
actor, action, entity type and id, redacted before/after values, IP, user agent,
timestamp. Redaction happens in `Services\AuditLogger` **before** persistence, so
secrets never reach the table or a log line.

**Gap:** there is no audit viewer UI (F26). Query directly for now:

```sql
SELECT created_at, user_id, event, auditable_type, auditable_id
FROM audit_logs ORDER BY id DESC LIMIT 50;
```

## Routine tasks

### Create the first super administrator

```bash
php artisan pisfa:create-super-admin
```

Interactive. The password is read hidden and is never accepted as a command
option, so it cannot leak into shell history or the process table.

### Clear caches after a config or route change

```bash
php artisan config:clear && php artisan config:cache
php artisan route:clear  && php artisan route:cache
php artisan view:clear   && php artisan view:cache
php artisan event:clear  && php artisan event:cache
```

A stale `config:cache` after an `.env` edit is the single most common cause of
"the setting did not apply".

### Put the site in maintenance mode

```bash
php artisan down --secret="<long-random-string>"   # you can still browse via /<secret>
php artisan up
```

## Incident playbook

| Symptom | First checks |
|---|---|
| Nothing is emailed | `queue:failed`; is the queue cron firing?; SMTP credentials; provider hourly cap |
| Reminders fire twice | Should be impossible — marker rows are `firstOrCreate`. Check for two cron entries or two deploy directories sharing one database |
| "Page expired" on submit | Session driver/table, cookie domain, `APP_URL` scheme, clock skew |
| 500 after deploy | `storage/logs/laravel.log`; then `config:clear`; check `storage/` and `bootstrap/cache/` are writable |
| Slow admin lists | Confirm the query is paginated and the composite index in `docs/DATABASE.md` exists |
| Customer cannot see their booking | Expected if it is not theirs — scoped bindings return **404**, not 403 |

## Escalation

1. Capture the audit-log entries and `laravel.log` lines around the event.
2. Note the release (git SHA) and whether it followed a deployment.
3. If data integrity is in doubt, take a backup **before** attempting a fix —
   see `docs/BACKUP_AND_RESTORE.md`.
