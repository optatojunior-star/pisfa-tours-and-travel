# Database

## Engine and conventions

| Setting | Value | Why |
|---|---|---|
| Engine | MySQL 8 / MariaDB 10.6+, InnoDB | Hostinger Premium provides MariaDB. InnoDB is required for row locks and foreign keys. |
| Charset / collation | `utf8mb4` / `utf8mb4_unicode_ci` | Full Unicode including emoji in customer-supplied text. |
| Timestamps | Stored in **UTC** | `config/app.php` keeps `timezone` at UTC. Business dates are rendered in `Africa/Kampala` at the presentation layer only. |
| Money | Integer **minor units** + ISO currency code on the same row | No floating-point arithmetic anywhere. `App\Support\Money` is the only parser/formatter. UGX has exponent 0; USD has exponent 2. |
| Primary keys | Auto-increment `bigint unsigned` | |
| Public identifiers | ULID-derived `reference` strings (`TRNSF-…`, `FLT-…`) with a unique index, used as the route key | Sequential ids are never exposed in URLs. |
| Deletes | Hard delete by default | Soft deletes are added only where an audit or business rule requires history. Assignment and status history is preserved in dedicated tables instead. |

Local test runs use SQLite in memory for speed (`phpunit.xml`). CI additionally
runs the whole suite against MariaDB using `phpunit.mysql.xml`, because SQLite
cannot exercise `lockForUpdate`, InnoDB unique-constraint races, or MySQL date
semantics. See `docs/TESTING.md`.

## Migration inventory

| Migration | Tables |
|---|---|
| `0001_01_01_000000_create_users_table` | `users`, `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `2026_08_04_000050_add_pisfa_profile_fields_to_users_table` | `users` (role, status, phone, profile) |
| `2026_08_04_000100_create_audit_logs_table` | `audit_logs` |
| `2026_08_04_000100_create_marketing_tables` | `newsletter_subscribers`, `contact_messages` |
| `2026_08_04_000200_create_settings_table` | `settings` |
| `2026_08_04_134042_add_two_factor_columns_to_users_table` | `users` (TOTP secret, recovery codes, confirmation) |
| `2026_08_04_140000_add_staff_provisioning_fields` | `users`, `staff_invitations` |
| `2026_08_20_000100_create_tour_domain_tables` | `tour_categories`, `tour_packages`, `tour_package_media`, `tour_package_items`, `tour_itinerary_days`, `tour_departures`, `tour_bookings`, `tour_travelers`, `tour_assignments`, `tour_booking_events` |
| `2026_08_20_000200_create_car_hire_domain_tables` | `vehicles`, `vehicle_media`, `vehicle_hire_rates`, `car_hire_bookings`, `car_hire_self_drive_applications`, `car_hire_documents`, `car_hire_contracts`, `car_hire_driver_assignments`, `car_hire_booking_events` |
| `2026_08_27_000300_create_airport_transfer_domain_tables` | `airports`, `airport_transfer_locations`, `airport_transfer_rates`, `airport_transfer_bookings`, `airport_transfer_assignments`, `airport_transfer_events` |
| `2026_08_28_000400_create_flight_inquiry_domain_tables` | `flight_inquiries`, `flight_inquiry_entries` |

## Domain relationships

### Identity and access

```
users ──< staff_invitations        (invited_by / accepted_by)
users ──< audit_logs               (actor; nullable for console actions)
users ──< sessions
```

`users.role` is an `App\Enums\UserRole` string column; `users.status` is
`App\Enums\AccountStatus`. There is no separate roles table — the five roles are
a closed set enforced by enum, middleware, and policies.

### Tours (F03)

```
tour_categories ──< tour_packages ──< tour_package_media
                                   ├─< tour_package_items      (inclusions/exclusions)
                                   ├─< tour_itinerary_days
                                   └─< tour_departures ──< tour_bookings ──< tour_travelers
                                                                          ├─< tour_assignments
                                                                          └─< tour_booking_events
```

### Car hire and fleet (F04, partial F21)

```
vehicles ──< vehicle_media
         ├─< vehicle_hire_rates                (versioned; append-only)
         └─< car_hire_bookings ──┬─< car_hire_self_drive_applications
                                 ├─< car_hire_documents          (private storage)
                                 ├─< car_hire_contracts          (versioned; voidable)
                                 ├─< car_hire_driver_assignments
                                 └─< car_hire_booking_events
```

### Airport transfers (F05)

```
airports ──┐
           ├──< airport_transfer_rates ──< airport_transfer_bookings ──┬─< airport_transfer_assignments
airport_transfer_locations ──┘                                        └─< airport_transfer_events
```

### Flight inquiries (F06)

```
users ──< flight_inquiries ──< flight_inquiry_entries
              (nullable customer_id: guest enquiries have no account)
