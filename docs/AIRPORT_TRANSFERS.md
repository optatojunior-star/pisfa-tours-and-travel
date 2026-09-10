# Airport Transfers (F05)

## Scope

F05 is implemented as a Laravel vertical slice for server-priced airport
transfer requests. It provides a public planner for guests and customers, a
signed guest acknowledgement, owner-scoped customer history and cancellation,
and protected operational screens for airports, service locations, immutable
effective-dated rates, booking transitions, and atomic driver-and-vehicle
assignments.

This slice records an exact amount due but does not collect or record a
payment. Checkout, payment status, allocation, receipts, reconciliation, and
refunds belong to F14. Flight numbers and times are customer-supplied planning
details; the application does not claim live flight tracking. Mail and database
notifications are implemented, but no SMS or WhatsApp delivery is implied.

## Route, time, and pricing contract

An active price rule identifies one airport, service location, transfer
direction, shared vehicle type, currency, passenger and luggage capacity,
integer-minor-unit amount, estimated duration, and half-open effective period.
The server selects the one effective rule for the requested service time.
There is no client-owned amount, fallback fare, distance estimate, or currency
conversion. When no exact rule exists, the planner reports that the route is
not currently priced and offers the genuine quotation workflow.

Every booking snapshots the airport and location names, airport code, vehicle
type and capacities, amount, currency, estimated duration, service interval,
flight schedule, party size, contact details, and exact address. Later
catalogue or rate edits do not rewrite that record. Money uses integer minor
units and the configured intersection of UGX and USD.

All persisted timestamps are UTC and are displayed in the configured PISFA
business timezone. Assignment availability uses the strict half-open interval
`[service_starts_at, service_ends_at)`, so exact back-to-back work is allowed.

- For an airport pickup, `service_starts_at` is initially derived from the
  customer-supplied scheduled flight arrival.
- For an airport drop-off, the customer supplies a separate address pickup
  time and later scheduled flight departure. The estimated airport arrival
  must be earlier than that departure.
- `service_ends_at` is derived from the selected rate's snapshotted duration.

Flight data is marked as unverified. Operational changes require a deliberate
audited workflow; a future flight-data provider must not silently mutate the
resource-reservation interval.

## Guest, customer, and duplicate safety

Guests provide a name, email address, telephone number, exact service address,
and explicit booking-details acknowledgement. The response uses a random
`TRNSF-` reference and a temporary signed acknowledgement URL; it is not an
electronic signature or a public lookup endpoint. Sensitive fields, internal
notes, assignment history, and identifiers are not exposed on that page.

An authenticated customer is linked on the server and the account's name,
email, and telephone replace any posted identity values. Customer route binding
is owner-scoped so a foreign reference returns not found. Guests and customers
must provide a UUID idempotency key. A replay with the same owner and normalized
payload returns the original booking; reusing the key for different input is
rejected. Posted customer, rule, amount, status, payment, driver, and vehicle
fields are never commercial authority.

Exact service addresses and flight numbers use encrypted model casts and are
hidden from normal serialization. Preserve the production `APP_KEY`; changing
it makes those values unreadable. Audit events contain safe IDs, states, times,
capacity, and integer money, not raw contact details, addresses, flight
numbers, idempotency values, or internal notes.

## Booking and assignment states

The explicit booking graph is:

```text
pending -> confirmed -> in_progress -> completed
   |          |
   +----------+-> cancelled
   +-> declined
   +-> expired
```

Pending means the request was received and awaits operational commitment. It
does not promise availability. A pending request may receive one atomic active
assignment containing both an eligible driver and an eligible vehicle.
Confirmation is allowed only when that pair exists and the booking's projected
driver and vehicle agree with the active history row. Starting requires the
same live pair and the permitted service time. Completion is allowed only at
the end of the service interval and creates one durable, unprocessed
loyalty-eligibility marker for F16; F05 does not invent points.

Terminal states are immutable. Cancelling, declining, expiring, or completing
closes the active assignment and clears the current projections. Customer
cancellation is limited to owned pending or confirmed requests before the
persisted cutoff and requires an explicit reason and acknowledgement.

Assignment locks the selected driver, vehicle, and booking before it rechecks:

- an active, email-verified driver account;
- an operational vehicle with matching type and sufficient passenger and
  luggage capacity;
