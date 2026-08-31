# Flight Inquiries (F06)

## Scope

PISFA sells flights as a consulted service, not as a self-service booking
engine. F06 captures a domestic or international fare enquiry from a guest or a
signed-in customer, acknowledges it, and gives operations a searchable follow-up
console with assignment, guarded status, internal notes, and communication
history.

F06 deliberately does **not** include live fare search, seat inventory, seat
holds, ticket issuing, or payment. Every customer-facing surface states that the
enquiry does not reserve a seat and that no payment is collected. Adding a real
booking flow requires a GDS/airline integration and belongs to a later feature.

## Captured request

| Field | Rule |
|---|---|
| `scope` | `domestic` or `international` |
| `trip_type` | `one_way` or `return` |
| `travel_class` | `economy`, `premium_economy`, `business`, `first` |
| `origin`, `destination` | 2–120 characters; destination must differ from origin |
| `outbound_on` | inside `minimum_notice_days` … `maximum_advance_days` |
| `return_on` | required and after `outbound_on` for a return trip; forced to null for one way |
| `passenger_count` | 1 … `maximum_passengers` |
| `contact_name`, `contact_email`, `contact_phone` | required; overwritten from the account for a signed-in customer |
| `notes` | optional, ≤ 5000 characters |
| `acknowledge_enquiry` | must be accepted |

A signed-in customer never supplies their own contact identity: the create
action overwrites name, email, and phone from the account before validation, so
a tampered form cannot attach someone else's contact details to the enquiry.

## Duplicate safety

Guest submissions have no user id to key on, so `CreateFlightInquiry` stores a
keyed `idempotency_owner_hash` (HMAC over `guest|email|phone`, or
`customer|id`), the submitted `idempotency_key`, and a `request_fingerprint`
over the normalized payload. `(idempotency_owner_hash, idempotency_key)` is
unique. Replaying the same form returns the original enquiry; reusing the key
with different content is a validation error rather than a silent overwrite.

## Status workflow

```
new -> contacted -> booked -> closed
 |         |          |
 |         v          v
 +----> cancelled <---+

closed | cancelled -> new   (reopen, restricted)
```

`FlightInquiryStatus::allowedTransitions()` is the single source of truth and
every transition passes through `TransitionFlightInquiry` under a row lock.

- Closing or cancelling requires a reason, stored as `resolution_reason`.
- Reopening returns a resolved enquiry to `new`. It is allowed only for the
  roles in `flight_inquiries.reopen.roles` (manager and super administrator by
  default), is bounded by `flight_inquiries.reopen.maximum_per_inquiry`, and
  clears the resolution fields while incrementing `reopen_count`.
- The console hides the reopen option from staff, and
  `TransitionFlightInquiryRequest` re-checks the `reopen` policy server-side so
  a hand-crafted request is refused, not merely hidden.
- A resolved enquiry cannot be reassigned until it is reopened.

## Assignment and history

`AssignFlightInquiry` locks the actor, the proposed consultant, and the enquiry
before writing. The consultant must be an active staff, manager, or super
administrator both at validation time and again under the lock, so a suspended
account cannot inherit a queue during a race. Releasing an enquiry back to the
unassigned queue clears `assigned_at`.

`flight_inquiry_entries` is the append-only history. Types split into:

- **Manual** — internal note, email sent, phone call, WhatsApp, quote shared.
  Written by `RecordFlightInquiryEntry` from the console.
- **System** — status changed, assignment changed. Written only by the
  transition and assignment actions; the manual form rejects them.

Only communication entries reach the customer portal. Internal notes stay in the
operations console, and that boundary is covered by a test.

## Delivered surfaces

Routes live in `routes/flight-inquiries.php`, required from `routes/web.php`.

