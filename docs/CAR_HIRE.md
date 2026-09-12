# Car Hire (F04)

## Scope

F04 is implemented as a Laravel vertical slice for a public vehicle catalogue,
interval availability, authenticated customer hire requests, self-drive review,
private identity documents, immutable booking-specific contracts, operational
booking transitions, and driver assignment history. It deliberately records
amounts due without claiming that payment was taken. Checkout, provider
callbacks, allocation, reconciliation, receipts, and refunds belong to F14.

Public users can browse `/car-hire` and eligible published vehicle details. An
active, email-verified customer can request a vehicle and manage owned records
under `/portal/car-hire`. Active staff, managers, and super administrators use
the protected car-hire catalogue and booking screens under `/admin`. Model
policies and owner-scoped route binding remain authoritative behind those
interfaces.

## Data and availability contract

- `vehicles` is the shared vehicle foundation intended for later extension by
  F21. Public catalogue status is separate from operational availability; a
  vehicle is never globally marked "booked".
- Ordered public media belongs to a vehicle. Publication requires exactly one
  cover image and at least one currently effective supported hire rate.
- Body type, fuel, transmission, drive and condition are chosen from
  `App\Enums\Vehicle*`, surfaced through `App\Support\VehicleSpecification`, and
  shared with the showroom. Engine capacity is an integer `engine_cc` picked
  from the displacements actually sold in this market.

### Why the specification is a closed list

These were free-text boxes hinting "lowercase key, for example suv". That asked
whoever was publishing to invent a vocabulary, and the inventions disagreed —
the showroom filled with `Diesel`/`Automatic` while the fleet filled with
`diesel`/`automatic`, so no filter could match both and a car retiring from hire
into the showroom changed its own specification on the way.

Adopting a closed list on a table that already held open text has one rule that
makes it safe: `VehicleSpecification::optionsPreserving()` keeps a record's
current value in its own dropdown, and `allowedValues()` keeps the same value
valid. A vehicle recorded before the lists existed stays editable and is never
silently rewritten by a screen nobody touched.
`2026_09_10_000200_normalise_vehicle_specification_values` folds the unambiguous
variants together, through `App\Support\VehicleSpecificationNormaliser`. The
logic sits in a class rather than inside the migration because it rewrites
production rows and therefore needs tests on both engines — its first version
passed on SQLite and was wrong on MySQL, where a case-insensitive `DISTINCT`
hid every capitalised spelling. See the fourth item under "What SQLite hides"
in [docs/TESTING.md](TESTING.md).

Condition is deliberately not force-mapped: "good" does not say whether a
vehicle was imported or bought locally, and that is the distinction the new list
draws.

## Side-by-side comparison

`car-hire.compare` takes two to four slugs and renders `VehicleComparison::rows()`
as a table. A catalogue card can only answer "what is this one"; choosing
between a Prado and a Hiace is a question about the differences, and answering
it meant opening two tabs and scrolling between them.

Three decisions worth keeping:

- **The selection lives in the query string, not in the browser.** The person
  choosing the vehicle and the person approving the cost are usually not the
  same person, so the comparison has to be something you can send.
- **Rows that differ are marked and sorted first.** A table where eleven of
  thirteen rows read identically has buried the two that matter.
- **It obeys the catalogue's own visibility rule.** The address is public and
  hand-editable, so `compare()` filters through `acceptingHire()` and
  `has('bookableHireRates')`, and a selection that leaves fewer than two visible
  vehicles is a 404 rather than a single-column table. Otherwise it would be a
  way to read draft stock by guessing a slug.

The catalogue also filters on `drive_type` now. "Is it 4WD" is the first
question a customer heading for Kidepo or Bwindi asks, and until the column
existed the catalogue could not answer it at all. Filter dropdowns list only the
values the bookable fleet actually holds, so a filter never returns nothing.

`Vehicle::bookableHireRates()` is the relation behind both: switched on, inside
its effective window, and priced for at least one mode. It replaced the same
constraint written twice as closures passed to `whereHas()` and `with()`.

## Publication readiness

`App\Support\Publishing\VehicleReadiness` answers "can this go live yet?" as a
list of checks, each carrying what is wrong, what to do about it, and where on
the screen to do it. `SaveVehicle` raises every outstanding failure at once,
against the field that fixes it, and `admin/vehicles/show` renders the same
checks as a visible checklist with the Publish button disabled until they pass.

This replaced a single message — *"A published vehicle needs a currently
effective supported rate for at least one hire mode."* — raised against
`catalogue_status`, a dropdown at the top of a form that describes neither
photographs nor prices. It covered five different failures without naming any of
them: no price at all, both amounts blank, a currency the catalogue does not
sell in, a price switched off, a price whose window has not started, and a price
that has expired. Each is now reported separately, and both media and price
problems arrive together rather than one round trip at a time.
- Hire rates are immutable effective-dated versions by vehicle and currency.
  A new version closes the preceding interval without disabling a still-current
  rate merely because its successor starts in the future.
- A booking snapshots its vehicle identity, registration plate, rate, rental
  subtotal, refundable security-deposit amount, total due, currency, interval,
  locations, and customer contact details. Later catalogue edits do not rewrite
  the commercial record.
- Self-drive applications store encrypted identity and permit numbers. Private
  document rows store a randomized path, detected MIME type, byte size, and
  SHA-256 digest. Contract rows retain immutable versioned snapshots, terms,
  content hashes, and issue/accept/void metadata.
- Driver assignment rows preserve assignment and release history; the booking
  stores only the current driver for efficient operational queries.

Intervals are UTC and half-open: `[pickup_at, return_at)`. A conflict exists
when an existing held booking starts before the requested return and ends after
the requested pickup. Exact back-to-back hires are therefore allowed. Pending
requests reserve a vehicle only until their persisted `hold_expires_at` value;
confirmed and in-progress bookings continue to reserve it.

