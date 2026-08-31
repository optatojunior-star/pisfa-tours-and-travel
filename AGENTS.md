# PISFA engineering instructions

## Product contract

`docs/FEATURE_TRACEABILITY.md` is the authoritative ledger for F01-F30. Do not
mark a feature verified until its data model, policies, business rules, routes,
responsive UI, failure states, tests, and documentation are all present.

Never leave fake-success responses, placeholder actions, dead links, UI-only
controls, or operational APIs without the required interface.

## Architecture

- Laravel 12 and PHP 8.2+.
- MySQL/InnoDB/utf8mb4 in staging and production.
- Blade, Livewire 3, Alpine.js, Tailwind CSS, and prebuilt Vite assets.
- Database queues, cache, and sessions by default.
- No production dependency on Docker, Redis, PostgreSQL, Supervisor, a Node.js
  server, or a self-hosted inbound WebSocket process.
- Hosted broadcasting is optional and every live feature needs an HTTP polling
  fallback.
- Store timestamps in UTC; present business dates in `Africa/Kampala`.
- Store money as integer minor units plus ISO currency code. Never use floats.

## Laravel conventions

- Use Form Requests, Policies, enums, focused Actions/Services, transactions,
  events/listeners, notifications, and idempotent jobs.
- Enforce roles and record ownership server-side. Navigation visibility is not
  authorization.
- Guard status transitions explicitly and test invalid transitions.
- Keep private files outside the public web root and serve them through
  authorized controllers or expiring signed URLs.
- Verify webhook signatures, amount, currency, ownership, timestamp/replay
  windows, and idempotency before changing business state.
- Record real, redacted audit entries for privileged and financial mutations.

## Required checks

Run the smallest relevant tests while iterating, then before handoff run:

```powershell
composer validate --strict
vendor\bin\pint --test
php -d memory_limit=2G vendor\bin\phpstan analyse --no-progress
php artisan test
npm run build
```

`composer check` runs the first four in order.

PHPStan runs at level 5 with `phpstan-baseline.neon` holding pre-existing
findings. **The baseline may shrink, never grow** — new code must be clean. If an
edit adds a baseline entry, fix the code instead of regenerating the baseline.

SQLite is the fast local default. It cannot exercise row locks or InnoDB
constraint races, so CI additionally runs the whole suite against MariaDB with
`php artisan test --configuration=phpunit.mysql.xml`. That config deliberately
omits every `DB_*` override so a misconfiguration fails to connect rather than
silently passing against SQLite.

Update the traceability matrix and documentation whenever a vertical slice is
advanced. Report incomplete work honestly.
