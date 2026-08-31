# PISFA Tours and Travels

PISFA Tours and Travels is the Laravel rebuild of the PISFA travel, transport,
vehicle, accommodation, and customer-service platform. The target is a secure,
responsive modular monolith that can run on Hostinger Premium shared hosting
without Docker, Redis, Supervisor, a permanent Node.js process, or a
self-hosted WebSocket server.

> **Current status:** active vertical-slice build. The project now has a working
> public inquiry/newsletter slice, Breeze authentication and email verification,
> role/account enforcement, TOTP two-factor authentication, a super-admin staff
> invitation and access workflow, typed settings, audit infrastructure,
> responsive PISFA shells, Livewire, CI, Hostinger deployment guidance, and an
> end-to-end Tours and Safaris module, and in-progress Car Hire and Airport
> Transfers vertical slices. F03, F04, and F05 have local SQLite evidence but remain in progress until
> their MySQL races, browser/accessibility journeys, queued mail, private
> storage, and Hostinger cron behavior pass staging acceptance. Most other business
> modules remain planned; this repository is not production-ready.

## Target stack

- PHP 8.2 or newer, within Hostinger's supported range
- Laravel 12
- MySQL or MariaDB using InnoDB and `utf8mb4`
- Blade, Livewire 3, Alpine.js, and Tailwind CSS
- Vite for assets, compiled before production deployment
- Database-backed queues, cache, and sessions
- Laravel Scheduler invoked by Hostinger cron
- PHPUnit or Pest, Laravel Pint, and Larastan/PHPStan

Laravel Breeze, Laravel Fortify, and Livewire 3 are installed. Continue to
prefer Laravel's native facilities and add maintained packages only where they
provide clear value.

## Product scope

The planned product includes:

- A public marketing website and content-management system
- Authentication, two-factor security, and six access levels
- Tours, safaris, car hire, airport transfers, flight inquiries, vehicle
  imports, vehicle sales, accommodation, and vehicle leasing
- Customer, driver, staff, manager, and super-administrator workspaces
- Corporate clients, group bookings, quotations, invoices, payments, refunds,
  loyalty, referrals, reviews, notifications, chat, and WhatsApp workflows
- Fleet, driver, customer, staff, expense, payroll, audit, reporting, document,
  automation, deployment, PWA, accessibility, and testing capabilities

See [the feature traceability matrix](docs/FEATURE_TRACEABILITY.md) for the
authoritative implementation status of all 30 features.

## Access levels

- **Visitor:** public browsing and eligible guest inquiries or bookings
- **Customer:** own bookings, payments, documents, loyalty, and profile
- **Driver:** own assignments, availability, trip, mileage, and fuel activity
- **Staff:** operational service and customer management
- **Manager:** operational access plus authorized finance and reporting
- **Super administrator:** staff, permissions, security, audit, integrations,
  settings, and all lower-level capabilities

Permissions must be enforced through middleware, policies, gates, and scoped
queries. Navigation visibility is not an authorization boundary.

## Account security and staff access

Every authenticated user can open **Security & two-factor** from the account
navigation. The security screen requires a recent password confirmation before
it exposes a TOTP setup key, QR code, or recovery codes. Enabling two-factor
requires a valid authenticator code. Later logins accept either a current TOTP
code or one recovery code; used recovery codes cannot be reused.

Two-factor is mandatory by default for the comma-separated roles in
`PISFA_TWO_FACTOR_REQUIRED_ROLES` (`super_admin,manager` in the example
environment), and a super administrator can require it for an individual staff
account. An account subject to that policy must finish setup and complete the
two-factor login challenge before it can use protected administration tools.
`PISFA_TWO_FACTOR_CHALLENGE_TTL` is measured in seconds.

Only a verified, active super administrator can open **Team & access**. Creating
a team member creates an inactive account and emails a one-time invitation; the
recipient chooses a strong password, and accepting the invitation activates and
email-verifies the account. Only a hash of the invitation token is stored.
Invitations expire after `PISFA_STAFF_INVITATION_EXPIRY_HOURS`, and resending
revokes earlier pending links. Role, status, and two-factor-policy changes
require password confirmation, are audited, and revoke the affected account's
existing database sessions. The workflow prevents self-demotion/deactivation
and preserves at least one active super administrator.

Invitation URLs are credentials. Configure a real SMTP transport before
issuing them; never use `MAIL_MAILER=log` in an environment where staff can be
invited.

## Tours and safaris

F03 provides a published public catalogue and tour detail pages, customer
booking requests/history/cancellation, and protected staff workspaces for
categories, packages, itineraries, departures, capacity, booking transitions,
and driver assignments. It snapshots exact integer-minor-unit prices and tour
dates, locks departure capacity, rejects overlapping driver work, queues
customer/driver notifications, schedules idempotent reminders, and records one
durable loyalty-eligibility event when a tour is completed.