## Pricing and duplicate safety

The booking action locks the active customer and vehicle, then rechecks public
and operational state, full-interval rate coverage, mode support, and overlap
inside one database transaction. It calculates:

```text
billable_days = max(1, ceiling(duration_seconds / 86,400))
rental_subtotal = daily_rate * billable_days
total_due = rental_subtotal + security_deposit
```

All values are integer minor units with an explicit configured currency, and
overflow is rejected. Posted prices, totals, status, customer, plate, rate, or
driver identifiers are not accepted as commercial authority. A UUID unique per
customer makes identical retries return the first request and rejects reuse for
a different payload.

SQLite provides functional evidence but does not enforce `lockForUpdate()`.
Before release, repeat exact-overlap, last-vehicle, and cross-service driver
races with two independent connections against the staging MySQL/MariaDB
version and isolation settings used on Hostinger.

## Booking, application, and contract states

Booking transitions are explicit:

```text
pending -> confirmed -> in_progress -> completed
   |          |
   +----------+-> cancelled
   +-> declined
   +-> expired
```

Terminal states are immutable. Confirmation rechecks the locked vehicle, exact
selected rate snapshot, interval availability, and acceptance of the current
contract. Self-drive confirmation also requires an approved application.
Starting a with-driver hire requires one current eligible driver assignment;
starting self-drive also requires an authorized staff member to record that
original documents were checked. Timing guards prevent starting before pickup
or completing before return. Completing emits one durable, unprocessed
loyalty-eligibility event for F16 rather than inventing points in F04.

Self-drive application states are `draft`, `submitted`, `needs_information`,
`approved`, and `rejected`. Customers may edit a draft or a request for more
information while the booking hold remains live. Submission requires National
ID and driving-permit uploads, an explicit accuracy declaration, and a permit
whose entered expiry covers the requested return date. Staff review decisions
require appropriate reasons; original verification is a separate, explicit
operation.

Contracts are issued from the exact booking snapshot and terms version. The
customer accepts the current, non-voided version; acceptance stores the actor,
time, IP, and bounded user-agent evidence. A material booking change must void
and reissue a new version before any new acceptance. The current F04 contract
is an immutable printable record; branded PDF generation and generalized
document-version infrastructure remain part of F27.

The supplied terms intentionally do not invent statutory age, licence,
insurance, damage, cancellation-charge, deposit-refund, or retention rules.
PISFA must have those points reviewed and approved for the operating
jurisdiction before production handover.

## Private documents and authorization

Identity files use the configured private Laravel disk and are never exposed by
a public storage URL. The upload action checks real file content, size, and the
extension/MIME pairing; uses a random server path; records a content hash; and
removes newly written files if the database transaction fails. Replacement and
deletion preserve database/file consistency as far as the storage adapter
allows.

Only the owning active customer and authorized operations users can download an
identity document through a guarded controller. Foreign customer references
resolve as not found. Drivers cannot view customer identity files. Downloads
set private/no-cache and content-sniffing protections. Audit records include
document type and safe metadata, never an identity number or storage path.

`PISFA_PRIVATE_DOCUMENT_DISK=local` uses Laravel's private local storage in the
default deployment. Do not point it at a publicly served disk. Preserve the
production `APP_KEY`: changing it makes encrypted identity and permit numbers
unreadable.

## Driver assignment and automation

Only an active, email-verified driver can be assigned to a with-driver booking.
Both F03 tour and F04 hire assignment actions lock the same driver row and
reject active overlaps across both services while allowing back-to-back work.
Reassignment closes the prior history record; unassignment requires a reason.

Laravel schedules these idempotent commands every minute with overlap
prevention:

- `car-hire:expire-pending-bookings` releases expired pending holds, voids
  their current contracts, and records one expiry event.
- `car-hire:send-return-reminders` creates one durable marker and queues one
  retryable mail/database notification for eligible confirmed or in-progress
  hires in the configured return window.

The normal Hostinger scheduler and short-lived database queue cron entries in
the deployment runbook execute these commands. Email jobs use bounded retry and
backoff and a car-hire mail-only throttle; database notifications are not held
by that mail throttle.

## Environment contract

- `PISFA_CAR_HIRE_MAXIMUM_DAYS=90`
- `PISFA_CAR_HIRE_MINIMUM_NOTICE_HOURS=2`
- `PISFA_CAR_HIRE_CANCELLATION_CUTOFF_HOURS=48`
- `PISFA_CAR_HIRE_PENDING_HOLD_MINUTES=1440`
- `PISFA_PRIVATE_DOCUMENT_DISK=local`
- `PISFA_CAR_HIRE_DOCUMENT_MAX_KB=5120`
- `PISFA_CAR_HIRE_CONTRACT_VERSION=2026-08-20`
- `PISFA_CAR_HIRE_RETURN_REMINDER_LEAD_MINUTES=1440`
- `PISFA_CAR_HIRE_RETURN_REMINDER_WINDOW_MINUTES=15`
- `PISFA_CAR_HIRE_MAIL_MAX_PER_MINUTE=8`

F04 accepts only its configured intersection of UGX and USD. Provider limits,
cron execution, private storage permissions, queued delivery, retry behavior,
and actual policy wording must be verified in staging.

## Release evidence still required

F04 remains **In progress** until the MySQL interval and cross-service driver
races, real responsive browser/accessibility journeys, private-storage behavior
on the chosen Hostinger layout, SMTP/database-queue delivery, scheduler recency,
failed-job visibility/retry, and stakeholder-approved operating terms have all
been demonstrated. F14 payment checkout and F27 branded PDF generation remain
separate incomplete feature dependencies.
