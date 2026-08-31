# Architecture

## Status

This document defines the proposed architecture for the Laravel rebuild. It is
an initial decision baseline, not a claim that the described modules have
already been implemented. Decisions that depend on the actual Hostinger plan or
third-party credentials must be verified before production.

## Architectural drivers

PISFA combines public commerce, travel operations, fleet management, financial
records, private identity documents, scheduled communications, and multiple
staff roles. The architecture therefore prioritizes:

- Correct ownership and role authorization
- Transactional availability and financial workflows
- Provider-independent payment and communication integrations
- Idempotent callbacks and scheduled work
- Private document handling
- Traceable mutations and reconciled reporting
- Deployment on shared hosting with constrained long-running processes
- Incremental delivery as complete vertical business slices

## Runtime topology

The production target is a Laravel modular monolith running under Hostinger's
Apache or LiteSpeed-compatible PHP environment.

```text
Browser / mobile browser
        |
        v
Hostinger HTTPS web server
        |
        v
Laravel web application -------------------- External providers
        |                                    payments / email / SMS /
        |                                    WhatsApp / media / broadcast
        v
MySQL or MariaDB
        ^
        |
Hostinger cron --> Scheduler --> database queue jobs
```

No production request path may depend on Docker, Redis, Supervisor, a permanent
Node.js process, or a self-hosted inbound WebSocket server. Real-time updates
use a hosted broadcaster when configured and AJAX polling otherwise.

## Application shape

Keep one deployable Laravel application, organized by business domain. Do not
split it into independently deployed services prematurely.

Suggested domain boundaries:

- Identity and Access
- Public Website and CMS
- Customers and Corporate Accounts
- Tours and Safaris
- Car Hire
- Airport Transfers and Flight Inquiries
- Vehicle Imports
- Vehicle Sales
- Accommodation
- Vehicle Leasing
- Fleet and Drivers
- Bookings and Assignments
- Quotations and Invoices
- Payments, Refunds, and Reconciliation
- Loyalty and Referrals
- Reviews
- Notifications, Chat, and WhatsApp
- Expenses and Payroll
- Analytics and Exports
- Documents and Media
- Settings and Audit
- Automation and Operations

Domain boundaries are namespaces and ownership conventions inside the monolith,
not separate databases or network services.

## Layering

### Delivery layer

- Route files divided into public, authentication, customer, driver, staff,
  manager, super-administrator, webhook, and versioned API surfaces
- Thin controllers and Livewire components
- Form Requests for HTTP validation
- Blade view components for shared presentation
- API Resources for stable JSON responses

### Authorization layer

- Authentication middleware for protected surfaces
- Role and permission middleware for broad access
- Policies for every owned or privileged resource
- Scoped Eloquent queries to prevent cross-account data exposure
- Explicit webhook middleware for signatures, replay protection, and rate limits

### Application layer

- Single-purpose action classes for commands such as creating a booking,
  allocating a payment, recording a refund, assigning a driver, and converting
  a quotation
- Query services for dashboards and reports
- Database transactions around multi-record operations
- Events emitted after a transaction commits
- Listeners and queued jobs for slow or external side effects

### Domain and persistence layer

- Eloquent models with explicit relationships, casts, scopes, and guarded
  attributes
- PHP backed enums for roles, states, service types, payment methods, and
  currencies
- Transition services that reject invalid state changes
- Database constraints that enforce identities and uniqueness
- Factories and seeders that support repeatable tests and demonstrations

### Integration layer

Provider contracts isolate business logic from third parties:

- Payment gateway
- Email delivery
- SMS delivery
- WhatsApp messaging
- Media and document storage
- Hosted broadcasting
- Exchange-rate lookup
- Analytics or telemetry

Every provider adapter must normalize its responses, redact logs, expose a
sandbox or fake implementation for tests, and validate callbacks according to
the provider's current official specification.

## Data design principles

### Identifiers and references

Use internal database identifiers for relationships and separate random,
unguessable public references for tracking, receipts, contracts, and guest
workflows. Never expose sequential identifiers as the only protection for a
guest lookup.

### Money

- Store amount values as integer minor units.
- Store an explicit three-letter currency code with each amount.
- Do not use floating-point arithmetic.
- Store the applied exchange rate and calculation context when conversion occurs.
- Treat payments, allocations, refunds, invoice balances, and loyalty redemption
  as ledger-like records rather than overwriting historical amounts.

### Time

Store timestamps in UTC. Display and interpret PISFA business dates using
`Africa/Kampala`, except when a user or integration explicitly requires another
timezone. Record provider timestamps and raw identifiers separately.

### Availability and assignments

Tour capacity, vehicle hire, accommodation rooms, drivers, and fleet vehicles
can be contended resources. Availability must be rechecked inside a database
transaction immediately before confirmation. Use appropriate locks, unique
constraints, and overlap queries rather than trusting a previously rendered
screen.

### States

Use backed enums and explicit transition maps. A user cannot submit an arbitrary
status string. Transitions that affect finance, inventory, loyalty, or
notifications run through one application action so side effects happen once.

### Deletion and retention

Financial, audit, contract, payroll, and provider callback records should not be
hard-deleted in ordinary operation. Apply soft deletion only when its restore
and query behavior is well understood. Define legal and operational retention
periods before production.

## Security model