No payment is collected or implied in this slice. Provider checkout,
allocation, reconciliation, and refunds belong to F14. See the
[Tours and Safaris contract](docs/TOURS_AND_SAFARIS.md) for state rules and the
remaining staging evidence.

## Car hire

F04 provides a published interval-aware vehicle catalogue, authenticated
customer hire requests/history/cancellation, self-drive review with private
identity documents, versioned contracts, and protected staff workspaces for
vehicles, effective-dated rates, booking transitions, and driver assignments.
It snapshots exact integer-minor-unit prices and deposits, expires pending
holds, rejects vehicle and cross-tour driver overlaps, queues notifications,
and schedules idempotent return reminders.

No payment is collected or implied in F04. Provider checkout and financial
allocation belong to F14; branded contract PDF generation belongs to F27. See
the [Car Hire contract](docs/CAR_HIRE.md) for the state, privacy, pricing, and
remaining staging requirements.

## Airport transfers

F05 provides a public guest/customer transfer planner, signed guest
acknowledgement, owner-scoped portal history and cancellation, and protected
airport, location, effective-rate, booking-workflow, and atomic driver/vehicle
assignment screens. It snapshots exact server-selected integer-minor-unit
prices, rejects capacity and cross-service assignment conflicts, queues
customer/guest/driver notices, expires pending requests, and sends idempotent
pickup reminders.

No payment, SMS, WhatsApp, map, or live-flight provider is claimed. F14 owns
checkout and financial state; flight details in F05 are customer-supplied and
unverified. See the [Airport Transfers contract](docs/AIRPORT_TRANSFERS.md) for
the exact state, pricing, privacy, automation, and remaining staging gates.

## Local setup

Install the committed dependencies and create an environment-specific key:

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Configure a dedicated local MySQL database in `.env`, then run:

```bash
php artisan migrate
npm install
npm run dev
php artisan serve
```

For queued work during development:

```bash
php artisan queue:work database --tries=3 --timeout=90
php artisan schedule:work
```

The example environment sets `DB_QUEUE_RETRY_AFTER=120`, safely above the
90-second worker timeout.

Never put production credentials in the repository. Payment, messaging,
storage, analytics, mail, and broadcasting credentials belong in environment
variables.

For a new production database, do not run the demonstration seeders. After
migrations, create the first administrator interactively over a private SSH
session:

```bash
php artisan pisfa:create-super-admin
```

Then sign in and configure two-factor authentication before using protected
administration tools. Preserve the production `APP_KEY`; rotating it makes
encrypted TOTP secrets and recovery codes unreadable and invalidates sessions.

## Engineering rules

- Store timestamps in UTC and display business time in `Africa/Kampala`.
- Store money as integer minor units with an explicit ISO currency code.
- Calculate prices, taxes, discounts, refunds, and balances on the server.
- Use transactions and appropriate locks for availability and financial flows.
- Make callbacks, webhooks, rewards, imports, reminders, and scheduled jobs
  idempotent.
- Use Form Requests for validation and Policies for ownership and permissions.
- Keep controllers and Livewire components thin; place business operations in
  actions or services.
- Store sensitive documents privately and serve them only through authorized
  downloads or short-lived signed URLs.
- Audit material mutations while redacting credentials, tokens, and other
  secrets.
- Do not mark a feature complete until its UI, backend, authorization, tests,
  error states, and documentation all work together.

## Documentation

- [Architecture](docs/ARCHITECTURE.md)
- [Feature traceability](docs/FEATURE_TRACEABILITY.md)
- [Permissions](docs/PERMISSIONS.md)
- [Tours and Safaris](docs/TOURS_AND_SAFARIS.md)
- [Car Hire](docs/CAR_HIRE.md)
- [Airport Transfers](docs/AIRPORT_TRANSFERS.md)
- [Hostinger deployment](docs/HOSTINGER_DEPLOYMENT.md)
- [Testing](docs/TESTING.md)

Additional documents required before production include database design,
integrations, security, testing, operations, backups and restore, and a release
checklist.

## Delivery sequence

1. Audit the original PISFA repository and preserve its valid business rules,
   terminology, content, and media.
2. Confirm Hostinger limits and record architectural decisions.
3. Implement security, roles, settings, storage, audit, notification, queue,
   scheduler, layout, and test foundations.
4. Deliver each feature as a tested vertical slice rather than leaving
   backend-only modules.
5. Integrate providers behind interfaces using sandbox credentials and verified
   webhook signatures.
6. Complete security, accessibility, reconciliation, backup, recovery, and
   role-journey testing.
7. Deploy to staging, verify every traceability entry, then promote through a
   documented and reversible release procedure.

## Production readiness

Production readiness requires all traceability entries to be verified, tests
and static analysis to pass, production assets to build, cron and short-lived
queue workers to run successfully, provider callbacks to be verified, and a
backup/restore exercise to succeed. A model, migration, route, or menu item by
itself does not make a feature complete.
