# Backup and restore

Hostinger Premium provides its own account-level backups. Those are a safety net,
not a strategy: they are not application-aware, their retention is outside our
control, and they cannot be tested on demand. This document defines PISFA's own
procedure on top of them.

> **A backup that has never been restored is not a backup.** The restore drill
> below is part of the procedure, not an optional extra.

## What must be backed up

| Asset | Where | Why |
|---|---|---|
| Database | MariaDB | All bookings, enquiries, financial records, audit log |
| Private media | `storage/app/private/` | Identity documents, driving permits, selfies, contracts |
| Public uploads | `storage/app/public/` | Vehicle and tour media |
| `.env` | Project root | Credentials and `APP_KEY` |
| Release artifact | Git SHA + `public/build/` | Reproducing the exact deployed code |

### APP_KEY is not optional

Encrypted columns — TOTP secrets, recovery codes, self-drive identity and permit
numbers, transfer flight numbers and service addresses — are decryptable **only**
with the `APP_KEY` in force when they were written. Idempotency and fingerprint
hashes are HMAC-keyed with the same value.

**A database restore with a different `APP_KEY` is a silent data loss.** Store
`APP_KEY` with the backup, in a separate secret store. Never rotate it without a
planned re-encryption migration.

## Schedule

| Asset | Frequency | Retention |
|---|---|---|
| Database | Daily, plus **before every release** | 7 daily, 4 weekly, 3 monthly |
| Private media | Daily incremental | 7 daily, 4 weekly |
| Public uploads | Weekly | 4 weekly |
| `.env` + `APP_KEY` | On change | Every version, in a secret store |

Off-site copies are strongly recommended: a backup on the same account is lost
with the account.

## Taking a backup

### The built-in command (preferred)

```bash
php artisan pisfa:backup                 # database + private media, then prune
php artisan pisfa:backup --no-media      # database only
php artisan pisfa:backup --no-prune      # keep every existing backup
```

This runs automatically at 02:15 Kampala time via the scheduler, writing to
`config('operations.backup.disk')` under `config('operations.backup.path')`.
Recent backups are listed in the console at **Admin → Operations**.

**It shells out to nothing.** The dump is taken through PDO rather than by
calling `mysqldump`, because Hostinger and most shared hosts disable `exec()`
and `proc_open()`, and where they do not the binary is often absent from PATH
for the PHP user. A backup that silently fails to run is worse than none,
because it is believed. The cost is honest: slower than `mysqldump`, with rows
streamed in 500-row chunks so peak memory stays flat regardless of table size.

The dump is written to a temporary file and moved into place only once complete,
so a run that dies half way through never leaves a truncated file that looks
usable.

**`.env` is deliberately not included**, for the reason given above: an archive
holding both the encrypted data and the key that decrypts it is a single file
that gives away everything. Keep `APP_KEY` in a secret store.

Retention (`PISFA_BACKUP_KEEP`, `PISFA_BACKUP_MAX_AGE_DAYS`) prunes by count and
age but **never deletes the newest backup**, whatever those are set to — a
misconfigured limit or a clock that jumped must not be able to leave the
deployment with nothing to restore from.

For a genuine off-site copy set `PISFA_BACKUP_DISK` to a remote filesystem disk.
A backup beside the database survives a bad migration, not a lost server; the
command and the console both say so when the disk is `local`.

### The manual script

Still useful for a one-off dump from a shell where `mysqldump` is available.
Run from the project root over SSH. Confirm the PHP and `mysqldump` paths on the
actual account first.

```bash
set -euo pipefail

STAMP="$(date -u +%Y%m%d-%H%M%SZ)"
DEST="$HOME/backups/pisfa/$STAMP"
mkdir -p "$DEST"

# Read DB credentials from .env without echoing them
export $(grep -E '^DB_(DATABASE|USERNAME|PASSWORD|HOST|PORT)=' .env | xargs)

# 1. Database. --single-transaction keeps InnoDB consistent without locking.
mysqldump \
  --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-3306}" \
  --user="$DB_USERNAME" --password="$DB_PASSWORD" \
  --single-transaction --quick --routines --triggers --events \
  --default-character-set=utf8mb4 \
  "$DB_DATABASE" | gzip -9 > "$DEST/database.sql.gz"

# 2. Private media (never served directly from the web root)
tar -czf "$DEST/private-media.tar.gz" -C storage/app private 2>/dev/null || true

# 3. Public uploads
tar -czf "$DEST/public-media.tar.gz" -C storage/app public 2>/dev/null || true

# 4. Environment — contains APP_KEY. Move this to a secret store, do not leave it here.
cp .env "$DEST/env.backup"

# 5. Release identity
git rev-parse HEAD > "$DEST/RELEASE_SHA" 2>/dev/null || echo "unknown" > "$DEST/RELEASE_SHA"

chmod -R 600 "$DEST"
ls -lh "$DEST"
```

