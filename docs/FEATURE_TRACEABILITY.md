# Feature Traceability (F01–F30)

Authoritative implementation ledger. Every feature identifier F01–F30 appears
exactly once, mapped to the twelve artifact classes required by the project
brief: migrations/tables, models, enums, policies/permissions, services/actions,
routes, controllers or Livewire components, screens, notifications/jobs,
external integrations, automated tests, and completion status.

## Status vocabulary

| Status | Meaning |
|---|---|
| **Not started** | No production code exists. Artifact rows are empty. |
| **Foundation only** | A general scaffold exists; the feature itself does not. |
| **In progress** | The vertical slice runs end to end through real UI, but the release evidence listed under the feature is not complete. |
| **Verified** | UI, backend, authorization, integrations, tests, failure states, and documentation demonstrated together. |

Only **Verified** means complete. A feature is not complete if any required UI,
backend, authorization, or test surface is missing. An artifact row that reads
"—" is a gap, not an omission from this document.

## Summary

| ID | Feature | Status |
|---|---|---|
| F01 | Public website and marketing | In progress |
| F02 | Accounts and security | In progress |
| F03 | Tours and safaris | In progress |
| F04 | Car hire | In progress |
| F05 | Airport transfers | In progress |
| F06 | Flight inquiries | In progress |
| F07 | Vehicle imports | In progress |
| F08 | Car sales and showroom | In progress |
| F09 | Accommodation and properties | In progress |
| F10 | Lease your car to PISFA | In progress |
| F11 | Corporate and group services | In progress |
| F12 | Blog and CMS | In progress |
| F13 | Customer portal | In progress |
| F14 | Payments | In progress |
| F15 | Quotations and invoices | In progress |
| F16 | Loyalty and referrals | In progress |
| F17 | Reviews | In progress |
| F18 | Notifications and communications | In progress |
| F19 | Live chat and WhatsApp bot | In progress |
| F20 | Admin dashboard and unified bookings | In progress |
| F21 | Fleet management | In progress |
| F22 | Driver operations | In progress |
| F23 | Customer CRM, staff, and RBAC | In progress |
| F24 | Expenses and payroll | In progress |
| F25 | Analytics, reports, and exports | In progress |
| F26 | Audit logs and settings | In progress |
| F27 | Uploads and generated documents | In progress |
| F28 | Scheduled automation | In progress |
| F29 | Deployment and operations | In progress |
| F30 | PWA, accessibility, and testing | In progress |

**0 of 30 features are Verified.** Twenty-two run end to end through real
interfaces; verification is blocked on the hosting pass (MySQL race evidence,
browser journeys, live provider credentials).

---