```

## Rate versioning

`vehicle_hire_rates` and `airport_transfer_rates` are **append-only version
tables**, not mutable price rows. Publishing a new version closes the previous
matching version by setting `effective_until`; existing bookings keep their own
price snapshot columns and are unaffected.

`airport_transfer_rates` carries a unique key on
`(airport_id, airport_transfer_location_id, transfer_type, vehicle_type, currency, effective_from)`
so two versions cannot start at the same instant for one route.

## Price and identity snapshots

Every booking table stores denormalised snapshots taken at creation time —
`vehicle_name_snapshot`, `airport_code_snapshot`, `location_name_snapshot`,
`vehicle_type_snapshot`, `passenger_capacity_snapshot`, `amount_minor`,
`currency`, `estimated_duration_minutes`. Renaming an airport or retiring a rate
must never alter what a customer already agreed to.

## Guest submissions and idempotency

Guest bookings and enquiries have `customer_id NULL`. They are **never** attached
to a placeholder "system" user, which would violate referential intent and leak
records across guests.

Because a guest has no user id to deduplicate against, each guest-capable table
carries three columns:

| Column | Purpose |
|---|---|
| `idempotency_owner_hash` | `hash_hmac('sha256', "guest\|email\|phone"` or `"customer\|id", APP_KEY)` |
| `idempotency_key` | Client-supplied UUID from the form |
| `request_fingerprint` | HMAC over the canonicalised, normalised payload |

`(idempotency_owner_hash, idempotency_key)` is **unique**. Replaying a form
returns the original record. Reusing the key with different content raises a
validation error rather than silently overwriting. Applies to
`airport_transfer_bookings` and `flight_inquiries`.

## Encryption at rest

Selected columns use Laravel's `encrypted` cast, so the value is unreadable in a
database dump or replica:

| Table | Encrypted columns |
|---|---|
| `airport_transfer_bookings` | `flight_number`, `service_address` |
| `users` | TOTP secret, recovery codes |
| `car_hire_self_drive_applications` | identity and permit numbers |

Encrypted columns cannot be searched with `LIKE`. Where operations need to find a
record, the searchable surface is the `reference`, contact name, or contact email
instead — never the encrypted field.

## Locking and transaction order

Financial and availability mutations run inside `DB::transaction(..., 3)` with
`lockForUpdate()`. Every transport domain acquires locks in one **fixed global
order** to prevent deadlock between tours, car hire, and airport transfers:

```
actor → driver → vehicle → booking → rate
```

Conflict queries run only after both serialisation rows are held. Cross-domain
conflict checks are reciprocal: assigning a driver to a transfer checks tour and
car-hire assignments, and vice versa.

## Indexes

Every foreign key is indexed. Additional composite indexes target the actual
query shapes rather than single columns:

- `airports_active_sort_index (is_active, sort_order)` — public dropdown ordering.
- `airport_transfer_rates_lookup_index (airport_id, location_id, transfer_type, currency, is_active, effective_from)` — the planner's rate resolution.
- `airport_transfer_rates_capacity_index (vehicle_type, passenger_capacity, luggage_capacity)` — party-size filtering.
- `flight_inquiries_status_departure_index (status, outbound_on)` — the follow-up queue.
- `flight_inquiries_owner_status_index (assigned_to_user_id, status)` — "assigned to me".
- `flight_inquiries_customer_index (customer_id, created_at)` — portal history.

## Referential actions

| Relationship | On delete | Reason |
|---|---|---|
| booking → rate / airport / location | `restrict` | A priced booking must never lose the row that priced it. |
| booking → customer | `set null` | Account deletion must not destroy operational and financial history. |
| entries/events → parent booking | `cascade` | History has no meaning without its subject. |
| audit_logs → user | `set null` | Audit entries outlive the actor by design. |

## Pagination

Every list query that can grow is paginated (`paginate()` with
`withQueryString()`), never `get()`. Admin lists use 20 per page, customer lists
15, public catalogues 12.

## Seeders

```bash
php artisan db:seed          # everything below, in order
```

**Reference data**, safe in any environment:

- `TourCategorySeeder` — the five tour categories.
- `SystemSettingSeeder` — the settings F26 reads.

**Demo data**, local and testing only:

- `DemoRoleUserSeeder` — one account per role, with a random password printed
  once on creation.
- `DemoCatalogueSeeder` — six tours with forward-dated departures, five fleet
  vehicles with hire rates, four properties with room types and nightly rates.
- `DemoContentSeeder` — five journal posts and four showroom listings, one of
  them sold.

Three properties hold for every demo seeder, and
`tests/Feature/Seeding/DemoSeederTest` proves each of them rather than leaving
them to be remembered:

1. **Additive.** Everything is created with `firstOrCreate` on a natural key.
   Nothing truncates, nothing calls `migrate:fresh`, and a run against a
   database holding real records adds to it rather than replacing it.
2. **Idempotent.** Running twice changes no row count. Departures are keyed on
   *how many future departures already exist* rather than on their dates —
   the dates come from `now()`, so keying on them would be idempotent within a
   day and would quietly accumulate a fresh set every day after that.
3. **Refuses production.** Each demo seeder checks the environment and returns.
   `db:seed` puts up its own production confirmation as a second layer.

Two details worth knowing if you extend them. Money goes through
`Money::parse`, so UGX 5,400,000 is stored as `5400000` — getting that wrong by
a factor of a hundred is the classic seeded-money bug and it looks entirely
plausible on the page, so a test asserts the stored integer. And date columns
cast to `date` are stored with a midnight time component, so a `firstOrCreate`
lookup must pass a Carbon rather than a `Y-m-d` string, or it never matches and
every run attempts a duplicate insert.