Verify the dump is complete rather than truncated:

```bash
gunzip -t "$DEST/database.sql.gz" && echo "gzip ok"
gunzip -c "$DEST/database.sql.gz" | tail -1 | grep -q "Dump completed" && echo "dump complete"
```

A dump that fails either check must be retaken. A silent partial dump is worse
than no dump, because it will be trusted.

### Retention prune

```bash
find "$HOME/backups/pisfa" -maxdepth 1 -type d -mtime +7 \
  ! -name "$(date -u -d 'last sunday' +%Y%m%d)*" -exec rm -rf {} +
```

Review before scheduling — deletion is irreversible.

## Restore

### Rule: restore to a scratch database first

Never restore over a live database to "see if it works". Restore into a scratch
schema, verify, then decide.

```bash
STAMP=20260828-120000Z
DEST="$HOME/backups/pisfa/$STAMP"
export $(grep -E '^DB_(USERNAME|PASSWORD|HOST|PORT)=' .env | xargs)

mysql --user="$DB_USERNAME" --password="$DB_PASSWORD" \
  -e "CREATE DATABASE pisfa_restore_check CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

gunzip -c "$DEST/database.sql.gz" \
  | mysql --user="$DB_USERNAME" --password="$DB_PASSWORD" pisfa_restore_check
```

Verify against the scratch copy:

```sql
SELECT COUNT(*) FROM users;
SELECT COUNT(*) FROM tour_bookings;
SELECT COUNT(*) FROM car_hire_bookings;
SELECT COUNT(*) FROM airport_transfer_bookings;
SELECT COUNT(*) FROM flight_inquiries;
SELECT MAX(created_at) FROM audit_logs;   -- how fresh is this backup?
```

Then confirm **encrypted columns actually decrypt** with the archived `APP_KEY` —
this is the check that catches a key mismatch:

```bash
php artisan tinker --execute="
  config(['database.connections.mysql.database' => 'pisfa_restore_check']);
  \$b = App\Models\AirportTransferBooking::query()->whereNotNull('service_address')->first();
  echo \$b ? 'decrypt ok: '.substr(\$b->service_address, 0, 12) : 'no encrypted row to test';
"
```

If that throws a decrypt exception, **stop**: the `APP_KEY` does not match the
dump. Find the correct key before going further.

### Full production restore

Only after the scratch verification passes.

```bash
php artisan down --secret="<long-random-string>"

# 1. Back up the CURRENT state first — you may need to undo this restore.
#    Run the backup script above before continuing.

# 2. Restore the database
gunzip -c "$DEST/database.sql.gz" \
  | mysql --user="$DB_USERNAME" --password="$DB_PASSWORD" "$DB_DATABASE"

# 3. Restore media
tar -xzf "$DEST/private-media.tar.gz" -C storage/app
tar -xzf "$DEST/public-media.tar.gz"  -C storage/app

# 4. Restore .env from the secret store, confirming APP_KEY matches the dump

# 5. Reconcile schema and caches
php artisan migrate --force
php artisan config:cache && php artisan route:cache
php artisan event:cache  && php artisan view:cache
php artisan storage:link

php artisan up
```

### Post-restore checks

- [ ] `php artisan migrate:status` — no pending migrations
- [ ] Sign in as each seeded role
- [ ] Open one record per domain: tour booking, car hire, transfer, flight enquiry
- [ ] Download one private car-hire document — proves media **and** the key
- [ ] Confirm a 2FA-enabled account can complete its TOTP challenge
- [ ] `php artisan queue:failed` — expected to be empty
- [ ] Confirm the scheduler fires within two minutes
- [ ] Drop the scratch database: `DROP DATABASE pisfa_restore_check;`

## Restore drill

Run **quarterly**, and after any change to the schema, storage layout, or
`APP_KEY` handling. Record in the release log: date, backup timestamp used, wall
time to restore, and every problem found.

An untested backup procedure has an unknown recovery time. The drill is what
converts it into a known one.

## Current gaps

Implemented since this document was first written: the scheduled backup
(`pisfa:backup`, nightly), retention with a floor that never removes the last
backup, private-media archiving, and backup visibility in the console.

Still outstanding, all belonging to the hosting pass:

- **Off-site replication is configurable but not configured.** `PISFA_BACKUP_DISK`
  defaults to `local`, which protects against a bad migration but not against
  losing the server. Pointing it at a remote disk is a deployment decision.
- **Backup-success monitoring is partial.** The console lists recent backups and
  `pisfa:backup` exits non-zero on failure — which cron mails to the account
  owner — but nothing alerts on a backup that simply stopped running.
- **The restore drill has not been performed** against a Hostinger staging
  account, so the recovery time objective is still unknown. The procedure below
  is written but unrehearsed.