## F01 — Public website and marketing

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_04_000100_create_marketing_tables.php` → `newsletter_subscribers`, `contact_messages` |
| Models | `NewsletterSubscriber`, `ContactMessage` |
| Enums | — |
| Policies/permissions | — (admin management of subscribers and messages not built) |
| Services/actions | — (controllers write directly) |
| Routes | `home`, `about`, `contact`, `request-quotation`, `privacy`, `terms`, `contact.store`, `newsletter.store` |
| Controllers | `Marketing\PublicPageController`, `Marketing\ContactMessageController`, `Marketing\NewsletterSubscriptionController` |
| Screens | `resources/views/marketing/` (7 Blade files), `layouts/public.blade.php` |
| Notifications/jobs | — |
| Integrations | — (Google Analytics not configured) |
| Tests | `tests/Feature/MarketingPagesTest.php` |
| **Status** | **In progress** |

**Gaps:** multi-service search, featured tours/vehicles/properties, testimonials, company statistics, chat entry point, Open Graph metadata, sitemap, robots rules, Google Analytics, web manifest and branded icons, admin management of subscribers/messages. Featured journal posts and editable SEO landed with F12.

---

## F02 — Accounts and security

| Artifact | Evidence |
|---|---|
| Migrations/tables | `0001_01_01_000000_create_users_table.php`, `2026_08_04_000050_add_pisfa_profile_fields_to_users_table.php`, `2026_08_04_134042_add_two_factor_columns_to_users_table.php` |
| Models | `User` |
| Enums | `UserRole`, `AccountStatus` |
| Policies/permissions | `RoleMiddleware`, `EnsureAccountIsActive`, `EnsureTwoFactorAuthenticationIsConfigured`, `Services\StaffAccessGuard` |
| Services/actions | `Actions\Fortify\CreateNewUser`, `Providers\FortifyServiceProvider` |
| Routes | `routes/auth.php` — `register`, `password.request/email/reset/store/update`, `verification.notice/verify/send`, `profile.security` |
| Controllers | `Http/Controllers/Auth/*`, `ProfileController` |
| Screens | `resources/views/auth/` (7), `resources/views/profile/` (5) |
| Notifications/jobs | Fortify/Laravel mail notifications |
| Integrations | SMTP; TOTP via Fortify (`config/fortify.php`, `config/security.php`) |
| Tests | `tests/Feature/Auth/` (7 files), `RoleAuthorizationTest` |
| **Status** | **In progress** |

**Gaps:** preferred language and currency are stored and editable but no screen honours them yet (no translation, no currency default); configurable CORS; security headers middleware; documented session-revocation coverage after every security change.

---

## F03 — Tours and safaris

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_20_000100_create_tour_domain_tables.php` → `tour_categories`, `tour_packages`, `tour_package_media`, `tour_package_items`, `tour_itinerary_days`, `tour_departures`, `tour_bookings`, `tour_travelers`, `tour_assignments`, `tour_booking_events` |
| Models | `TourCategory`, `TourPackage`, `TourPackageMedia`, `TourPackageItem`, `TourItineraryDay`, `TourDeparture`, `TourBooking`, `TourTraveler`, `TourAssignment`, `TourBookingEvent` |
| Enums | `TourPackageStatus`, `TourPackageItemType`, `TourDepartureStatus`, `TourBookingStatus`, `TourBookingEventType`, `TourTravelerType` |
| Policies/permissions | `TourCategoryPolicy`, `TourPackagePolicy`, `TourDeparturePolicy`, `TourBookingPolicy` |
| Services/actions | `CreateTourBooking`, `CancelTourBooking`, `TransitionTourBooking`, `AssignTourDriver`, `SaveTourPackage`, `SaveTourDeparture` |
| Routes | `routes/tours.php` — public `tours.*`, customer `tour-bookings.*`/`portal.bookings.*`, admin `admin.tours.*`, `admin.tour-categories.*`, `admin.tour-departures.*`, `admin.tour-bookings.*` |
| Controllers | `Tours\TourCatalogueController`, `Tours\TourBookingController`, `Tours\BookingPortalController`, `Admin\TourPackageController`, `Admin\TourCategoryController`, `Admin\TourDepartureController`, `Admin\TourBookingController` |
| Screens | `views/tours/` (3), `views/tour-bookings/`, `views/admin/tours/`, `views/admin/tour-categories/`, `views/admin/tour-bookings/` |
| Notifications/jobs | `Notifications\Tours\*` (7); `SendTourDepartureReminders` command |
| Integrations | SMTP |
| Tests | `tests/Feature/Tours/` (13 files) |
| **Status** | **In progress** |

**Gaps:** loyalty award (blocked on F16), MySQL race evidence, browser/accessibility journeys.

Checkout is now live via F14: `TourBooking` implements `Payable`, so a customer
can pay through `payments.checkout` and settlement records a durable
`LoyaltyEligible` event exactly once, ready for F16.

---

## F04 — Car hire

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_20_000200_create_car_hire_domain_tables.php` → `vehicles`, `vehicle_media`, `vehicle_hire_rates`, `car_hire_bookings`, `car_hire_self_drive_applications`, `car_hire_documents`, `car_hire_contracts`, `car_hire_driver_assignments`, `car_hire_booking_events` |
| Models | `Vehicle`, `VehicleMedia`, `VehicleHireRate`, `CarHireBooking`, `CarHireSelfDriveApplication`, `CarHireDocument`, `CarHireContract`, `CarHireDriverAssignment`, `CarHireBookingEvent` |
| Enums | `HireMode`, `CarHireBookingStatus`, `CarHireBookingEventType`, `CarHireDocumentType`, `SelfDriveApplicationStatus`, `VehicleCatalogueStatus`, `VehicleOperationalStatus` |
| Policies/permissions | `VehiclePolicy`, `CarHireBookingPolicy`, `CarHireDocumentPolicy`, `CarHireContractPolicy` |
| Services/actions | `CreateCarHireBooking`, `CancelCarHireBooking`, `TransitionCarHireBooking`, `AssignCarHireDriver`, `SaveSelfDriveApplication`, `ReviewSelfDriveApplication`, `VerifySelfDriveOriginals`, `StoreCarHireDocument`, `DeleteCarHireDocument`, `AcceptCarHireContract`, `SaveVehicle`, `SaveVehicleRate` |
| Routes | `routes/car-hire.php` — `car-hire.*`, `car-hire-bookings.*`, `portal.car-hire-bookings.*` (incl. documents/contracts), `admin.vehicles.*`, `admin.car-hire-bookings.*` |
| Controllers | `CarHire\CarHireCatalogueController`, `CarHireBookingController`, `CarHirePortalController`, `SelfDriveApplicationController`, `CarHireDocumentController`, `CarHireContractController`, `Admin\VehicleController`, `Admin\CarHireBookingController` |
| Screens | `views/car-hire/` (3), `views/car-hire-bookings/` (5), `views/admin/vehicles/`, `views/admin/car-hire-bookings/` |
| Notifications/jobs | `Notifications\CarHire\*` (7); `ExpireCarHireBookings`, `SendCarHireReturnReminders` commands |
| Integrations | SMTP; private local storage for identity documents |
| Tests | `tests/Feature/CarHire/` (12 files) |
| **Status** | **In progress** |

**Gaps:** MySQL race evidence, browser/accessibility journeys.

Checkout is live via F14 (`CarHireBooking` implements `Payable`).

Branded PDF contracts are now delivered via F27
(`portal.car-hire-bookings.contracts.download`, `pdf/car-hire-contract.blade.php`,
`tests/Feature/CarHire/ContractPdfDownloadTest.php`).

---

## F05 — Airport transfers

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_27_000300_create_airport_transfer_domain_tables.php` → `airports`, `airport_transfer_locations`, `airport_transfer_rates`, `airport_transfer_bookings`, `airport_transfer_assignments`, `airport_transfer_events` |
| Models | `Airport`, `AirportTransferLocation`, `AirportTransferRate`, `AirportTransferBooking`, `AirportTransferAssignment`, `AirportTransferEvent` |
| Enums | `AirportTransferType`, `AirportTransferBookingStatus`, `AirportTransferEventType` |
| Policies/permissions | `AirportPolicy`, `AirportTransferLocationPolicy`, `AirportTransferRatePolicy`, `AirportTransferBookingPolicy` |
| Services/actions | `CreateAirportTransferBooking`, `QuoteAirportTransfer`, `TransitionAirportTransferBooking`, `CancelAirportTransferBooking`, `AssignAirportTransferResources`, `RescheduleAirportTransferBooking`, `SaveAirport`, `SaveAirportTransferLocation`, `SaveAirportTransferRate`, `ChangeAirportTransferRateStatus` |
| Routes | `routes/airport-transfers.php` — `airport-transfers.index`, `airport-transfer-bookings.store`, `airport-transfer-bookings.guest.show` (signed), `portal.airport-transfer-bookings.*`, `admin.airport-transfer-bookings.*`, `admin.airport-transfer-settings.*` (20 routes) |
| Controllers | `AirportTransfers\AirportTransferPlannerController`, `AirportTransfers\AirportTransferPortalController`, `Admin\AirportTransferBookingController`, `Admin\AirportTransferSettingController` |
| Screens | `views/airport-transfers/` (index, guest), `views/airport-transfer-bookings/` (index, show), `views/admin/airport-transfer-bookings/` (index, show), `views/admin/airport-transfer-settings/` (index) |
| Notifications/jobs | `Notifications\AirportTransfers\*` (6); `ExpireAirportTransferRequests`, `SendAirportTransferPickupReminders` commands |
| Integrations | SMTP |
| Tests | `tests/Feature/AirportTransfers/` — `AirportTransferPlannerHttpTest` (10), `AirportTransferRouteAuthorizationTest` (9), `AirportTransferAdministrationHttpTest` (10), `AirportTransferPortalAndLifecycleTest` (7) = 36 tests |
| **Status** | **In progress** |

**Gaps:** MySQL race evidence, browser/accessibility journeys, staging SMTP/queue/scheduler observation, approved live route and rate data. Checkout is live via F14 (`AirportTransferBooking` implements `Payable`). Detail in `docs/AIRPORT_TRANSFERS.md`.

---

## F06 — Flight inquiries

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_28_000400_create_flight_inquiry_domain_tables.php` → `flight_inquiries`, `flight_inquiry_entries` |
| Models | `FlightInquiry`, `FlightInquiryEntry` |
| Enums | `FlightInquiryScope`, `FlightInquiryStatus`, `FlightInquiryEntryType`, `FlightTripType`, `FlightTravelClass` |
| Policies/permissions | `FlightInquiryPolicy` (incl. narrower `reopen` ability driven by `config/flight_inquiries.php`) |
| Services/actions | `CreateFlightInquiry`, `TransitionFlightInquiry`, `AssignFlightInquiry`, `RecordFlightInquiryEntry` |
| Routes | `routes/flight-inquiries.php` — `flight-inquiries.create`/`.create.scope`/`.store`, `flight-inquiries.guest.show` (signed), `portal.flight-inquiries.*`, `admin.flight-inquiries.*` (11 routes) |
| Controllers | `FlightInquiries\FlightInquiryController`, `FlightInquiries\FlightInquiryPortalController`, `Admin\FlightInquiryController` |
| Screens | `views/flight-inquiries/` (create, guest, index, show), `views/admin/flight-inquiries/` (index, show) |
| Notifications/jobs | `FlightInquiryReceivedNotification`, `FlightInquiryStatusNotification`, `FlightInquiryAssignedNotification` |
| Integrations | SMTP. **No GDS/airline integration** — F06 is an enquiry desk, not a booking engine. |
| Tests | `tests/Feature/FlightInquiries/` — `FlightInquirySubmissionTest` (12), `FlightInquiryWorkflowTest` (12), `FlightInquiryAuthorizationTest` (13) = 37 tests |
| **Status** | **In progress** |

**Gaps:** MySQL concurrency evidence, browser/accessibility journeys, staging SMTP/queue observation, a direct "quote this enquiry" action (F15 quotations exist but are not yet raised from a flight enquiry). Detail in `docs/FLIGHT_INQUIRIES.md`.

---

## F07 — Vehicle imports

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_28_000700_create_vehicle_import_domain_tables.php` -> `vehicle_import_orders`, `vehicle_import_events`, `vehicle_import_messages` |
| Models | `VehicleImportOrder` (implements `Payable`), `VehicleImportEvent`, `VehicleImportMessage` |
| Enums | `VehicleImportStatus` (11-stage guarded lifecycle), `VehicleImportBodyType`, `VehicleImportFuelType`, `VehicleImportTransmission`, `VehicleImportDriveType`, `VehicleImportSteering`, `VehicleImportEventType` |
| Policies/permissions | `VehicleImportOrderPolicy` — owner-scoped view; quote/transition operations-only; customer cancellation limited to pre-deposit stages |
| Services/actions | `CreateVehicleImportOrder`, `QuoteVehicleImportOrder`, `TransitionVehicleImportOrder` |
| Routes | `routes/vehicle-imports.php` — `vehicle-imports.create/store`, `vehicle-imports.track` (unguessable token), `portal.vehicle-imports.*`, `admin.vehicle-imports.*` |
| Controllers | `VehicleImports\VehicleImportController`, `VehicleImports\VehicleImportPortalController`, `Admin\VehicleImportController` |
| Screens | `views/vehicle-imports/{create,track,index,show}.blade.php`, `partials/{progress,timeline}.blade.php`, `views/admin/vehicle-imports/{index,show}.blade.php` |
| Notifications/jobs | `VehicleImportReceivedNotification`, `VehicleImportQuotedNotification`, `VehicleImportStatusNotification` |
| Integrations | SMTP; F14 payments for deposit and balance; F27 documents for shipping paperwork |
| Tests | `tests/Feature/VehicleImports/` — `VehicleImportLifecycleTest` (14), `VehicleImportHttpTest` (18) = 32 tests |
| **Status** | **In progress** |

Delivered: the full 17-field specification intake, keyed idempotency for guest
and customer requests, a 64-hex-character CSPRNG tracking token (unique column,
never derived from the reference, format-checked before any query), a customer
timeline and progress bar, the guarded 11-stage lifecycle, quotation publishing
with revision locked once the deposit settles, and **two-stage payment**:
`outstandingAmountMinor()` returns the deposit first and the balance only once
the vehicle is `ready_for_delivery`.

Two lifecycle stages gate on money rather than on an operator's word:
`deposit_paid` requires a settled deposit, and `delivered` requires the balance
settled in full. Internal timeline events and internal notes never reach the
customer view.

**Gaps:** staff/customer message posting UI (the model, thread rendering, and
privacy boundary exist; the compose form does not), shipping-document upload UI
(F27 storage is wired, the admin upload control is not), consultant assignment
UI, import reports and revenue summaries, guest-to-account linking so a guest
can pay without staff intervention, MySQL race evidence, browser journeys.

---

## F08 — Car sales and showroom

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000500_create_sales_domain_tables.php` -> `vehicle_listings` (nullable `vehicle_id` to the F04 fleet, snapshotted specification, `asking_price_minor`, `sold_price_minor`, deferred `sold_to_enquiry_id` FK), `vehicle_sales_enquiries` (nullable `customer_id`, unique `(idempotency_owner_hash, idempotency_key)`) |
| Models | `VehicleListing` (soft deleted), `VehicleSalesEnquiry`; `Vehicle::listings()` |
| Enums | `ListingStatus` — Draft, Available, Reserved, Sold, Withdrawn; `SalesEnquiryStatus` — New, Contacted, Viewing, Negotiating, Won, Lost. Both with guarded transition graphs |
| Policies/permissions | `VehicleListingPolicy` (console access for staff; a sold or withdrawn listing is read-only; `sell` is manager-only), `VehicleSalesEnquiryPolicy` (staff, plus the customer who raised it), `Actions\Sales\SalesAccess` re-checking the locked user row inside every action |
| Services/actions | `SaveVehicleListing` (fleet interlocks, unique slug, money parsing), `TransitionVehicleListing` (list/reserve/release/restore/withdraw/sell), `SubmitSalesEnquiry` (guest-capable, idempotent), `TransitionSalesEnquiry` (pipeline, assignment, internal notes) |
| Routes | `routes/sales.php` — public `showroom.{index,show,enquire}`; admin `admin.showroom.{index,create,store,show,edit,update,publish,reserve,release,restore,withdraw,sell}` and `admin.showroom.enquiries.{index,show,advance,assign,note}` |
| Controllers | `Sales\ShowroomController`, `Admin\VehicleListingController`, `Admin\SalesEnquiryController`; `StoreSalesEnquiryRequest`, `SaveVehicleListingRequest` |
| Screens | `views/showroom/{index,show}` + `partials/card`, `views/admin/showroom/{index,create,edit,show}` + `partials/form`, `views/admin/showroom/enquiries/{index,show}`; "Cars for sale" in the public nav, mobile menu, and footer; "Showroom" in the staff console nav; "Sell this vehicle" on the fleet vehicle page |
| Notifications/jobs | `SalesEnquiryReceivedNotification` (queued, rate-limited via `sales-notification-mail`; mail only for a guest, mail + database for a customer) |
| Integrations | F04/F21 fleet vehicles and hire bookings, F27 documents for photographs (`DocumentCategory::VehicleMedia`), F26 audit log |
| Tests | `tests/Feature/Sales/ShowroomTest` (49 tests) |
| **Status** | **In progress** |

**A listing snapshots its specification instead of reading through the vehicle.**
A car sold in March and a fleet record edited in June are two different facts,
and a showroom that renders the current record would quietly rewrite what was
advertised. The snapshot also means stock that was never on hire is a complete
record on its own, so the showroom does not depend on the fleet existing.
`vehicle_id` is therefore nullable, and a test edits the vehicle after listing it
to prove the listing does not move.

**Selling a fleet vehicle retires it from hire in the same transaction.**
A showroom that marked a car sold while the hire site still took bookings for it
would be selling the same asset twice, so `sell()` sets the vehicle to
`Archived` + `Retired` under the same lock — unpublished so it leaves search, and
retired so it stops being assignable. The interlock runs the other way as well:
a vehicle with hire bookings that have not finished cannot be listed at all,
because advertising it would promise something PISFA cannot deliver.

**`Reserved` exists so a deposit does not have to claim a sale.** The money may
still fall through, and a listing that jumped straight to Sold would have to be
un-sold, which is not something an inventory record should ever do. Reserved
stays publicly visible, marked as reserved, and still takes enquiries in case the
deposit fails. `Sold` is terminal — its `allowedTransitions()` is empty — so a
mistaken sale is corrected by a new listing rather than by rewriting the record
of what was sold and when. A test asserts every status is reachable, including
the Withdrawn -> Draft return path.

**A sale is recorded against the listing, never against an enquiry.**
`TransitionSalesEnquiry` refuses to set `Won` and says why: closing a lead as won
on its own would let the same car be sold twice and would put a sale in the
pipeline that no revenue figure knows about. The manager-only `sell()` marks the
buyer's enquiry won, closes every other open enquiry on that vehicle as lost with
a reason, and refuses a buyer who enquired about a different listing.

Key guarantees: guests are first-class — `customer_id` stays null rather than
pointing at a placeholder account, and the HMAC-keyed idempotency owner hash is
keyed to the enquirer *and* the listing, so one person asking about two cars is
two enquiries while a refreshed form is one; a signed-in customer's identity comes
from their account, so the contact fields cannot be spoofed; `acceptsEnquiries()`
is re-checked under the lock, so a car sold a moment ago takes no further
enquiries; a sold listing stays in the showroom for a configurable window because
recent sales read as a going concern, and remains reachable by URL forever so an
old link is not a dead end; draft and withdrawn listings are unreachable even by
slug, and soft-deleted slugs are counted so a withdrawn URL is never reused;
internal notes and idempotency material are hidden from serialisation, and the
audit trail records that a note was added and how long it was, never its text.

**Gaps:** no photograph upload from the showroom console — media is attached
through F27 and the create form has no uploader yet; no test drive or viewing
scheduler; no part-exchange valuation; no finance or hire-purchase quotation; no
deposit taken through F14 payments, so a reservation is recorded but the money is
handled outside the system; no sold-stock reporting slice in F25; and no
comparison, saved searches, or price-drop alerts for buyers.

---

## F09 — Accommodation and properties

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000600_create_accommodation_domain_tables.php` -> `properties`, `property_room_types` (the contended `quantity`), `property_room_rates` (seasonal, per currency), `property_bookings` (unique `(customer_id, idempotency_key)`, availability index on `(room_type, status, check_in_date, check_out_date)`), `property_booking_events` (unique `(booking, event_type)`) |
| Models | `Property` (soft deleted, `HasReviews`), `PropertyRoomType` (availability arithmetic), `PropertyRoomRate`, `PropertyBooking` (`Payable` via `IsPayable`), `PropertyBookingEvent` |
| Enums | `PropertyType`; `PropertyStatus` — Draft, Published, Archived; `PropertyBookingStatus` — Pending, Confirmed, CheckedIn, CheckedOut, Cancelled, Declined, Expired, with `holdsInventory()`, a guarded transition graph, and a `stage()` mapping onto `BookingStage`; `PropertyBookingEventType` |
| Policies/permissions | `PropertyPolicy` (staff for the console, manager for publishing and pricing, archived is read-only), `PropertyBookingPolicy` (staff, plus the customer who booked), `Actions\Accommodation\AccommodationAccess` re-checking the locked user row inside every action |
| Services/actions | `SaveProperty`, `TransitionProperty` (publish/unpublish/archive/restore), `SaveRoomType`, `SaveRoomRate`, `CreatePropertyBooking`, `TransitionPropertyBooking` (confirm/decline/cancel/check-in/check-out) |
| Routes | `routes/accommodation.php` — public `accommodation.{index,show,availability}`, `accommodation.book`; portal `portal.property-bookings.{show,cancel}`; admin `admin.accommodation.{index,create,store,show,edit,update,publish,unpublish,archive,restore}`, `admin.accommodation.rooms.{store,update}`, `admin.accommodation.rates.{store,retire}`, `admin.accommodation.bookings.{index,show,confirm,decline,cancel,check-in,check-out}` |
| Controllers | `Accommodation\PropertyCatalogueController`, `Accommodation\PropertyBookingController`, `Admin\PropertyController`, `Admin\PropertyBookingController`; `StorePropertyBookingRequest`, `SavePropertyRequest` |
| Screens | `views/accommodation/{index,show}` + `partials/card`, `views/portal/stays/show`, `views/admin/accommodation/{index,create,edit,show}` + `partials/form`, `views/admin/accommodation/bookings/{index,show}`; "Stays" in the public nav, mobile menu, and footer, and in the staff console nav |
| Notifications/jobs | `PropertyBookingReceivedNotification`, `PropertyBookingUpdatedNotification`, `PropertyArrivalReminderNotification` (queued, rate-limited via `accommodation-notification-mail`); commands `accommodation:expire-pending-bookings` (every minute) and `accommodation:send-arrival-reminders` (daily at 07:00 Kampala) |
| Integrations | F14 payments (`Payable`, registry segment `stays`), F17 reviews (`HasReviews` on `Property`, `subjectFor()` and `isCompleted()` branches in `SubmitReview`), F20 unified bookings (`BookingSource::Accommodation`), F13 portal activity (through the same `BookingSource` loop), F26 audit log, F27 documents for photographs (`DocumentCategory::PropertyMedia`) |
| Tests | `tests/Feature/Accommodation/AccommodationTest` (62 tests) |
| **Status** | **In progress** |

**Availability is a count, not an overlap test — and that is the whole domain.**
A hire vehicle is one physical object, so any overlap is a conflict. A room type
is N interchangeable rooms, so the question is how many are already committed on
the *busiest night* of the requested stay. It is the minimum across nights rather
than a total on purpose: a guest needs the same room every night, so a type with
one room free on Monday and three on Tuesday can take exactly one booking, not
four. Tests cover the count, the busiest-night rule, and re-checking a booking
without counting it against itself.

**Nights are a half-open interval, so same-day turnover is not a conflict.**
Arriving on the 10th and leaving on the 12th occupies the nights of the 10th and
11th; the room is free again on the 12th, and the next guest can check in that
morning. Dates are stored as dates rather than timestamps for the same reason —
"the 12th" means the same thing to the guest and the desk, and storing a moment
would invite a timezone bug into what is a calendar question. The unified booking
list knows this too: `BookingSource::serviceDateIsCalendarDate()` stops it
filtering a date column with a UTC instant converted from a Kampala day.

**A season is a different price, not a discount on a base rate.** The whole stay
has to fall inside one rate window; a stay straddling two seasons has no single
nightly rate, and the booking form says so and points at a quotation rather than
silently billing whichever window sorted first. Two active rates in one currency
may not cover the same night, because then `rateFor()` would have to pick one and
a guest's price would depend on insert order. Retiring a rate deactivates it
rather than deleting it, so the `property_room_rate_id` on every booking priced
from it still explains a disputed charge a year later.

**Availability is re-checked under a lock at confirmation, not only at booking.**
A pending stay whose hold quietly lapsed may have had its rooms taken in the
meantime, and confirming it anyway would promise a room that is gone. The
availability query itself ignores an expired hold, so the inventory stays honest
whether or not the expiry sweep has run — the sweep exists to give the guest a
definite answer and keep the desk queue clean, not to protect the rooms.

Key guarantees: a property cannot be published without an active priced room,
because a page with a booking form that can never succeed is worse than no page;
publication needs a status *and* a date, so a scheduling mistake can only hide a
property; a published slug is frozen, since renaming would break every shared
link; archiving is refused while guests are still expected, because the page a
confirmed booking links to would disappear while PISFA still owed them a room;
lowering a room type's `quantity` below what is already committed on a future
night is refused; occupancy is checked across the rooms booked rather than per
room, so a family of five in two doubles is one booking; the hold is capped at
the arrival moment it is holding; a replayed idempotency key describing a
different stay is refused rather than silently returning the first booking; a
guest may cancel only inside the property's own free-cancellation window, and the
reason is shown rather than the button silently vanishing; and internal notes,
the idempotency key, and the request fingerprint are never serialised.

**Gaps:** no photograph upload from the accommodation console — media attaches
through F27 and the property form has no uploader; no amenity list or facility
filters; no map or coordinates; no per-room photographs; no deposit or partial
payment schedule, so a stay is paid in full or not at all; no rate import from a
property's own system; no allocation of a specific room number at check-in; no
group or block booking across several room types in one request; no channel
manager or external availability sync; and no automatic release of a stay whose
guest never arrived — a no-show is closed by hand at the desk.

---

## F10 — Lease your car to PISFA

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000700_create_vehicle_leasing_domain_tables.php` -> `vehicle_lease_applications` (nullable `owner_id`, unique `(idempotency_owner_hash, idempotency_key)`), `vehicle_leases` (nullable `vehicle_id`, deferred `application_id` FK, integer retainer and basis points), `vehicle_lease_payouts` (unique `(vehicle_lease_id, period_start)`) |
| Models | `VehicleLeaseApplication`, `VehicleLease`, `VehicleLeasePayout` |
| Enums | `LeaseApplicationStatus` — Submitted, UnderReview, InspectionArranged, Inspected, Approved, Declined, Withdrawn; `LeaseStatus` — Draft, Active, Suspended, Ended; `LeasePayoutModel` — FixedMonthly, RevenueShare; `LeasePayoutStatus` — Draft, Approved, Paid, Cancelled. All with guarded transition graphs |
| Policies/permissions | `VehicleLeaseApplicationPolicy`, `VehicleLeasePolicy`, `VehicleLeasePayoutPolicy`; `Actions\Leasing\LeasingAccess` splitting day-to-day desk work (staff) from what commits PISFA to paying somebody (manager), re-checked against the locked user row inside every action |
| Services/actions | `SubmitLeaseApplication` (guest-capable, idempotent), `TransitionLeaseApplication` (review/inspect/approve/decline/withdraw/assign), `SaveVehicleLease`, `TransitionVehicleLease` (activate/suspend/end, with the fleet interlock), `CalculateLeasePayout`, `TransitionLeasePayout` |
| Routes | `routes/leasing.php` — public `leasing.{create,store,show}`; portal `portal.leases.show`; admin `admin.leasing.applications.{index,show,review,inspection,findings,approve,decline,withdraw,assign}`, `admin.leasing.leases.{index,create,store,show,edit,update,activate,suspend,end}`, `admin.leasing.payouts.{calculate,deductions,approve,paid,reopen}` |
| Controllers | `Leasing\LeaseApplicationController`, `Admin\LeaseApplicationController`, `Admin\VehicleLeaseController`; `StoreLeaseApplicationRequest`, `SaveVehicleLeaseRequest` |
| Screens | `views/leasing/{create,show}`, `views/portal/leases/show`, `views/admin/leasing/applications/{index,show}`, `views/admin/leasing/leases/{index,create,edit,show}` + `partials/form`; "Lease to us" in the public nav, mobile menu, and footer, and "Leasing" in the staff console nav |
| Notifications/jobs | `LeaseApplicationReceivedNotification`, `LeaseApplicationUpdatedNotification`, `LeaseStatusChangedNotification`, `LeasePayoutReadyNotification` (queued, rate-limited via `leasing-notification-mail`; mail only for a guest, mail + database for an account) |
| Integrations | F04/F21 fleet (a lease creates and retires the vehicle), F03 car hire (payouts are computed from completed hires), F26 audit log, F27 documents for the signed agreement (`DocumentCategory::LeaseContract`) |
| Tests | `tests/Feature/Leasing/VehicleLeasingTest` (52 tests) |
| **Status** | **In progress** |

**A lease and the fleet are one fact, not two.** Activating an agreement creates
the fleet vehicle; suspending takes it off hire without ending the agreement;
ending retires *and* unpublishes it. A car that stayed bookable after its lease
ended would have PISFA hiring out something it no longer controls, and a lease
whose vehicle never joined the fleet would be money paid for an asset nobody
could use. The fleet record is created at activation rather than at application
time, so an offer PISFA never took up leaves no phantom vehicle behind, and it
arrives as a **draft** — operationally available so the desk can assign it, but
unpublished until it has photographs and a hire rate. Ending is refused while
hires are still to run, using the hire domain's own `holdingVehicle()` definition
so the two cannot disagree about what counts as unfinished.

**The security deposit is not revenue, and is never shared.** A revenue share is
taken from `rental_subtotal_minor`, never from the booking total: the deposit is
the customer's money held and returned, and paying an owner a slice of it would
be paying them out of funds PISFA holds on somebody else's behalf.

**Money is never summed across currencies.** A lease is denominated in one
currency. Hires in another are counted into `excluded_hire_count` and reported on
the statement as excluded — never converted at a rate nobody agreed, and never
silently dropped, because a missing hire looks identical to a quiet month.

**The share is integer basis points, and it rounds rather than truncates.** 25%
is 2500, the multiplication happens before the division, and half the divisor is
added first — `intdiv()` alone would shave a shilling off every month in the
owner's disfavour. A share above 10000 bps is refused: it would pay out more than
the vehicle earned.

**Approval is only reachable from Inspected.** PISFA takes on liability for a car
it puts on hire, so the transition graph makes approving a vehicle nobody has
looked at unreachable rather than merely discouraged, and the recorded findings
are the basis the terms are drawn from.

Key guarantees: guests are first-class — `owner_id` stays null and the offer is
readable by its reference, while an offer that *does* belong to an account is a
404 by reference alone; the idempotency hash is keyed to the applicant **and** the
registration plate, so one owner offering two cars files two offers; the
registration plate is hidden from serialisation and deliberately kept out of the
audit trail, which is read by more people than the offer is; terms are frozen
once a lease is active, because payouts have been computed against them and a
renegotiation is a new agreement; one approved offer backs exactly one lease; a
plate already in the fleet is refused rather than duplicated, so a car's history
is not split in two; the unique `(lease, period_start)` index means a repeated
payout run cannot pay an owner twice; an approved payout is never recalculated,
because the owner already has that statement; a deduction larger than the
earnings is refused, since a debt is not a negative payout; a deduction needs a
reason the owner can read; and marking a payout paid requires the transfer
reference, so a payout marked paid is distinguishable from one somebody forgot
to send.

**Gaps:** no outbound payment execution — PISFA sends the money by mobile money
or bank transfer and records the reference, so there is no provider integration
here; no signed-agreement generation or e-signature, only a document slot; no
owner-side upload of logbook or insurance; no automatic monthly payout run, so
statements are drawn up by hand from the console; no maintenance-cost recovery
flow feeding deductions automatically from F21; no proration when a lease starts
or ends mid-month; and no owner-facing earnings chart.

---

## F11 — Corporate and group services

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000900_create_corporate_domain_tables.php` -> `corporate_accounts` (soft deleted, integer credit limit and basis-point discount, **no balance column**), `corporate_members` (unique `(corporate_account_id, user_id)`), `group_bookings` (nullable `corporate_account_id`), `group_travelers`; adds `invoices.corporate_account_id` |
| Models | `CorporateAccount`, `CorporateMember`, `GroupBooking`, `GroupTraveler` |
| Enums | `CorporateAccountStatus` — Prospect, Active, Suspended, Closed; `CorporateMemberRole` — Traveller, Booker, Approver, Administrator, each carrying its own `canBook()`/`canApprove()`/`canManageMembers()`; `GroupBookingStatus` — Enquiry, Quoted, ManifestPending, Confirmed, InProgress, Completed, Cancelled, with a `stage()` mapping onto `BookingStage`. All with guarded transition graphs |
| Policies/permissions | `CorporateAccountPolicy`, `GroupBookingPolicy`; `Actions\Corporate\CorporateAccess` separating a customer's authority (a live membership row) from PISFA staff authority (their role), with terms sitting above both |
| Services/actions | `Services\Corporate\CorporateCreditQuery` (the live credit position), `SaveCorporateAccount`, `TransitionCorporateAccount`, `ManageCorporateMembers`, `SaveGroupBooking`, `ManageGroupManifest`, `TransitionGroupBooking` |
| Routes | `routes/corporate.php` — portal `portal.groups.{index,store,show,update,cancel}` and `portal.groups.travelers.{store,update,destroy}`; admin `admin.corporate.{index,create,store,show,edit,update,terms,activate,suspend,close,reopen}`, `admin.corporate.members.{store,destroy}`, `admin.corporate.groups.{index,show,price,quote,manifest,confirm,start,complete,cancel}` |
| Controllers | `Corporate\GroupBookingController`, `Admin\CorporateAccountController`, `Admin\GroupBookingController`; `SaveCorporateAccountRequest`, `SaveGroupBookingRequest` |
| Screens | `views/portal/corporate/{index,show}`, `views/admin/corporate/{index,create,edit,show}` + `partials/form`, `views/admin/corporate/groups/{index,show}`; "Corporate" in the staff console nav, "Groups" in the customer nav |
| Notifications/jobs | `GroupBookingUpdatedNotification` (queued, rate-limited via `corporate-notification-mail`) |
| Integrations | F15 billing (`invoices.corporate_account_id` is the link the credit check follows; `company_name` stays as the printed snapshot), F20 unified bookings (`BookingSource::Groups`), F13 portal activity, F26 audit log, `ServiceCatalogue` for the service a group is booking |
| Tests | `tests/Feature/Corporate/CorporateAndGroupsTest` (57 tests) |
| **Status** | **In progress** |

**There is no balance column, and that is the point.** A stored outstanding
figure drifts the moment an invoice is voided, a payment lands out of band, or
two requests update it at once — and a credit limit checked against a drifted
balance is worse than no limit, because it looks like a control while letting
the wrong thing through. `CorporateCreditQuery` computes the position from live
invoices every time, the same principle as the UNION-over-live-tables pattern
F20 and F13 use. Tests prove a draft invoice consumes nothing and that
cancelling one releases its credit with no update anywhere.

**Credit is per currency and never summed across.** An account is denominated in
one currency; invoices raised in another are counted and surfaced as excluded
rather than folded into a total that means nothing, and `canCarry()` refuses
outright in a foreign currency rather than converting at a rate nobody agreed.

**The credit check runs at confirmation, under a lock.** Not at enquiry, because
that is not when PISFA commits, and not from whatever screen was rendered when
the price was agreed, because the position may have moved since. The console
shows the refusal coming — available credit beside the group's price — so the
desk is not surprised by a button that fails.

**A group cannot be confirmed with an incomplete manifest.** A coach booked for
forty with thirty-seven names is three people with no seat, and it is discovered
on the morning of the trip. `ManifestPending` is a status of its own rather than
a flag, because that is the state most groups sit in longest and it is genuinely
different work. The list is capped at the headcount under the same lock, so two
people filling it in at once cannot both take the last seat, and the headcount
cannot be cut below the names already on it.

**Authority is a membership row with a role, not a flag on the user.** The same
person may be an administrator at one company and merely a traveller at another,
and revoking authority must not touch the bookings they already raised — so a
membership is deactivated, never deleted, and every check goes through
`canBook()`/`canApprove()` which return false for a deactivated row whatever the
role says. Re-adding somebody restores and re-roles the existing row rather than
hitting the unique index.

Key guarantees: the last active administrator cannot be removed, because an
account nobody can manage defeats the point of self-service; only an active
customer may be put on a company account, since PISFA staff on one would blur
whose side somebody is acting for; a credit limit cannot be cut below what is
already owed, and the billing currency cannot change while money is outstanding;
an account that owes money or has an unfinished trip cannot be closed; a closed
account reopens as a *prospect*, so terms are agreed again rather than an old
limit quietly returning; a group may be raised with no company at all, because a
school trip is a group without being a corporate account; travellers' identity
documents and dates of birth are hidden from serialisation and deliberately kept
out of the audit trail, which records the name only; and a customer may withdraw
an enquiry but not a confirmed trip, which is PISFA's commitment as much as
theirs.

**Gaps:** no self-service account signup — a company is opened by PISFA staff;
no automatic invoice generation from a confirmed group, so billing is raised by
hand through F15; no per-account price list or automatic application of the
agreed discount to a quotation; no statement or ageing report for an account; no
credit-hold notification when an account crosses its limit; no rooming list or
seat allocation on the manifest; no CSV import of a traveller list, which for a
two-hundred-person group is real work; and no framework-agreement or tender
tracking.

---

## F12 — Blog and CMS

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000400_create_content_domain_tables.php` -> `post_categories`, `posts`, `post_tag` (unique `(post_id, tag)`) |
| Models | `Post` (soft deleted), `PostCategory`, `PostTag` |
| Enums | `PostStatus` — Draft, Scheduled, Published, Archived, with a guarded transition graph |
| Policies/permissions | `PostPolicy` — console access for operations staff; an archived post is read-only; public visibility is decided by the published scope, not by the policy |
| Services/actions | `Actions\Content\SavePost` (slug derivation and freezing, tag normalisation, reading time), `Actions\Content\TransitionPost` (publish/schedule/unpublish/archive/release) |
| Routes | `routes/content.php` — public `blog.index`, `blog.show`; admin `admin.posts.{index,create,store,edit,update,publish,unpublish,archive}` |
| Controllers | `Content\BlogController`, `Admin\PostController`; `SavePostRequest` |
| Screens | `views/blog/{index,show}` + `partials/card`, `views/admin/posts/{index,create,edit}` + `partials/form`; journal links in the public nav and footer; featured posts on the home page |
| Notifications/jobs | Command `content:publish-scheduled`, every five minutes |
| Integrations | F27 documents for cover images (`DocumentCategory::BlogMedia`) |
| Tests | `tests/Feature/Content/BlogTest` (28 tests) |
| **Status** | **In progress** |

**`Scheduled` is a separate status, not a published post with a future date.**
That distinction is the whole safety property: the public scope requires the
status *and* a date that has passed, so a scheduling bug can only ever hide a
post, never leak a draft early. A late sweep delays an article; it cannot
publish one before its time. Tests cover a draft, a scheduled post, an archived
post, and one dated a single second in the future — none of them reachable.

**The slug is the public URL, and it is frozen once a post has been live.**
Changing it silently breaks every link anyone has shared and every search result
pointing at it, so renaming a live article's URL should be a deliberate act with
a redirect behind it, not a side effect of fixing a typo in the headline. A
draft that has never been public may still take a new slug, and the form
disables the field with the reason rather than accepting an edit it will discard.

Key guarantees: soft-deleted posts are counted when generating a slug, so a
removed article's URL is never silently reused for different content; post bodies
render **escaped**, because rendering staff-authored HTML unescaped would turn an
editor account into stored XSS against every reader; tags are normalised and
de-duplicated, so "Gorilla Trekking" and "gorilla trekking" are one tag, with a
unique index making a duplicate impossible; unpublishing keeps the original
publication date, since a post taken down and put back should not lose the day it
first ran; an archived post is read-only and returns through Draft, so it is
reviewed before it is public again; reading time is computed on save rather than
on render, so a listing of twenty posts does not count twenty bodies; and SEO
title and description are editable with a fallback to the post's own words.

This closes two items recorded against F01: **featured posts** on the home page
(drawn through the same public scope the journal uses, so the home page cannot
surface a post the blog would hide) and **editable SEO title and description**.

**Gaps:** no rich-text or Markdown editor — bodies are plain text with paragraph
breaks, which is deliberate given the escaping rule but limits formatting; no
cover-image upload screen (the relation and category exist, but nothing files
one yet); no admin CRUD for post categories, which must be seeded; no Open Graph
tags, sitemap, or robots rules (still open on F01); no author profile pages; no
comments; no revision history beyond the audit trail; MySQL race evidence,
browser journeys.

---

## F13 — Customer portal

| Artifact | Evidence |
|---|---|
| Migrations/tables | None by design — the portal aggregates the domain tables; the `notifications` table already existed and is now readable |
| Models | — (reads the existing domain models) |
| Enums | `Support\Portal\ActivityKind` (allowlist of what appears, and where each row links) |
| Policies/permissions | `role:customer` on every portal route; `ActivityFilterRequest::authorize()`; every query scoped by `customer_id` inside the query itself; notification lookups scoped through the notifiable relationship |
| Services/actions | `Services\Portal\CustomerActivityQuery` (UNION across bookings, quotations, invoices, payments), `Services\Portal\CustomerDocumentQuery`, reusing `Services\Dashboard\CustomerSnapshot` |
| Routes | `routes/portal.php` — `portal.index`, `portal.activity`, `portal.documents`, `portal.notifications.{index,read,read-all}`; `/dashboard` now hands customers to `portal.index` |
| Controllers | `Portal\PortalController`, `Portal\NotificationController`; `ActivityFilterRequest` |
| Screens | `views/portal/index` (attention, upcoming, amounts due, recent activity), `views/portal/activity` (everything, filterable), `views/portal/documents`, `views/portal/notifications`; unread badge in both nav variants |
| Notifications/jobs | — (the portal reads; it does not dispatch) |
| Integrations | — (reads F03–F07 bookings, F14 payments, F15 quotations and invoices, F16 loyalty, F18 notifications, F27 documents) |
| Tests | `tests/Feature/Portal/CustomerPortalTest` (21 tests) |
| **Status** | **In progress** |

**The notification inbox closes a genuine dead feature.** Every notification in
the system dispatches to `['mail', 'database']`, and until this screen existed
the database half went nowhere a person could read — a feature that existed only
in a table. `portal.notifications` renders it, with per-message and bulk
mark-as-read and an unread badge in the navigation.

The activity list is a **UNION over the live domain tables**, the customer mirror
of the admin unified-bookings screen and built the same way, so the portal cannot
drift from what the office sees.

Key guarantees: every projection filters by `customer_id` inside the query rather
than relying on a caller to remember, so there is no path through
`CustomerActivityQuery` that returns another customer's row — asserted by a test;
a **draft quotation or invoice never reaches the aggregate**, because an
aggregate list is still a customer screen; an unknown `kind` returns nothing
rather than everything, and is a validation error over HTTP; document ownership is
resolved per owner type rather than by scanning the polymorphic table, since
matching on `documentable_id` alone would hand a customer someone else's contract
the moment two tables shared an id; only **current** document versions are
listed, so a superseded contract is retained as evidence without being what a
customer reads; the download route still re-checks `DocumentPolicy`, so listing
grants nothing by itself; a notification is looked up through the notifiable
relationship, so one customer cannot open another's message (404, not 403); and
the inbox only follows a stored URL that this application generated, so it cannot
become an open redirect.

A payment row deliberately links nowhere — it is shown through the thing it paid
for, and a dead link would be worse than none.

**Gaps:** `preferred_language` and `preferred_currency` are stored and editable
on the profile form, but **no screen honours them yet** — the catalogue does not
default to a customer's currency and nothing is translated; no in-portal profile
photo or address book; no self-service data export or account-closure request; no
saved payment methods; the portal is responsive but not installable (F30); MySQL
race evidence, browser journeys.

---

## F14 — Payments

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_28_000600_create_payment_domain_tables.php` → `payments`, `payment_allocations`, `refunds`, `payment_webhook_events` |
| Models | `Payment`, `PaymentAllocation`, `Refund`, `PaymentWebhookEvent`; `Concerns\IsPayable` trait |
| Enums | `PaymentStatus` (9 cases, one vocabulary system-wide), `PaymentProvider` (8), `RefundStatus` (4) |
| Policies/permissions | `PaymentPolicy` — view/pay owner-scoped; `refund` restricted to manager and super administrator; `record` for operations |
| Services/actions | `CreatePaymentIntent`, `SettlePayment`, `ProcessPaymentWebhook`, `RefundPayment`; `ExchangeRateResolver`, `PaymentGatewayRegistry` |
| Contracts | `Contracts\Payments\PaymentGateway`, `Contracts\Payments\Payable`; value objects `InitiationResult`, `VerificationResult`, `WebhookEvent`, `RefundResult` |
| Routes | `routes/payments.php` — `payments.checkout`, `payments.checkout.store`, `payments.status`, `webhooks.payments` (CSRF-exempt, signature-gated) |
| Controllers | `Payments\CheckoutController`, `Payments\PaymentWebhookController`, `Admin\PaymentController` |
| Screens | `views/payments/checkout.blade.php`, `views/payments/status.blade.php`, `views/admin/payments/{index,show}.blade.php`, `components/pay-now.blade.php` |
| Notifications/jobs | — (payment notifications land with F18) |
| Integrations | `ManualGateway` (bank transfer, cash) live; `FakeGateway` for tests. **Remote adapters not yet written** — an enabled provider without an adapter fails loudly at resolution rather than accepting uncollectable money |
| Tests | `tests/Feature/Payments/` — `PaymentLifecycleTest` (15), `PaymentWebhookTest` (12), `RefundTest` (12), `CheckoutHttpTest` (15), `AdminPaymentConsoleTest` (14), `PortalPaymentEntryPointTest` (6) = 74 tests |
| **Status** | **In progress** |

Delivered: server-authoritative amounts read from the payable (never the
request), keyed idempotency on intents and refunds, in-flight duplicate
suppression, integer parts-per-million exchange rates stamped at creation so a
later rate change cannot rewrite historic revenue, signature-gated webhooks with
database-level `(provider, event_id)` replay rejection, amount/currency/provider
verification before settlement, allocation via a unique `(payment, target)` key,
partial and full refunds with locked headroom recomputation, and payload
redaction before storage.

Also delivered: the operations console (search, filters, reconciliation totals,
an unreconciled queue, manual receipt recording against evidence, refund UI),
and `<x-pay-now>` entry points on the tour, car-hire, and transfer portals with
four server-derived states and no fake-success path. `TourBooking`,
`CarHireBooking`, and `AirportTransferBooking` are all `Payable`.

**Gaps:** the six remote provider adapters (MTN MoMo, Airtel Money, Stripe,
PayPal, PesaPal, Flutterwave) and their real signature schemes; receipt PDFs;
intent expiry scheduling; payment notifications. Sandbox credentials required
for staging. Detail in `docs/PAYMENTS.md`.

---

## F15 — Quotations and invoices

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000100_create_billing_domain_tables.php` -> `number_sequences`, `quotation_requests`, `quotations`, `quotation_items`, `invoices` (unique `quotation_id`), `invoice_items` |
| Models | `QuotationRequest`, `Quotation`, `QuotationItem`, `Invoice` (implements `Payable`), `InvoiceItem`, `NumberSequence`; `Concerns\HasBillingTotals` |
| Enums | `QuotationRequestStatus`, `QuotationStatus`, `InvoiceStatus` — each with a guarded transition graph |
| Policies/permissions | `QuotationRequestPolicy`, `QuotationPolicy`, `InvoicePolicy`; `Actions\Billing\BillingAccess` splits manage (staff+) from write-off (manager+) |
| Services/actions | `SubmitQuotationRequest`, `SaveQuotation`, `TransitionQuotation` (send/revise/cancel/respond/expire), `ConvertQuotationToInvoice`, `TransitionInvoice` (issue/cancel/void), `Services\Billing\DocumentNumberGenerator`, `Support\Billing\LineTotals` |
| Routes | `routes/billing.php` — public `quotation-requests.store`, guest `quotation-requests.track`/`quotations.track`(+respond)/`invoices.track`, `portal.quotation-requests.*`/`portal.quotations.*`/`portal.invoices.*`, `admin.quotation-requests.*`/`admin.quotations.*`/`admin.invoices.*` |
| Controllers | `Billing\QuotationRequestController`, `Billing\QuotationController`, `Billing\InvoiceController`, `Admin\QuotationRequestController`, `Admin\QuotationController`, `Admin\InvoiceController`; 4 Form Requests |
| Screens | real `marketing/request-quotation` form; `views/billing/**` (portal + guest tracking for requests, quotations, invoices); `views/admin/{quotation-requests,quotations,invoices}/**`; `components/billing-lines`, `components/billing-status`; PDF templates `pdf/quotation`, `pdf/invoice` |
| Notifications/jobs | `QuotationRequestReceivedNotification`, `QuotationSentNotification`, `QuotationRespondedNotification`, `InvoiceIssuedNotification`; command `quotations:expire` (daily, Kampala) |
| Integrations | SMTP; DomPDF via F27 `PdfRenderer` into `DocumentCategory::Quotation`/`Invoice`; F14 checkout through `PayableRegistry` segment `invoices` |
| Tests | `tests/Feature/Billing/` — `QuotationLifecycleTest` (28), `InvoiceLifecycleTest` (20), `BillingHttpTest` (22) = 70 tests |
| **Status** | **In progress** |

Every figure is an integer: money in minor units, tax in basis points. `LineTotals`
is the single arithmetic, and it applies tax **once** to the discounted subtotal
rather than per line — rounding each line separately drifts by a unit per line
against the figure a customer gets adding the document up by hand.

Numbers come from a locked `number_sequences` counter inside the issuing
transaction, so the series is contiguous per year (`INV-2026-00001`) and a rolled
-back write does not burn a number. An auto-increment id or a random string would
not survive an audit.

Key guarantees: a quotation is **not payable** — money is only ever collected
against the invoice an accepted quotation produces, which is what lets an offer
expire or be declined without touching a receivable; pricing is editable only in
Draft, and revising a sent offer bumps the revision rather than silently
rewriting numbers the customer already holds; an expired offer cannot be accepted
even before the sweep relabels it; `invoices.quotation_id` is unique, so a double
conversion is impossible by construction; invoice lines are **copied**, so
revising a quotation can never restate an issued or paid invoice; "overdue" is
derived from the due date and the live balance rather than stored, so it cannot
disagree with the money received; `PartiallyPaid` and `Paid` are reached only by
`applySettledPayment` inside the F14 settlement transaction; a paid invoice
cannot be cancelled, only voided, and voiding is restricted to managers and
above with a mandatory reason; a draft never resolves in the portal even for the
customer it names; internal notes are hidden from serialisation and rendered on
no customer screen or PDF; and a guest holding a tracking token cannot settle a
quotation that belongs to an account.

**Gaps:** no overdue-reminder command (a repeat cadence needs a different marker
design from the one-shot pattern used elsewhere); no credit notes — a void
records the correction but does not raise a reversing document; no partial
refunds against an invoice beyond what F14 already offers; quotations cannot yet
be raised directly from a tour, hire, or transfer booking; MySQL race evidence,
browser journeys.

---

## F16 — Loyalty and referrals

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_28_000800_create_loyalty_domain_tables.php` -> `loyalty_accounts`, `loyalty_transactions`, `referrals` |
| Models | `LoyaltyAccount`, `LoyaltyTransaction`, `Referral`; `User::loyaltyAccount()`, `User::referralReceived()` |
| Enums | `LoyaltyTier` (thresholds + discounts), `LoyaltyTransactionType`, `ReferralStatus` |
| Policies/permissions | Redemption restricted to active customers in `RedeemLoyaltyPointsRequest` and re-checked in the action |
| Services/actions | `RecordLoyaltyMovement` (single ledger writer), `AwardLoyaltyForEvent`, `RedeemLoyaltyPoints`, `RegisterReferral` |
| Routes | `routes/loyalty.php` — `portal.loyalty.index`, `portal.loyalty.redeem` |
| Controllers | `Loyalty\LoyaltyController`; referral capture in `Auth\RegisteredUserController` |
| Screens | `views/loyalty/index.blade.php` (balance, tier progress, tier table, redemption, referral code/link, ledger); referral field on the registration form |
| Notifications/jobs | `LoyaltyPointsExpiringNotification`, `LoyaltyPointsExpiredNotification`; commands `loyalty:process-awards` (every 5 min) and `loyalty:expire-points` (monthly) |
| Integrations | SMTP; consumes F14 settlement markers from F03/F04/F05/F07 |
| Tests | `tests/Feature/Loyalty/` — `LoyaltyPointsTest` (24), `LoyaltyAwardAndReferralTest` (16) = 40 tests |
| **Status** | **In progress** |

All brief-specified values are enforced and covered by boundary tests: tiers at
1,000/5,000/20,000 lifetime points, discounts at 0/5/10/15%, a 500-point
minimum redemption, and UGX 100 per point. Points are earned across **all four**
paying domains, not tours only.

Key guarantees: tier derives from **lifetime** points so redeeming or expiring
never demotes a customer; the unique `(account, idempotency_key)` index makes
double-awarding impossible; awards are keyed on the domain's `LoyaltyEligible`
marker row, so re-running the command is a no-op; the referrer is paid only
after the referred customer's first settled booking; self-referral and repeat
referral earn nothing; an unknown code is ignored rather than blocking
registration; and expiry writes one movement per period with a warning first.

**Gaps:** the tier discount is computed and displayed but **not yet applied at
checkout** (needs a discount hook in `CreatePaymentIntent`); no staff-facing
loyalty adjustment UI; redemption credits the ledger but there is no
account-credit balance a customer can spend at checkout yet; MySQL race
evidence; browser journeys.

---

## F17 — Reviews

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_28_000900_create_review_domain_tables.php` -> `reviews` (unique `(booking_type, booking_id)`), `review_moderation_events`, `review_summaries` |
| Models | `Review`, `ReviewModerationEvent`, `ReviewSummary`; `Concerns\HasReviews` on `TourPackage`; `TourBooking::review()` |
| Enums | `ReviewStatus` (transition graph, `isPublic()`, `countsTowardsSummary()`), `ReviewModerationAction` |
| Policies/permissions | `ReviewPolicy` — published reviews public, everything else author + moderators only; `moderate`/`reply` require administration access |
| Services/actions | `SubmitReview` (eligibility proved from the booking), `ModerateReview` (guarded transitions, replies), `RecalculateReviewSummary` (full recount) |
| Routes | `routes/reviews.php` — `portal.reviews.*` (index/create/store/edit/update/destroy), `admin.reviews.*` (index/show/moderate/reply) |
| Controllers | `Reviews\ReviewController`, `Admin\ReviewController`; requests `StoreReviewRequest`, `ModerateReviewRequest`, `ReplyToReviewRequest` |
| Screens | `views/reviews/{index,create,edit}`, `views/admin/reviews/{index,show}`, `components/star-rating`, ratings + published reviews on `views/tours/show` |
| Notifications/jobs | `ReviewRequestNotification`; command `reviews:send-requests` (hourly, two-pass with a unique marker) |
| Integrations | SMTP; F27 documents for review media (`DocumentCategory::ReviewMedia`) |
| Tests | `tests/Feature/Reviews/` — `ReviewModerationTest` (16), `ReviewHttpTest` (19), `SendReviewRequestsTest` (8) = 43 tests |
| **Status** | **In progress** |

Averages are stored as an integer **sum and count**, never a float, so a mean is
exact and recomputable. `RecalculateReviewSummary` does a **full recount** on
every status change rather than an increment: an increment has to be perfectly
paired with every transition, and one miss corrupts the average permanently.

Key guarantees: nothing a customer writes is public without a moderator —
submissions always land `Pending`, and an edit to a published review returns it
to `Pending` rather than rewriting it in place; only `Published` reviews are
visible publicly or counted in an average; a rejection cannot be recorded
without a reason the author can act on; the unique `(booking_type, booking_id)`
index makes a second review for the same booking impossible; a foreign review or
booking gives **404, not 403**, through the `customerReview` scoped binding;
public pages show a first name and surname initial only, never a full surname;
moderation notes are hidden from serialisation and never rendered publicly; and
`reviews:send-requests` asks once per booking, honouring both a delay and a
maximum age so a first deployment against historic data cannot mail every past
customer.

**Gaps:** only `TourBooking` is reviewable so far — car hire, transfers, and
accommodation subjects need `HasReviews` plus a `subjectFor()` branch once their
review requests are wired; review photo upload through F27 is modelled
(`Review::media()`) but has no upload screen; no public "all reviews" page with
pagination beyond the ten shown on a tour; MySQL race evidence; browser
journeys.

---

## F18 — Notifications and communications

| Artifact | Evidence |
|---|---|
| Migrations/tables | `notifications` (framework table); no preferences or delivery-attempt tables |
| Models | — |
| Enums | — |
| Policies/permissions | — |
| Services/actions | Per-domain notification dispatch inside F03/F04/F05/F06 actions |
| Routes | `portal.notifications.{index,read,read-all}` (delivered with F13) |
| Controllers | `Portal\NotificationController` |
| Screens | `views/portal/notifications`, plus the unread badge in both navigation variants |
| Notifications/jobs | 40+ queued notification classes across Tours, CarHire, AirportTransfers, FlightInquiries, VehicleImports, Loyalty, Reviews, Billing, and Fleet; all `ShouldQueue`, `afterCommit()`, bounded `$tries`/`$backoff`, mail-only `RateLimited` middleware |
| Integrations | SMTP only. Africa's Talking SMS, Meta WhatsApp, hosted broadcasting: not implemented |
| Tests | `TourNotificationContractTest`, `CarHireNotificationContractTest` |
| **Status** | **In progress** |

The `database` channel is no longer write-only: F13 added the inbox that reads
it, with unread counts and mark-as-read. Until then every dispatch wrote a row
nobody could see — a feature that existed only in a table.

**Gaps:** per-event and per-channel preferences and their enforcement, delivery
tracking, SMS and WhatsApp provider adapters, failure visibility, authorized
retry, and a staff-facing inbox (the current one is customer-only).

---

## F19 — Live chat and WhatsApp bot

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_001000_create_messaging_domain_tables.php` -> `conversations` (unique `(idempotency_owner_hash, idempotency_key)`, phone/status index), `conversation_messages` (unique `provider_message_id`, unique `(conversation_id, idempotency_key)`), `messaging_webhook_events` (unique `(provider, event_id)`) |
| Models | `Conversation` (phone normalised on write, `needingReply()`, `waitingMinutes()`, `isReachable()`), `ConversationMessage` (`visibleToCustomer()`, `after()`, `applyDeliveryReport()`), `MessagingWebhookEvent` |
| Enums | `ConversationStatus` (transition graph, `acceptsMessages()`), `ConversationChannel` (`requiresPhoneNumber()`, `usesProvider()`), `MessageAuthorType` (`canTriggerAutoReply()`), `MessageDeliveryStatus` (**`rank()` + `advanceTo()`**) |
| Policies/permissions | `ConversationPolicy` — inbox is staff-only; a signed-in customer may read their own thread; a guest's claim is the reference their **session** opened, resolved in `ChatController` |
| Services/actions | `StartConversation`, `PostMessage` (the single path every message takes), `TransitionConversation`, `HandleInboundWebhook`, `MessagingAccess`; `AutoReplyResolver` (three loop guards), `MessagingTransportRegistry`, `LogTransport`, `WhatsAppCloudTransport` |
| Routes | `routes/messaging.php` — `chat.{start,messages,send}` (guest-capable, throttled), `webhooks.whatsapp.{verify,receive}` (CSRF-exempt, signature-only), `admin.inbox.{index,show,reply,assign,resolve,reopen,close}` |
| Controllers | `Messaging\ChatController`, `Messaging\WhatsAppWebhookController`, `Admin\InboxController`; requests `StartChatRequest`, `PostChatMessageRequest` |
| Screens | `components/chat-widget` (mounted in `layouts/public`), `views/admin/inbox/{index,show}`, inbox link in `layouts/navigation` (both breakpoints) |
| Notifications/jobs | `CustomerMessageReceivedNotification` (queued, assignee only, rate-limited via `messaging-notification-mail`) |
| Integrations | Meta WhatsApp Cloud API behind `MessagingTransport`; credentials from env only (`WHATSAPP_ACCESS_TOKEN`, `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_APP_SECRET`, `WHATSAPP_VERIFY_TOKEN`) |
| Tests | `tests/Feature/Messaging/` — `LiveChatTest` (43), `WhatsAppWebhookTest` (24) = 67 tests; `tests/Support/FakeMessagingTransport` |
| **Status** | **In progress** |

**Delivery state advances by rank and never moves backwards.** A provider's
callbacks arrive out of order — a `read` can land before the `delivered` that
logically precedes it — so `MessageDeliveryStatus::advanceTo()` compares ranks
instead of assigning. Without it a late callback would drag a message the
customer demonstrably read back to merely delivered, and the console would tell
staff a customer had not seen a message they had already replied to. A `failed`
report arriving after delivery is likewise not believed: the provider has
already told us it arrived, which is the fact that matters.

**The bot cannot run away.** `AutoReplyResolver` clears three separate guards,
each for a different failure: only a message whose author is the customer can
trigger a reply, so the bot can never answer itself and two such systems pointed
at each other cannot talk until somebody reads the bill; a cooldown per
conversation means somebody typing five short messages gets one answer; and once
a member of staff has written in the thread the bot falls silent for good,
because a canned answer landing after a real person took over is worse than
silence. The replies themselves are a fixed, reviewable list in
`config/messaging.php` — not generated text — because an auto-reply is read as
PISFA speaking and costs money per message on WhatsApp.

Key guarantees: guests are first class throughout — `customer_id` stays null
rather than pointing at a placeholder account; an inbound WhatsApp message
**never** claims a customer account on a phone-number match, because a number is
not proof of identity; the webhook verifies its signature with `hash_equals`
before the payload is read, and a rejected delivery writes **no** event row so
an unauthenticated caller cannot fill a table; a replayed delivery is refused by
the unique `(provider, event_id)` index rather than by an application check two
concurrent requests could both pass; the subscription handshake compares its
token in constant time; internal notes are staff-only on every surface; a guest
polling a reference their session did not open gets **404, not 403**; a provider
outage marks the reply failed and leaves it in the thread rather than losing it;
and the event table keeps a **digest** of the payload, not the message text,
which already lives in `conversation_messages` under its own retention.

**Hosting constraint, stated honestly:** Hostinger Premium runs no persistent
Node process and no self-hosted WebSocket server, so the widget **polls** at
`config('messaging.chat.poll_seconds')` rather than holding a socket open. A
reply reaches the customer within roughly one interval. Polling runs only while
the panel is open, so a tab left open all day does not make a request every few
seconds in the background.

**Deployments without credentials degrade visibly, not silently.** The registry
falls back to `LogTransport`, which records that a message was *not* sent rather
than pretending it was, and the console tells staff plainly that WhatsApp is not
configured. `LogTransport::verifySignature()` returns false unconditionally, so a
deployment that never configured WhatsApp cannot be fed messages by anybody who
finds the webhook URL. The whole suite runs on `FakeMessagingTransport` and no
test needs a real credential.

---

## F20 — Admin dashboard and unified bookings

| Artifact | Evidence |
|---|---|
| Migrations/tables | — (no new tables by design; the domain tables stay the only source of truth) |
| Models | — (reads the existing domain models) |
| Enums | `BookingStage` (shared vocabulary), `Support\Bookings\BookingSource` (domain allowlist + column projection); `stage()` and `valuesInStage()` added to `TourBookingStatus`, `CarHireBookingStatus`, `AirportTransferBookingStatus`, `VehicleImportStatus` |
| Policies/permissions | `role:staff,manager,super_admin` + `2fa.required` on the console; `UnifiedBookingFilterRequest::authorize()` re-checks `canAccessAdministration()`; `CustomerSnapshot` scopes every query by `customer_id` |
| Services/actions | `Services\Bookings\UnifiedBookingQuery` (UNION projection, filters, stage counts), `Services\Dashboard\OperationsSnapshot`, `Services\Dashboard\CustomerSnapshot` |
| Routes | `routes/operations.php` — `admin.dashboard`, `admin.bookings.index`; `/dashboard` now dispatches by role |
| Controllers | `DashboardController` (role router), `Admin\DashboardController`, `Admin\UnifiedBookingController`; `UnifiedBookingFilterRequest` |
| Screens | `views/admin/dashboard` (queues, today, money, per-service queues), `views/admin/bookings/index` (cross-domain list with stage chips and service/date/sort filters), `views/dashboard/customer`, `views/dashboard/pending` |
| Notifications/jobs | — (the dashboard reads; it does not dispatch) |
| Integrations | — (reads F03/F04/F05/F07 bookings, F14 payments, F15 invoices, F16 loyalty, F17 reviews) |
| Tests | `tests/Feature/Operations/` — `UnifiedBookingTest` (19), `DashboardTest` (15) = 34 tests |
| **Status** | **In progress** |

The unified list is a **UNION over the live domain tables**, not a materialised
index. An index would be faster, but it can drift, and a status change that
forgets to update it makes the console quietly wrong — for an operations screen
that is worse than a slower query.

`BookingStage` is the shared vocabulary the cross-domain screen groups by, and
each domain's own status enum owns its mapping, because which of its states
counts as "in progress" is the domain's knowledge. A test asserts every status in
every domain maps to exactly one stage, so none can silently vanish from the list.

Key guarantees: sort, source, and stage are validated against enums before they
reach the query — an unvalidated sort column would be an injection point in the
union's `ORDER BY`, and a test passes a `drop table` string and checks the table
survives; an unknown source or stage returns **nothing** rather than everything;
date filters are read as Kampala calendar days and converted to the UTC instants
actually stored, with tests on both sides of a midnight boundary; money is
reported per currency and never summed across UGX and USD; revenue is stated in
the base currency stamped on each payment, so a later rate change cannot move a
historic figure; vehicle imports are excluded from "today" because they run for
months and have no service date; an unpriced import still appears with a zero
amount rather than being dropped, since an unpriced enquiry is exactly the kind
of row that needs attention; and `CustomerSnapshot` scopes every query by
`customer_id`, with a test that one customer's figures never include another's.

Drivers get an honest "not built yet" page rather than an empty console, because
F22 does not exist.

**Gaps:** no date-range picker on the dashboard itself (it reports today and a
fixed 30-day revenue window); no bulk
actions from the list; no per-staff workload view; the list cannot yet include
F15 invoices as rows in their own right; MySQL race evidence, browser journeys.

---

## F21 — Fleet management

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000200_create_fleet_domain_tables.php` -> `vehicle_maintenance_records`, `vehicle_fuel_logs`; `vehicles.current_odometer_km` + `odometer_updated_at`; `documents.expires_at` + `expiry_alert_sent_at`. Registry tables from F04 |
| Models | `VehicleMaintenanceRecord`, `VehicleFuelLog`; `Vehicle` gains `maintenanceRecords`, `fuelLogs`, `documents`, `openMaintenance` |
| Enums | `MaintenanceType` (with `recurs()`), `MaintenanceStatus` (guarded graph, `countsTowardsCost()`) |
| Policies/permissions | `VehiclePolicy::manageFleet()` and `::retire()`; `Actions\Fleet\FleetAccess` splits recording (staff+) from retiring (manager+) |
| Services/actions | `RecordOdometerReading` (the single monotonicity guard), `SaveMaintenanceRecord`, `CompleteMaintenanceRecord` (start/complete/cancel), `RecordFuelLog`, `Services\Fleet\FleetReport` (costs, consumption, utilisation, compliance) |
| Routes | `routes/fleet.php` — `admin.fleet.index`, `admin.fleet.show`, `admin.fleet.maintenance.{store,update,start,complete,cancel}`, `admin.fleet.fuel.store` |
| Controllers | `Admin\FleetController`, `Admin\VehicleMaintenanceController`, `Admin\VehicleFuelController`; requests `SaveMaintenanceRequest`, `CompleteMaintenanceRequest`, `RecordFuelLogRequest` |
| Screens | `views/admin/fleet/index` (off-road, service due, expiring paperwork, per-vehicle utilisation), `views/admin/fleet/show` (odometer, jobs, fuel log, costs, consumption, schedule and refuel forms) |
| Notifications/jobs | `FleetAlertNotification` (one digest, not one email per vehicle); command `fleet:send-alerts` (daily 06:30 Kampala) |
| Integrations | SMTP; F27 documents for insurance and registration; F04 bookings supply utilisation |
| Tests | `tests/Feature/Fleet/` — `FleetMaintenanceTest` (19), `FleetReportingTest` (20) = 39 tests |
| **Status** | **In progress** |

**The odometer is the invariant everything rests on.** Maintenance completions
and fuel logs both carry a reading, and both go through one guard against the
locked vehicle row. Without a single place enforcing it, a fill entered out of
order would quietly move the fleet's mileage backwards and every consumption and
service-due figure derived from it would be wrong. Equal readings are allowed —
two records the same day at the same mileage are ordinary — but a decrease is
refused, and so is a jump beyond 20,000 km, which catches a typed extra digit
before it permanently corrupts the record.

Volume is stored in whole millilitres for the same reason money is stored in
minor units: no float ever participates in a stored quantity. Litres and price
per litre are derived at display time.

Key guarantees: a scheduled job carries no cost or reading, because it has
neither yet and inventing them would put fiction in the cost report; only
completed work counts towards cost; a closed record cannot be edited, since it is
the evidence behind the service history; starting work takes the vehicle off
hire, and completing it gives the vehicle back **only if no other open job is
still holding it** — an unconditional release would put a vehicle back on hire
while it was still in the workshop; a one-off repair proposes no next-due point
even when an operator supplies one, because a repair does not recur and the entry
would be noise in the alert queue; a next-service reading at or below the current
one is refused so the record is not born overdue; service is due by date **or**
odometer, whichever a workshop would actually go by; consumption is measured only
between two full tanks, since a partial fill leaves an unknown amount already in
the tank; utilisation is derived from the bookings that hold a vehicle rather
than a second trip store that could drift, and is capped at 100% because a
booking can overhang the window; and costs are grouped by currency and never
summed across UGX and USD.

Alerts are one digest per sweep, and both maintenance and documents carry a
"already warned" marker. Without the document marker, expired insurance would
produce an identical email every morning until renewal — which is how an alert
stops being read. A still-unrenewed document is raised again after a repeat
window (7 days by default), because expired cover does deserve nagging.

**Gaps:** no per-trip logs with odometer out/in — that belongs with F22 driver
operations and would double-record what bookings already know; no document upload
screen on the fleet console (documents are filed through the F27 system, but the
expiry date has no form yet); no tyre or part inventory; no cost-per-kilometre or
cross-vehicle comparison report (F25); no vehicle retirement flow despite the
policy ability existing; MySQL race evidence, browser journeys.

---

## F22 — Driver operations

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000300_create_driver_domain_tables.php` -> `driver_profiles`, `driver_trips` (unique `(assignment_type, assignment_id)`), `vehicle_inspections` (unique `(driver_trip_id, phase)`) |
| Models | `DriverProfile`, `DriverTrip`, `VehicleInspection`; `User::driverProfile()` |
| Enums | `DriverTripStatus` (guarded graph), `InspectionPhase`; `Support\Drivers\AssignmentSource` (domain allowlist), `Support\Fleet\InspectionChecklist` (fixed item set with critical flags) |
| Policies/permissions | `Actions\Drivers\DriverAccess` — active driver role, ownership re-proved against the locked assignment, withdrawn assignments refused |
| Services/actions | `StartDriverTrip` (+`prepare`), `CompleteDriverTrip` (complete/abandon), `RecordVehicleInspection`, `Services\Drivers\DriverAssignmentQuery` |
| Routes | `routes/drivers.php` — `drivers.index`, `drivers.history`, `drivers.jobs.show`, `drivers.jobs.start`, `drivers.jobs.inspection`, `drivers.trips.complete`, `drivers.trips.abandon` |
| Controllers | `Drivers\DriverPortalController`; requests `StartDriverTripRequest`, `CloseDriverTripRequest`, `RecordInspectionRequest` |
| Screens | `views/drivers/index` (today, upcoming, licence warnings, on-the-road banner), `views/drivers/show` (job detail, both checks, start/close/abandon), `views/drivers/history`; `/dashboard` now redirects drivers here |
| Notifications/jobs | Driver licences join `FleetAlertNotification` and the `fleet:send-alerts` sweep |
| Integrations | F03/F04/F05 assignments supply the work; F21 receives the odometer and the defect-raised repairs; F27 unaffected |
| Tests | `tests/Feature/Drivers/DriverOperationsTest` (29 tests) plus licence coverage in `FleetReportingTest` |
| **Status** | **In progress** |

**The vehicle check gates the trip, and that ordering is the whole point.** A trip
cannot start without a recorded, passed pre-trip check, and cannot close without
a post-trip one. A check that can be skipped, or done afterwards, authorises
nothing. Critical items left *unchecked* count as failures, because "I did not
look" is not the same as "it is fine".

A reported defect does not merely sit in a text field: it raises a scheduled
repair in the F21 fleet queue, linked back to the inspection that caused it, and
a critical failure takes the vehicle off hire immediately. A check nobody acted
on would be paperwork, not safety.

Trip odometer readings go through **the same `RecordOdometerReading` guard the
fleet console uses**, so a driver's mileage lands in the one place the whole
system reads from and cannot disagree with a fuel log or a service record.

Key guarantees: the schedule unions the three assignment tables and is scoped by
`driver_user_id` on every query, with no unscoped path a wrong filter could turn
into another driver's day; a withdrawn assignment disappears and cannot be acted
on even by someone holding the link; a foreign job is a **404, not a 403**; one
trip per assignment and one check per phase, both enforced by unique indexes
rather than application checks that could race; a closing reading below the
departure reading is refused; an abandoned job records **no distance**, so a
journey that never happened cannot appear in utilisation figures as if it had; a
tour run in a vehicle outside the hire fleet skips odometer capture rather than
inventing a reading; licence numbers are encrypted at rest, masked on screen, and
hidden from serialisation; and an expired licence disqualifies a driver
regardless of the availability toggle — no office switch should put an unlicensed
driver on the road.

**Gaps:** no driver-facing profile screen (the office records licence details,
but there is no self-service view or upload); no admin screen for managing driver
profiles and availability; no GPS or live location; no driver mobile push (the
portal is responsive but not a PWA — that is F30); no per-driver performance or
earnings report (F25); no photo capture against a reported defect; MySQL race
evidence, browser journeys.

---

## F23 — Customer CRM, staff, and RBAC

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_04_140000_add_staff_provisioning_fields.php`; `staff_invitations` |
| Models | `User`, `StaffInvitation` |
| Enums | `UserRole`, `AccountStatus`, `StaffRoles` |
| Policies/permissions | `Services\StaffUserPolicy` (registered as the `User` policy), `StaffAccessGuard`, `RoleMiddleware` |
| Services/actions | `StaffInvitationService`, `IssuedStaffInvitation`, `StaffInvitationNotification` |
| Routes | `admin.staff.index/create/store/update/resend`, `staff-invitations.show/accept` |
| Controllers | `Http/Controllers/Admin` staff controller, invitation controller |
| Screens | `views/admin/` staff directory and invitation screens |
| Notifications/jobs | Staff invitation mail |
| Integrations | SMTP |
| Tests | `tests/Feature/StaffProvisioningTest.php`, `RoleAuthorizationTest.php` |
| **Status** | **In progress** |

**Gaps:** the entire customer CRM half (search, profile, booking/payment
aggregates, import and loyalty history, authorized customer communication),
driver-account creation and driver-profile onboarding, granular permissions
beyond the five roles, and the complete server-side permission matrix tests.

---

## F24 — Expenses and payroll

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_29_000800_create_finance_domain_tables.php` -> `expenses` (nullable `vehicle_id`, deferred `recovered_on_payout_id` FK), `payroll_runs` (unique `(period_start, currency)`), `payroll_lines` (unique `(payroll_run_id, user_id)`), `payroll_deductions` (basis kept beside amount) |
| Models | `Expense`, `PayrollRun`, `PayrollLine`, `PayrollDeduction` |
| Enums | `ExpenseCategory` (with `attachesToVehicle()` and `isRecoverableFromOwner()`), `ExpenseStatus` — Draft, Submitted, Approved, Reimbursed, Rejected; `PayrollRunStatus` — Draft, Approved, Paid, Cancelled; `PayrollDeductionType` — Nssf, Paye, LocalServiceTax, SalaryAdvance, Other, carrying its own `order()` and `isComputed()` |
| Policies/permissions | `ExpensePolicy`, `PayrollRunPolicy`, `PayrollLinePolicy`; `Actions\Finance\FinanceAccess` with three tiers — claim (anyone on the payroll), review and approve (staff/manager), and payroll (super-administrator only) |
| Services/actions | `SaveExpense`, `TransitionExpense`, `RecoverExpensesOnPayout`, `BuildPayrollRun`, `TransitionPayrollRun`; `Support\Payroll\TaxSchedule` holding the statutory arithmetic |
| Routes | `routes/finance.php` — portal `portal.expenses.{index,store,update,submit}` and `portal.payslips.{index,show}`; admin `admin.expenses.{index,show,approve,reject,return,reimburse}`; `admin.payroll.{index,store,show,lines.save,lines.remove,approve,reopen,paid,cancel}` behind a `role:super_admin` route group as well as the policy. Plus `admin.leasing.payouts.recover` in `routes/leasing.php` |
| Controllers | `Finance\ExpenseController`, `Finance\PayslipController`, `Admin\ExpenseReviewController`, `Admin\PayrollController`; `SaveExpenseRequest` |
| Screens | `views/portal/expenses/index`, `views/portal/pay/{index,show}`, `views/admin/finance/expenses/{index,show}`, `views/admin/finance/payroll/{index,show}`, `views/pdf/payslip`; "Expenses" and (super-administrator only) "Payroll" in the console nav, "My expenses" and "My payslips" in the driver nav |
| Notifications/jobs | `ExpenseDecisionNotification`, `PayslipAvailableNotification` (queued, rate-limited via `finance-notification-mail`) |
| Integrations | F10 leasing (approved vehicle spending is charged to an owner's statement, and released again if the statement is reopened), F21 fleet (`vehicle_id` on an expense), F27 documents — this feature is what finally uses `DocumentCategory::ExpenseReceipt` and `DocumentCategory::Payslip`, both of which were declared but unused, F26 audit log |
| Tests | `tests/Feature/Finance/ExpensesTest` (34 tests), `tests/Feature/Finance/PayrollTest` (30 tests) |
| **Status** | **In progress** |

**PAYE is charged after NSSF, not on the full gross — and that ordering is the
substance of the feature.** Uganda's NSSF employee contribution comes off gross,
and income tax is charged on what is left. Computing them the other way round
overstates the tax every single month, in the employer's favour, which is exactly
the kind of error nobody notices until an audit. The order lives on
`PayrollDeductionType::order()` rather than in whoever writes the calculation
next, and a test asserts the resulting PAYE is genuinely lower than PAYE on the
full gross.

**The employer's NSSF contribution is never deducted from the employee.** It is
10% the business pays on top, recorded as employer cost and shown on the payslip
as exactly that. Putting it in the deductions column would be wrong by a factor
of three, so a test asserts it appears in `employer_nssf_minor` and in no
deduction row.

**Statutory deductions are arithmetic, not a text box.** `PayrollDeductionType::isComputed()`
marks NSSF and PAYE, and the action refuses to accept either as a typed figure —
letting somebody enter a PAYE amount would put the tax authority's rules in a
form field. Advances and other arrangements are typed in, and they are the only
things that can be. All of it is integer basis points with the division last and
half the divisor added first; `intdiv()` alone would shave real money off in one
direction only.

**PAYE bands are written in one currency and are not applied to another.** The
bands are Ugandan shilling thresholds; charging them against a dollar salary
would be nonsense, so `TaxSchedule::paye()` throws rather than guessing, and a
run is denominated in a single currency — staff paid in a second currency get a
second run, because money is never summed across currencies. The expense console
shows monthly spend per currency for the same reason.

**Nobody approves their own claim.** A second pair of eyes is the entire control
against expense fraud, so the action refuses it explicitly and the console
explains why rather than hiding the button. Approving is also separate from
reimbursing: they are days apart in practice, and somebody who is out of pocket
needs to see which of the two has actually happened.

**Approved vehicle spending is charged to a lease owner's statement, once.** This
closes the gap recorded against F10: a repair recorded as an expense becomes a
line the owner can read, rather than a number somebody reads off one screen and
types into another. Each expense carries the payout it was recovered on, so the
same repair cannot be deducted in two different months, and reopening a statement
releases them again — left marked, they would be attached to a figure that no
longer exists and could never be charged to anybody. Fuel is deliberately *not*
recoverable: PISFA earns the hire income, so PISFA buys the fuel.

Key guarantees: payroll is super-administrator only in the route group as well as
the policy, because a run discloses what every colleague earns; an employee sees
their own payslip and only once the run is approved, never a draft and never a
colleague's; the audit trail records run totals and never individual salaries;
recomputing a line replaces its deductions wholesale rather than merging, so a
stale row cannot deduct something twice; deductions can never take somebody below
zero, because a shortfall is a debt to settle rather than a negative payslip; an
empty run cannot be approved; approving twice does not file a second payslip or
re-notify, guarded by a flag rather than by the status the early return would
already have changed; marking anything paid requires the transfer reference; and
an approved claim or run stops being editable, because the figures have been
signed off and somebody has been told.

**Gaps:** no outbound payment execution — PISFA sends the money and records the
reference; no receipt upload from the claims page itself (receipts attach through
F27); no mileage or per-diem rate cards; no budget or approval limits by amount;
no local service tax schedule, so it is a typed deduction rather than a computed
one; no employee contract records, leave, or NSSF/PAYE statutory return exports;
no bank or mobile-money bulk payment file; and no expense analytics slice in F25.

---

## F25 — Analytics, reports, and exports

| Artifact | Evidence |
|---|---|
| Migrations/tables | — (no new tables by design; every figure is computed from the domain tables) |
| Models | — (reads the existing domain models) |
| Enums | `Support\Export\ExportDataset` (dataset allowlist with per-role availability) |
| Policies/permissions | `ExportDataset::isAvailableTo()` splits operational (staff+) from financial (manager+); `ReportPeriodRequest::authorize()`; revenue hidden from staff on the report page itself |
| Services/actions | `Services\Reports\RevenueReport`, `Services\Reports\OperationsReport`, `Services\Reports\CsvExporter`; `Support\Reports\ReportPeriod`, `Support\Export\CsvCell`, `Support\Export\StreamedCsv` |
| Routes | `routes/reports.php` — `admin.reports.index`, `admin.reports.export` (throttled) |
| Controllers | `Admin\ReportController`; `ReportPeriodRequest` |
| Screens | `views/admin/reports/index` — period picker, revenue with period-on-period change, per-currency and per-provider and per-service breakdowns, a daily trend row, refunds, booking volume by stage, conversion funnel, fleet figures, and the export cards |
| Notifications/jobs | — (reporting reads; it does not dispatch) |
| Integrations | — (reads F03/F04/F05/F07 bookings, F14 payments and refunds, F15 quotations and invoices, F16 loyalty, F17 reviews, F21/F22 fleet and trips) |
| Tests | `tests/Feature/Reports/ReportsAndExportsTest` (28 tests) |
| **Status** | **In progress** |

**CSV injection is treated as a security property, not a formatting detail.** A
cell whose text begins with `=`, `+`, `-`, `@`, a tab, or a carriage return is
executed as a formula by Excel, LibreOffice, and Google Sheets — so a customer
stored as `=cmd|'/c calc'!A1` would run on the machine of whoever opened the
export. `CsvCell` neutralises every trigger once, centrally, so no exporter can
forget it, and strips control characters that could break a row apart. There are
tests for each payload shape and one that drives a malicious name all the way
through a real download.

Exports are **streamed row by row**, never assembled in memory: Hostinger
Premium is shared hosting, and a year of bookings held as one string is how an
export becomes a 500 for the person who needed it most. Every file carries a
byte-order mark so Excel on Windows reads UTF-8 rather than mangling names.

Key guarantees: the dataset comes from an enum allowlist, because a table name
from a query string would export any row in the database including password
hashes; financial datasets require manager and above, with staff shown the
operational half of the report page rather than a blocked page; payment exports
carry no provider reference, webhook payload, or idempotency material, and
customer exports select explicit columns so no credential can travel with them —
both asserted by tests; **every export is written to the audit log**, because
taking a copy of customer or payment data out of the system is a data-access
event, not a page view; money is written as a decimal string beside its own
currency column, since one "amount" column mixing UGX and USD would be summed by
whoever opened it and the answer would be wrong; revenue totals use the
base-currency amount stamped on each payment at settlement, so a later rate
change cannot move a past figure; refunds are reported separately rather than
netted off, because a month with heavy refunds against last month's takings is a
fact worth seeing; `ReportPeriod` reads dates as Kampala calendar days and caps a
window at 731 days, so one URL cannot take the site down; and a rate with no
denominator reports null rather than a division by zero dressed up as a
percentage.

This closes the "no CSV export" gap recorded against F01, F15, and F20.

**Gaps:** no scheduled report emails (a weekly management summary would need its
own marker design); no PDF reports — CSV only; no saved report presets; no
per-staff or per-driver performance breakdown; no cohort or retention analysis;
no Google Analytics integration (still open on F01); the daily trend row is a
CSS bar chart rather than a real chart library, which is deliberate on shared
hosting but limits what it can show; MySQL race evidence, browser journeys.

---

## F26 — Audit logs and settings

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_04_000100_create_audit_logs_table.php` -> `audit_logs`; `2026_08_04_000200_create_settings_table.php` -> `settings` |
| Models | `AuditLog`, `Setting` |
| Enums | — (`Support\Settings\SettingDefinition` is the registry) |
| Policies/permissions | `AuditLogPolicy` and `SettingPolicy` — super administrator only, with no create, update, or delete ability on the trail; `UpdateSettings` re-checks against the locked user row |
| Services/actions | `Services\AuditLogger` (key-pattern and URL redaction), `Services\Settings\SettingsRepository` (stored value wins, config falls back, memoised), `Actions\Settings\UpdateSettings` |
| Routes | `routes/governance.php` — `admin.audit.{index,show}`, `admin.settings.{edit,update}`, behind `role:super_admin` + `2fa.required` |
| Controllers | `Admin\AuditLogController`, `Admin\SettingsController`; `AuditLogFilterRequest` |
| Screens | `views/admin/audit/{index,show}` (filter by area, actor, and date; full before/after payloads), `views/admin/settings/index` (grouped, typed, with the credentials notice) |
| Notifications/jobs | — |
| Integrations | — (`PdfRenderer` and the PDF layout now read branding from settings) |
| Tests | `tests/Feature/Governance/AuditAndSettingsTest` (23), plus the existing `AuditLoggerTest` and `SettingTest` |
| **Status** | **In progress** |

**No credential can be stored here.** The settings registry is a deliberately
small allowlist — company details and two operational toggles. Provider keys,
webhook secrets, mail passwords, and the application key stay in environment
variables, where they are not readable through a web form, not in a table a
backup copies, and not on a screen somebody can screenshot. A settings page that
could hold an API key would defeat the rule that put it in the environment. A
test asserts no registered key even *looks* like a credential, and another proves
an unregistered key submitted with the form is discarded rather than stored.

**`Setting` was written by nothing and read by nothing** before this feature —
the second backend-only surface found in this pass, after the notification
inbox. `SettingsRepository` now backs `PdfRenderer`, whose docblock had claimed
"branding pulled from settings so a rename does not require a redeploy" while
actually reading config. The claim is now true, and the PDF footer reads the same
brand array as its header so the two cannot disagree.

Key guarantees: every settings change is audited with its before and after value,
and an unchanged field produces no entry, so a no-op save does not fill the trail
with noise; an unchecked checkbox stores false rather than being read as "leave
alone", because an unchecked box posts nothing at all; the trail is **read-only by
construction** — there is no route that writes, edits, or deletes an entry, and a
test drives every other HTTP verb at the detail URL to prove it; redacted values
stay redacted in the viewer, with a test that a password and an API key never
reach the rendered page; an entry with no actor says "System or guest" rather than
attributing a scheduled command to somebody; and reading the trail is a
super-administrator power, because a manager appearing in it should not be the
person who decides what it says about them.

**Gaps:** no retention or archival policy for `audit_logs` (the table grows
without bound); no export of the trail (F25 exports business data, not
governance); no diff view — before and after are shown as JSON rather than
field-by-field; no settings for tax rate or booking windows, which remain
environment variables; no per-setting history beyond what the audit trail holds;
MySQL race evidence, browser journeys.

---

## F27 — Uploads and generated documents

| Artifact | Evidence |
|---|---|
| Migrations/tables | `2026_08_28_000500_create_document_tables.php` → `documents` (polymorphic, versioned, soft-deleted). Legacy per-domain: `car_hire_documents`, `vehicle_media`, `tour_package_media` |
| Models | `Document` |
| Enums | `DocumentCategory` (18 cases, decides visibility/versioning/image requirement), `DocumentVisibility` |
| Policies/permissions | `DocumentPolicy` — inherits the owning record's policy; generated documents deletable by operations only |
| Services/actions | `Services\Documents\FileInspector`, `DocumentStorage`, `PdfRenderer`; `Actions\Documents\StoreDocument`, `DeleteDocument` |
| Routes | `routes/documents.php` — `documents.show` (auth + policy), `documents.signed` (signed, private-only); `portal.car-hire-bookings.contracts.download` |
| Controllers | `Documents\DocumentDownloadController`, `CarHire\CarHireContractController@download` |
| Screens | `resources/views/pdf/layout.blade.php`, `pdf/car-hire-contract.blade.php`; PDF download control on the customer contract screen |
| Notifications/jobs | — |
| Integrations | DomPDF (`barryvdh/laravel-dompdf` — pure PHP, no binary, chosen for Hostinger shared hosting). Local private/public disks; S3 by config; **Cloudinary not wired** |
| Tests | `tests/Feature/Documents/` — `FileInspectorTest` (11), `DocumentLifecycleTest` (10), `DocumentDownloadAuthorizationTest` (10), `PdfGenerationTest` (6); `tests/Feature/CarHire/ContractPdfDownloadTest` (7) = 44 tests |
| **Status** | **In progress** |

Content-sniffing upload validation, generated filenames, category-forced
visibility, versioning vs replacement, atomic failure handling, authorized and
signed downloads, and branded PDF generation are all live and tested — including
adversarial cases (PHP renamed `.jpg`, JPEG/PHP polyglot, extension/content
mismatch, decompression-bomb dimensions). The rental-contract PDF is wired into
the customer portal, closing the standing F04 gap.

**Gaps:** Cloudinary adapter; migrating the legacy `car_hire_documents` path
onto this system so there is one path rather than two; the remaining PDF
generators (lease contracts F10, quotations/invoices F15, payslips F24, payment
receipts F14, statements); virus scanning. Detail in
`docs/UPLOADS_AND_DOCUMENTS.md`.

---

## F28 — Scheduled automation

| Artifact | Evidence |
|---|---|
| Migrations/tables | `jobs`, `failed_jobs`, `job_batches`; per-domain event tables act as sent/processed markers |
| Services/actions | Commands: `SendTourDepartureReminders`, `ExpireCarHireBookings`, `SendCarHireReturnReminders`, `ExpireAirportTransferRequests`, `SendAirportTransferPickupReminders` |
| Routes | `routes/console.php` schedule definitions with overlap prevention |
| Notifications/jobs | Queued notifications with bounded retries and backoff |
| Tests | `tests/Feature/Tours/DepartureReminderCommandTest.php`, `tests/Feature/CarHire/CarHireCommandTest.php` |
| **Status** | **In progress** |

**Gaps:** document-expiry alerts, property reminders, review requests, daily
analytics aggregation, weekly management emails, loyalty expiry, abandoned
unpaid-booking reminders, scheduled blog publishing, queue/failed-job cleanup,
failure UI, and administrator-triggered fallback actions.

---

## F29 — Deployment and operations

| Artifact | Evidence |
|---|---|
| Services | `Operations\HealthCheck` (9 checks), `HealthStatus` (`worst()`, `isDown()`), `HealthCheckResult`, `Operations\DatabaseDump` (PDO only), `Operations\BackupRunner` |
| Logging | `Logging\RedactSensitiveValues` (Monolog processor), `Logging\ConfigureLogging` (tap); wired into every writing channel in `config/logging.php` |
| Commands | `pisfa:backup` (`--no-media`, `--no-prune`), `pisfa:heartbeat`; both scheduled in `routes/console.php` |
| Routes | `/health` public (up/down only, throttled), `admin.operations.index`, `admin.operations.failed-jobs.{retry,forget}` |
| Controllers | `Operations\HealthController` (public), `Admin\OperationsHealthController` (detail, failed jobs, backup history) |
| Policies/permissions | `StaffUserPolicy::viewOperations()` — super administrators only, on top of the `role:super_admin` route gate |
| Screens | `views/admin/operations/index` — health report, failed jobs with retry/discard, backup history; nav link in `layouts/navigation` (both breakpoints) |
| Configuration | `config/operations.php` — health thresholds, backup disk/path/retention; `.env.example` documents every key |
| Integrations | Any configured filesystem disk as an off-site backup target (`PISFA_BACKUP_DISK`) |
| Tests | `tests/Feature/Operations/HealthAndBackupTest.php` — 31 tests covering the endpoint, each check, the console, backups, retention, and log redaction |
| Documentation | `docs/HOSTINGER_DEPLOYMENT.md`, `docs/OPERATIONS.md`, `docs/BACKUP_AND_RESTORE.md`, `docs/RELEASE_CHECKLIST.md` |
| **Status** | **In progress** |

**The public health endpoint says only up or down.** No check names, no detail,
no timings. An endpoint that reports `database: connection refused to
db1.example.com:3306` hands an attacker the topology, and one that merely lists
subsystems tells them what to aim at. The detailed report lives behind
super-administrator authentication in the console. Failure is **503**, not 500,
because a monitor and a load balancer both read 503 as "not ready".

**Every check proves something by doing it.** The storage checks write a file and
read it back rather than confirming a disk is configured — a disk that is
configured but not writable behaves exactly like a working one until somebody
uploads a passport scan. No check may throw: one broken subsystem must not take
down the report somebody is reading to find out which subsystem is broken, so
each is wrapped and any throwable becomes a failure with a message this codebase
wrote. The driver's own message is discarded, because it names the host, port,
and user.

**Three states, not two.** `Warning` exists because most operational problems
are not binary: a queue backlog, disk pressure, and failed jobs all want somebody
to look today without taking the site down. Only `Failing` makes `/health` report
down. A *missing* scheduler heartbeat is a warning (a new deployment has none
yet); a *stale* one is a failure (reminders have stopped).

**The dump shells out to nothing.** `DatabaseDump` reads rows through PDO rather
than calling `mysqldump`, because Hostinger and most shared hosts disable
`exec()` and `proc_open()`, and where they do not the binary is often absent from
PATH for the PHP user. A backup that silently fails to run is worse than none,
because it is believed. A test asserts the class contains no process call, with
comments stripped first so the class may still explain why. The trade-off is
honest: slower than `mysqldump`, with rows streamed in 500-row chunks and
written straight out so peak memory stays flat.

**`.env` is never in a backup.** It holds `APP_KEY`, and an archive containing
both the encrypted data and the key that decrypts it is a single file that gives
away everything. A test asserts the key does not appear in the dump. Retention
prunes by count and age but **never deletes the newest backup**, so a
misconfigured age limit or a clock that jumped cannot leave the deployment with
nothing to restore from. The console and the command both say plainly that a
backup on the local disk survives a bad migration, not a lost server.

**Redaction is a Monolog processor, not a rule call sites remember.** Asking
every `Log::info()` to know what is sensitive holds until the first person in a
hurry, and a logged token is a token that must be rotated. Matching is by key
name and by substring, so `stripe_secret_key`, `WHATSAPP_ACCESS_TOKEN` and
`Api.Key` are all caught without maintaining an exhaustive list; `signature` is
included because a webhook signature is a valid credential for replaying that
request. Recursion is bounded at eight levels, because a stack overflow inside
the logger would take down the request that was trying to report a problem.
Operational context — booking references, phone numbers — is deliberately kept:
an operator has to be able to trace a booking, and those already sit in a
database the same person can read.

**Remaining gaps:** an automated restore drill (the procedure is documented and
manual), and off-site backup verified against a real remote disk — both belong
to the hosting pass.

---

## F30 — PWA, accessibility, and testing

| Artifact | Evidence |
|---|---|
| Tests | 1217 passing, 2 skipped, 4184 assertions |
| Static analysis | Larastan/PHPStan level 5, `phpstan.neon` + `phpstan-baseline.neon` (270 pre-existing findings baselined, down from 581; new code must be clean) |
| Formatting | Laravel Pint, enforced in CI |
| Accessibility | Skip link, semantic landmarks, labelled controls, visible focus rings, `role="status"`/`role="alert"` regions, table captions, empty states and permission-denied states in every shipped screen |
| PWA | `Pwa\ProgressiveWebAppController` (manifest, worker, offline page), `views/pwa/service-worker`, `views/pwa/offline`, `components/pwa-head` in all three layouts, `routes/pwa.php`, generated icons in `public/icons` (192, 512, maskable 512, apple-touch) |
| Accessibility | `tests/Support/AccessibilityAudit` — eleven markup checks (title, lang, heading order, alt text, control names, labels, link text, tabindex, duplicate ids, landmarks, table headers) run over every public page and the portal |
| Browser tests | Laravel Dusk; `tests/Browser/PublicJourneyTest` (7 journeys), `.env.dusk.local`, `DuskTestCase` reuses an already-running driver; wired into CI with artifact upload on failure |
| **Status** | **In progress** |

**The service worker never caches HTML, and that is the whole point of it.**
Almost every PWA guide caches pages for offline reading; doing it here would be
a data leak. A customer's portal page carries their bookings and invoices, a
staff page carries other people's, and devices are shared — a family phone, an
office machine, an internet cafe. A cached HTML response is served to whoever
asks next, signed in or not, and logging out would not remove it. So only
content-hashed `/build/` and `/icons/` output is cached, because its URL changes
when its content does and it contains nobody's data. Navigations are
network-only with the offline page as the fallback. POSTs are never intercepted
— replaying one from a cache would submit a booking twice — and `/admin/`,
`/chat/`, `/webhooks/` and `/health` are excluded outright.

The manifest and worker are **generated**, not static files: the manifest takes
its name from company settings so the installed icon matches the settings
screen, and the worker embeds a cache version hashed from the Vite manifest so a
deployment invalidates old caches by itself. A stale worker serving last
release's JavaScript is the most common way a PWA breaks after a deploy, so the
worker is served `no-store` — a browser that will not re-fetch it can never be
given a new one.

**The accessibility audit is honest about its limits.** It proves what markup
can prove and says so in its own docblock: colour contrast, focus visibility and
whether alt text is *useful* need a browser or a person. A green run means no
page has lost its headings, labels or landmarks — not that the site is
accessible. Two of its own tests check the checker: one feeds it broken markup
and asserts it complains, because a checker that passes everything is worse than
none.

It found three real bugs on first run: the F19 chat widget's submit button was
named only by an Alpine `x-text` binding (no accessible name in markup, and none
at all if the script fails to load), and three car-hire views nested a `<main>`
inside the layout's, giving those pages two `main` landmarks and a skip link
that lands on the wrong one. All three are fixed.

**Browser tests cover what HTTP tests cannot.** Seven journeys, deliberately
few — they are slow and flake under load, so they cover the paths where being
wrong is expensive and leave breadth to the 1,350-test HTTP suite. They caught
that the skip link's `sr-only` is defeated for `height` by its own padding
utilities (it is `clip` that hides it), and they are the only place F19's
Alpine-driven chat widget is genuinely exercised rather than asserted from
markup.

`DuskTestCase` reuses a driver that is already running rather than starting its
own — that covers CI, where the driver is a service, and local Windows, where
Dusk's process spawning does not reliably start the bundled binary.

**Remaining gaps:** no automated colour-contrast checking (needs axe-core in the
browser); no frontend linting in CI, which is low value while the JavaScript is
a single Alpine component and one service worker; and the browser suite runs
only the public and customer journeys, not staff console workflows.

---

## Verification rule

Before changing a row to **Verified**, this document must link the exact
migrations/tables, models, enums, policies, actions/services, routes, controllers
or Livewire components, screens, notifications/jobs, integrations, automated
tests, and user-journey evidence. Any missing surface keeps the feature below
Verified.