| Surface | Route name | Controller | Screen |
|---|---|---|---|
| Public enquiry form | `flight-inquiries.create` / `.create.scope` | `FlightInquiries\FlightInquiryController@create` | `flight-inquiries/create.blade.php` |
| Public submission | `flight-inquiries.store` | `FlightInquiries\FlightInquiryController@store` | redirects to portal or signed tracking |
| Guest tracking (`signed`) | `flight-inquiries.guest.show` | `FlightInquiries\FlightInquiryController@guest` | `flight-inquiries/guest.blade.php` |
| Customer history | `portal.flight-inquiries.index` | `FlightInquiries\FlightInquiryPortalController@index` | `flight-inquiries/index.blade.php` |
| Customer detail | `portal.flight-inquiries.show` | `FlightInquiries\FlightInquiryPortalController@show` | `flight-inquiries/show.blade.php` |
| Operations console | `admin.flight-inquiries.index` | `Admin\FlightInquiryController@index` | `admin/flight-inquiries/index.blade.php` |
| Detail, status, assignment, entries | `admin.flight-inquiries.{show,transition,assignment,entries.store}` | `Admin\FlightInquiryController` | `admin/flight-inquiries/show.blade.php` |

## Authorization

The form and submission are public and rate limited. Active, email-verified
customers see only their own enquiries, resolved through the
`customerFlightInquiry` route binding so a foreign reference is a 404 rather
than a 403 that confirms the record exists. Guest enquiries are unreachable from
the portal and reachable publicly only through a temporary signed link.
Operations screens require an active staff, manager, or super administrator
behind email verification, role middleware, and the mandatory two-factor policy.

## Notifications

- `FlightInquiryReceivedNotification` — acknowledgement to the traveller.
- `FlightInquiryStatusNotification` — follow-up on each status change; the
  console can suppress it per transition with `notify_traveller`.
- `FlightInquiryAssignedNotification` — handover notice to the consultant.

All extend `FlightInquiryMailNotification`: queued, dispatched after commit,
bounded retries with backoff, mail-only rate limiting through the
`flight-inquiry-notification-mail` limiter so database notifications are not
delayed by the SMTP allowance. Guests receive routed mail only; account holders
also get a database notification.

## Environment

- `PISFA_FLIGHT_INQUIRY_MAXIMUM_PASSENGERS=50`
- `PISFA_FLIGHT_INQUIRY_MINIMUM_NOTICE_DAYS=1`
- `PISFA_FLIGHT_INQUIRY_MAXIMUM_ADVANCE_DAYS=365`
- `PISFA_FLIGHT_INQUIRY_MAXIMUM_TRIP_LENGTH_DAYS=180`
- `PISFA_FLIGHT_INQUIRY_MAXIMUM_REOPENS=3`
- `PISFA_FLIGHT_INQUIRY_GUEST_TRACKING_EXPIRY_HOURS=168`
- `PISFA_FLIGHT_INQUIRY_MAIL_MAX_PER_MINUTE=8`

## Tests

`tests/Feature/FlightInquiries/` — 37 tests:

- `FlightInquirySubmissionTest` — public form, both scopes, guest signed
  tracking, signature expiry, account contact override, date-window and
  trip-type rules, acknowledgement, idempotency.
- `FlightInquiryWorkflowTest` — console filters, assignment and its history and
  notification, guarded transitions, reason requirements, manager-only bounded
  reopening, locked-consultant eligibility, manual-vs-system entry types,
  suppressible traveller email.
- `FlightInquiryAuthorizationTest` — route boundaries per role, verification,
  inactive accounts, cross-customer isolation, guest isolation, internal-note
  privacy, empty state, two-factor policy, reopen visibility.

## Release evidence still required

F06 remains **In progress** until the MySQL/MariaDB concurrency behaviour of the
idempotency unique key and the assignment lock is verified on Hostinger-
compatible staging; real browser and accessibility journeys pass; SMTP and
database-queue delivery, retry, and failure visibility are observed in staging;
and stakeholders approve the enquiry wording, planning window, and reopen policy.

F14 payments, F15 quotations (turning an enquiry into a priced quotation), F18
notification preferences and SMS/WhatsApp providers, and F25 reporting remain
separate incomplete dependencies.