- no strict interval overlap with another airport transfer;
- no driver overlap with F03 tours or F04 with-driver hire;
- no vehicle overlap with an active F04 car-hire hold or booking.

The reciprocal F03 and F04 actions also check active transfer assignments, and
F04 vehicle availability checks transfers. Reassignment closes the full old
driver-and-vehicle pair and writes a new history row; partial active assignment
rows are not allowed.

## Authorization and delivery

The planner and booking submission are public and rate limited. Active,
email-verified customers manage only their own transfers under
`/portal/airport-transfers`. Active staff, managers, and super administrators
manage transfer settings and operations under `/admin`, behind email
verification, role middleware, required two-factor authentication, policies,
Form Requests, and transactional actions. Drivers do not gain customer or
administration access in F05; they receive only the minimum operational details
needed for assigned work until the F22 driver workspace exists.

Responsive Blade screens provide explicit initial, empty, no-rate, validation,
pending, unavailable, and terminal states. Every customer-facing amount panel
says that no online payment has been collected. There is no Pay button,
payment-method choice, payment-status editor, receipt claim, live map, flight
tracker, SMS promise, or WhatsApp promise.

Registered customers receive queued mail and database notifications. Guests
receive routed mail only. Drivers receive assignment mail and database
notifications with the operational route, time, service telephone, and address,
but without customer email, price, internal notes, or unrelated history. Jobs
are dispatched after commit, use bounded retries/backoff, and apply a mail-only
rate limiter so database notifications are not delayed by the SMTP allowance.

## Delivered surfaces

Routes live in `routes/airport-transfers.php`, required from `routes/web.php`.

| Surface | Route name | Controller | Screen |
|---|---|---|---|
| Public planner and quote | `airport-transfers.index` | `AirportTransfers\AirportTransferPlannerController@index` | `airport-transfers/index.blade.php` |
| Public request submission | `airport-transfer-bookings.store` | `AirportTransfers\AirportTransferPlannerController@store` | redirects to portal or signed acknowledgement |
| Guest acknowledgement (`signed`) | `airport-transfer-bookings.guest.show` | `AirportTransfers\AirportTransferPlannerController@guest` | `airport-transfers/guest.blade.php` |
| Customer history | `portal.airport-transfer-bookings.index` | `AirportTransfers\AirportTransferPortalController@index` | `airport-transfer-bookings/index.blade.php` |
| Customer detail | `portal.airport-transfer-bookings.show` | `AirportTransfers\AirportTransferPortalController@show` | `airport-transfer-bookings/show.blade.php` |
| Customer cancellation | `portal.airport-transfer-bookings.cancel` | `AirportTransfers\AirportTransferPortalController@cancel` | same detail screen |
| Operations console | `admin.airport-transfer-bookings.index` | `Admin\AirportTransferBookingController@index` | `admin/airport-transfer-bookings/index.blade.php` |
| Operations detail, status, assignment, reschedule | `admin.airport-transfer-bookings.{show,transition,assignment,reschedule}` | `Admin\AirportTransferBookingController` | `admin/airport-transfer-bookings/show.blade.php` |
| Airports, locations, and rate versions | `admin.airport-transfer-settings.*` | `Admin\AirportTransferSettingController` | `admin/airport-transfer-settings/index.blade.php` |

### Who is meeting you

`airport-transfers/partials/driver-card.blade.php` is shared by the customer
portal and the guest acknowledgement. It shows the driver's photograph, their
name, the vehicle, its **number plate**, and a `tel:` link plus a WhatsApp link
to the driver's own number.

"Where is my driver" is the call this business takes most often. The
confirmation used to answer it with a name and, where the office had recorded
one, a phone number as plain text — which helps only after a stranger has
already started the conversation. The guest page did not show the driver at all:
it said the team would confirm a vehicle and driver, and then never did.

The photograph comes from `DriverProfile::photographUrl()`, a short-lived signed
link — see [docs/UPLOADS_AND_DOCUMENTS.md](UPLOADS_AND_DOCUMENTS.md) for why it
is private rather than public media like a team photo. A driver with no
photograph falls back to their initial rather than a stock silhouette, which is
honest about there being no photograph.

Both controllers must keep `registration_plate` in the `assignedVehicle` column
list. It is excluded from serialisation by `Vehicle::$hidden` but the plate is
exactly what a customer checks in a car park, so the confirmation reads it
directly.

### The published price list

