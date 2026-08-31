#!/usr/bin/env bash
#
# PISFA — deploy the current release on Hostinger.
#
# Run over SSH from the private application directory, after `git pull`.
# Safe to re-run: every step is idempotent and none of them destroys data.
#
#   cd ~/domains/DOMAIN/app && git pull && bash deploy/deploy.sh
#
# The exit code matters. Any failure stops the script before caches are rebuilt,
# so a half-applied release does not get served.

set -euo pipefail

PHP="${PHP_BIN:-php}"

step() { printf '\n\033[1;36m==> %s\033[0m\n' "$1"; }
fail() { printf '\n\033[1;31mFAILED: %s\033[0m\n' "$1"; exit 1; }

[ -f artisan ] || fail "Run this from the application directory (no artisan file here)."
[ -f .env ]    || fail ".env is missing. Create it before deploying — see docs/HOSTINGER_DEPLOYMENT.md §5."

step "PHP version"
$PHP -v | head -1

# ---------------------------------------------------------------------------
# Maintenance mode.
#
# The window between migrating and rebuilding caches is the one moment the app
# can serve inconsistent state, so visitors get a maintenance page rather than
# a 500. `--render` uses a real Blade view so the page still looks like PISFA.
# ---------------------------------------------------------------------------
step "Entering maintenance mode"
$PHP artisan down --render="errors::503" --retry=60 || true
trap '$PHP artisan up || true' EXIT

# ---------------------------------------------------------------------------
# Dependencies.
#
# --no-dev omits the test and analysis tooling. --optimize-autoloader builds a
# classmap, which is a measurable win on shared hosting where the filesystem is
# slow.
# ---------------------------------------------------------------------------
step "Installing PHP dependencies"
if command -v composer >/dev/null 2>&1; then
    composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist
elif [ -f composer.phar ]; then
    $PHP composer.phar install --no-dev --optimize-autoloader --no-interaction --prefer-dist
else
    fail "Composer not found. Install it, or upload vendor/ manually and re-run with SKIP_COMPOSER=1."
fi

# ---------------------------------------------------------------------------
# Database.
#
# --force is required because the command refuses to run unprompted in
# production. It is not "force past errors": a failing migration still stops
# the script, and the trap above brings the site back up.
# ---------------------------------------------------------------------------
step "Running migrations"
$PHP artisan migrate --force

step "Linking public storage"
$PHP artisan storage:link || true

# ---------------------------------------------------------------------------
# Caches.
#
# Cleared first, then rebuilt. Rebuilding over a stale cache is how a
# deployment ends up serving a route that no longer exists.
# ---------------------------------------------------------------------------
step "Rebuilding caches"
$PHP artisan optimize:clear
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache

step "Restarting queue workers"
# Signals the running short-lived worker to exit after its current job, so the
# next cron firing picks up the new code rather than the old.
$PHP artisan queue:restart || true

# ---------------------------------------------------------------------------
# The gate.
#
# Runs before the site comes back up. A failure here means the release is
# configured wrongly, and it is better to stay on the maintenance page than to
# serve a misconfigured site.
# ---------------------------------------------------------------------------
step "Preflight"
if ! $PHP artisan pisfa:preflight; then
    fail "Preflight failed. The site is still in maintenance mode — fix the reported items, then re-run."
fi

step "Leaving maintenance mode"
trap - EXIT
$PHP artisan up

step "Release"
git log -1 --format='%h  %ad  %s' --date=short 2>/dev/null || echo "(no git metadata)"

printf '\n\033[1;32mDeployed.\033[0m\n'