- Laravel session authentication for the browser application
- Sanctum only for a real versioned API or future first-party client
- CSRF protection on browser mutations
- Form Request validation and escaped Blade output
- Sanitization for administrator-authored rich text
- Policies plus ownership scopes on every protected record
- Secure, HTTP-only, same-site cookies over HTTPS
- TOTP two-factor authentication and recovery codes
- Optional mandatory two-factor authentication for privileged roles
- Rate limiting for authentication, guest tracking, chat, and sensitive actions
- Encryption for TOTP secrets and sensitive application values
- Signature, timestamp, amount, currency, and replay validation on callbacks
- Private storage for identity, permit, payroll, contract, receipt, and import
  documents
- Audit logs with redacted before/after data

Authorization rules are documented in [PERMISSIONS.md](PERMISSIONS.md).

### Implemented identity and team-access slice

Laravel Fortify provides the TOTP primitives while the application retains its
PISFA-branded Breeze screens and account-state rules. The security page is
available to every authenticated account after recent password confirmation.
Setup is not considered enabled until the user proves possession with a valid
authenticator code. TOTP secrets and recovery-code sets are encrypted using the
application key; a recovery code is consumed when used. Login challenges are
rate limited and pending challenge state expires according to
`PISFA_TWO_FACTOR_CHALLENGE_TTL`.

Required 2FA is the union of the roles listed in
`PISFA_TWO_FACTOR_REQUIRED_ROLES` and an account-specific policy flag. Required
accounts cannot use the protected administration surface without configured
2FA and a verified challenge for the current session. Required accounts cannot
disable 2FA through the normal security action.

Staff provisioning is invitation-only and super-admin-only. A new staff record
starts inactive with an unusable random password. The emailed invitation holds
the only raw setup token; the database stores its SHA-256 hash. Acceptance is
single-use, expiry-checked, sets the recipient's chosen password, verifies the
invited email address, activates the account, and directs required accounts to
2FA setup. Resending revokes earlier pending invitations. Privileged access
changes require recent password confirmation, revoke database sessions, and
write redacted audit events. Guards prevent a super administrator from
demoting or deactivating themselves and prevent removal of the last active
super administrator.

Because an invitation URL grants account setup, any environment capable of
issuing invitations must deliver mail through a protected SMTP transport. The
log mailer is not acceptable for that environment because it exposes the raw
URL in application logs.

## Queues and scheduled work

Use the database queue driver. Jobs must have bounded attempts, timeouts,
backoff, safe exception handling, and enough persisted state to retry without
duplicating a payment, reward, message, report, or status change.

Hostinger cron invokes:

- `schedule:run` at the shortest supported interval
- A short-lived `queue:work database --stop-when-empty` command at a safe interval

Use scheduler overlap protection and database processed/sent markers. Because
shared hosting may not provide a single always-running process, every critical
workflow needs a visible failed state and an authorized retry or synchronous
fallback.

## Documents and media

Public marketing media can use public storage or Cloudinary. Identity documents,
driving permits, contracts, receipts, payroll files, import papers, and private
chat attachments must use private storage.

Private files are returned only by an authorized controller or a short-lived
signed provider URL. Validate content, MIME type, extension, size, and image
dimensions; generate random storage names; keep original names only as escaped
metadata.

## UI architecture

- Public, customer, driver, staff, manager, and super-administrator layouts
- Blade for page structure and server-rendered content
- Livewire for forms, filters, tables, modals, dashboards, and stateful workflows
- Alpine.js for small local interactions
- Tailwind CSS for the design system
- Shared components for form fields, status badges, tables, pagination, money,
  dates, empty states, errors, confirmations, and authorized downloads

Accessibility is a delivery requirement: keyboard access, visible focus,
semantic structure, labels, error announcements, sufficient contrast, and
useful loading, empty, denied, offline, and failure states.

## Observability and audit

Application logs must be structured enough to correlate a request, user,
booking, transaction, and provider callback without logging passwords, tokens,
full documents, payment credentials, or other secrets. Health checks should
cover application boot, database access, writable storage, queue failures, and
critical configuration without exposing configuration values.

Material changes emit immutable audit records containing actor, action, entity,
redacted changes, IP, user agent, and timestamp. Creating an audit table is not
enough; mutation actions must invoke the logger.

## Testing strategy

- Unit tests for calculations, state machines, adapters, and value objects
- Feature tests for routes, validation, authorization, ownership, and workflows
- Database tests for conflicts, constraints, transactions, and idempotency
- Provider contract tests using fakes and signed fixtures
- Browser tests for primary journeys for every role
- Accessibility checks for critical public and authenticated pages
- Backup and restore rehearsal on staging

Use MySQL-compatible behavior in integration tests for logic that depends on
locking, indexes, JSON, date arithmetic, or database constraints.

## Deployment decisions still to verify

- Exact PHP version and enabled extensions
- SSH and Composer availability for the purchased plan
- Cron frequency and command limits
- Document-root configuration and symlink behavior
- Maximum upload, memory, execution time, and storage limits
- Outbound connectivity and provider callback reachability
- Chosen private-media storage provider
- Chosen hosted broadcasting provider, if real-time updates are required
- Off-site backup destination and retention policy

See [HOSTINGER_DEPLOYMENT.md](HOSTINGER_DEPLOYMENT.md) for the deployment
baseline. No production launch should proceed until these items are tested on a
staging subdomain using the actual hosting account.