`AirportTransferPlannerController::priceList()` reads every rate currently in
force, for active airports and locations, in one query, and groups it by
airport-and-place — the unit a customer thinks in ("Entebbe to Kampala"), then
the vehicles.

It exists because pricing was accurate and completely invisible. The planner
would not name a figure until it had a direction, an airport, a location, a
currency, a flight time, a party size and a luggage count: a form for somebody
who has already chosen PISFA, not for somebody deciding whether to. The table
answers "what does Entebbe to Kampala cost, and in what vehicle" on arrival,
each row links into the planner with that route preselected, and the planner
remains the only thing that produces a bookable quote.

The list is display only. Every price is resolved again server-side against the
flight time when a request is submitted, so a stale table can never become a
stale booking.

Server-side pricing for the planner comes from `Actions\AirportTransfers\
QuoteAirportTransfer`, which resolves the same effective rate version that
`CreateAirportTransferBooking` selects, so a quoted price and a booked price
cannot diverge. Guarded status changes come from `Actions\AirportTransfers\
TransitionAirportTransferBooking`, which delegates cancellation to
`CancelAirportTransferBooking`, requires an assigned and still-eligible vehicle
and driver before confirming or starting, releases assignment history on
terminal outcomes, and writes one `LoyaltyEligible` event per completed
transfer through `firstOrCreate`.

The assignment dropdown lists only vehicles the assignment action will accept:
operationally available, matching the booked vehicle class, and large enough for
the booked passengers and luggage.

Automated coverage lives in `tests/Feature/AirportTransfers/`:
`AirportTransferPlannerHttpTest`, `AirportTransferRouteAuthorizationTest`,
`AirportTransferAdministrationHttpTest`, and
`AirportTransferPortalAndLifecycleTest`.

## Automation and environment

Laravel schedules these idempotent commands every minute with overlap
prevention:

- `airport-transfers:expire-pending-requests` transitions requests after their
  persisted review deadline, releases any assignment, records one expiry event,
  and queues retryable notices.
- `airport-transfers:send-pickup-reminders` records one durable marker and
  queues one reminder for an eligible confirmed transfer in the configured
  window.

Configure and verify:

- `PISFA_AIRPORT_TRANSFER_MAXIMUM_PASSENGERS=50`
- `PISFA_AIRPORT_TRANSFER_MAXIMUM_LUGGAGE=100`
- `PISFA_AIRPORT_TRANSFER_MINIMUM_NOTICE_HOURS=2`
- `PISFA_AIRPORT_TRANSFER_MAXIMUM_ADVANCE_DAYS=365`
- `PISFA_AIRPORT_TRANSFER_REQUEST_EXPIRY_MINUTES=1440`
- `PISFA_AIRPORT_TRANSFER_CANCELLATION_CUTOFF_HOURS=4`
- `PISFA_AIRPORT_TRANSFER_RATE_MINIMUM_DURATION_MINUTES=15`
- `PISFA_AIRPORT_TRANSFER_RATE_MAXIMUM_DURATION_MINUTES=1440`
- `PISFA_AIRPORT_TRANSFER_GUEST_CONFIRMATION_EXPIRY_HOURS=168`
- `PISFA_AIRPORT_TRANSFER_MAIL_MAX_PER_MINUTE=8`
- `PISFA_AIRPORT_TRANSFER_PICKUP_REMINDER_LEAD_MINUTES=1440`
- `PISFA_AIRPORT_TRANSFER_PICKUP_REMINDER_WINDOW_MINUTES=15`

PISFA must configure approved airports, location boundaries, route/type rates,
capacities, durations, currencies, effective dates, and operating terms through
the protected administration UI. The legacy application's hardcoded fares and
service promises are demonstration data, not production defaults.

## Release evidence still required

F05 remains **In progress** until its two-connection MySQL/MariaDB assignment,
idempotency, rate-version, and cross-service races pass against the Hostinger-
compatible staging database; real mobile and accessibility journeys pass;
SMTP, database queue, retry/failure behavior, signed guest mail, and scheduler
recency are observed in staging; and stakeholders approve the live airport,
zone, rate, duration, contact-retention, cancellation, and service-wording
configuration.

F14 payment checkout/refunds, F16 actual loyalty awards, F18 preferences and
SMS/WhatsApp providers, F21 fleet maintenance, F22 driver operations, and F27
branded documents remain separate incomplete dependencies.
