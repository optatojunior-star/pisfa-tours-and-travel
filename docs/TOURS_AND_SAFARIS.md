# Tours and Safaris (F03)

## Scope

F03 is implemented as a Laravel vertical slice covering public discovery,
customer booking requests, operational catalogue and availability management,
booking workflow, traveler records, driver assignment, reminders, and a durable
loyalty-eligibility event. It deliberately does not claim that a payment was
taken; provider checkout and reconciliation belong to the payments feature.

The public surfaces are `/tours` and `/tours/{slug}`. An active, verified
customer can submit a booking request and manage owned records under
`/portal/bookings`. Active staff, managers, and super administrators manage the
module under `/admin/tours`, `/admin/tour-categories`, and
`/admin/tour-bookings`. Policies remain authoritative behind route middleware.

## Data contract

- Categories can be activated, ordered, and safely deactivated only after their
  published packages are archived.
- Packages retain a draft, published, or archived state plus ordered media,
  itinerary days, inclusions, and exclusions. Publication requires an active
  category, exactly one cover image, an itinerary day for every package day,
  and at least one inclusion and exclusion.
- Departures are the lockable availability unit. Each stores its UTC start,
  end and cutoff, capacity, state, and optional integer-minor-unit price
  override.
- A booking snapshots package identity, dates, cutoff, unit price, total,
  currency, and customer contact data. Later catalogue or profile edits cannot
  rewrite the commercial record.
- Traveler rows must exactly match the server-counted booking party. Passport
  and identity-document data are intentionally not collected in F03.
- The booking stores the current driver for efficient operational queries while
  assignment rows preserve every assignment and release.
- Booking events use a unique booking/event constraint for reminder and future
  loyalty processing idempotency.

All money is an integer number of minor units with an ISO currency code. F03
accepts the configured intersection of UGX and USD only: UGX uses whole units
and USD uses two decimal places. Application timestamps are UTC; staff and
customer screens render them in `Africa/Kampala`.

## Availability, pricing, and duplicate safety

`CreateTourBooking` locks the customer, package, and selected departure inside
one database transaction. It then rechecks publication, category state,
departure state, future cutoff, group limits, traveler count, and the sum of
capacity-holding bookings. Pending, confirmed, and in-progress bookings reserve
places; cancellation releases them because cancelled records are excluded from
that sum.

Price and currency always come from the locked departure/package. Posted owner,
reference, price, total, currency, status, capacity, and driver values are not
accepted as authority. A customer-generated UUID is unique per customer. A
replayed identical request returns the original booking; reusing the key for a
different payload is rejected.

SQLite is useful for fast functional tests but does not enforce
`lockForUpdate()`. Before release, run the documented two-connection last-seat
race against the same MySQL/MariaDB and isolation settings used on Hostinger.

## Booking and departure states

Booking transitions are explicit:

```text
pending -> confirmed -> in_progress -> completed
   |           |
   +-----------+-> cancelled
```

Completed and cancelled bookings are terminal. Confirmation requires a future,
scheduled departure and a complete traveler list. A tour cannot start before
departure. Completing a booking creates one unprocessed `loyalty_eligible`
event; the future loyalty module will consume it rather than F03 inventing or
duplicating points.

Customers can cancel only owned pending or confirmed bookings before the
snapshotted cutoff, with a reason and explicit acknowledgement. Operations may
cancel the same eligible states after the cutoff but must provide a reason.
Bookings are never deleted. Departure cancellation/completion is refused while
capacity-holding bookings remain, preventing silent customer cancellation.

## Driver assignments

Only active, email-verified users with the driver role can be assigned, and
only to confirmed or in-progress bookings. The assignment action locks the
driver row and rejects another active assignment when:

```text
existing.starts_at < requested.ends_at
and existing.ends_at > requested.starts_at
```

This catches partial and contained overlaps while allowing back-to-back work.
Reassignment closes the prior history row; unassignment requires an operational
reason. Customer and driver notifications are queued only after commit.

## Reminders and Hostinger operation

The `tours:send-departure-reminders` command finds confirmed bookings in the
configured half-open reminder window. Both scheduled departures and departures
closed to further sales remain reminder-eligible; cancelled or completed
departures do not. The command first creates the unique reminder event and then
queues a retryable mail/database notification. Repeated or overlapping command
runs do not create a second logical reminder.

Laravel evaluates this command every minute with overlap prevention. Production
needs both short-lived cron entries from the deployment runbook:

```text
* * * * * <PHP_BINARY> <APP_PATH>/artisan schedule:run --no-interaction
*/5 * * * * <PHP_BINARY> <APP_PATH>/artisan queue:work database --stop-when-empty --max-time=180 --tries=3 --timeout=90 --no-interaction
```

Keep `DB_QUEUE_RETRY_AFTER=120` or higher with that 90-second worker timeout.
Laravel requires `--timeout` to be several seconds shorter than the queue
connection's `retry_after` value to prevent overlapping delivery attempts.

The F03 environment contract is:

- `PISFA_SUPPORTED_CURRENCIES=UGX,USD` controls the configured currencies; F03
  ignores unsupported codes.
- `PISFA_TOUR_CANCELLATION_CUTOFF_HOURS=48` supplies the default persisted
  departure cutoff.
- `PISFA_TOUR_MAXIMUM_BOOKING_TRAVELERS=50` caps one booking and is clamped to
  the supported range of 1 through 500.
- `PISFA_TOUR_REMINDER_LEAD_MINUTES=1440` and
  `PISFA_TOUR_REMINDER_WINDOW_MINUTES=15` define the reminder interval.
- `PISFA_TOUR_MAIL_MAX_PER_MINUTE=8` throttles queued tour email; set it at or
  below the verified mailbox/provider limit. Database notifications are not
  held by the mail throttle.

Verify actual scheduler recency, queued delivery, SMTP, provider allowances,
failed-job behavior, and retries in staging; an hPanel cron entry alone is not
acceptance evidence.

## Release evidence still required

Local SQLite tests cover the business and authorization paths, but F03 remains
in progress until the MySQL two-connection capacity and assignment races,
responsive browser/accessibility journeys, SMTP/database-queue delivery, and
Hostinger scheduler execution have been demonstrated in staging. Failed-job
visibility and an authorized retry or manual-reminder fallback are also not yet
implemented and remain release requirements under F18/F28.
