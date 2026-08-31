# Security

This document records what is **implemented and tested today**, and what is
still missing. A control listed under "Not yet implemented" is a known gap, not
an oversight.

## Authentication

| Control | State | Evidence |
|---|---|---|
| Registration, login, logout | Implemented | `routes/auth.php`, `tests/Feature/Auth/AuthenticationTest.php`, `RegistrationTest.php` |
| Session regeneration on login | Implemented | Fortify default; `AuthenticationTest` |
| Password hashing | Implemented | bcrypt; `BCRYPT_ROUNDS=4` only under test |
| Strong password validation | Implemented | `Actions\Fortify\CreateNewUser` |
| Forgotten password / reset | Implemented | `PasswordResetTest` — field names and routes verified to match end to end |
| Email verification and resend | Implemented | `EmailVerificationTest`; `verified` middleware on every customer route |
| TOTP 2FA with QR provisioning | Implemented | Fortify; `TwoFactorAuthenticationTest` |
| Recovery codes | Implemented | `TwoFactorAuthenticationTest` |
| Two-step login challenge | Implemented | `config/security.two_factor.challenge_ttl_seconds` |
| Forced 2FA for privileged roles | Implemented | `EnsureTwoFactorAuthenticationIsConfigured`; `security.two_factor.required_roles` defaults to `super_admin,manager`; tested in the transfer and flight authorization suites |
| Account activation/deactivation | Implemented | `AccountStatus`; `EnsureAccountIsActive` appended to the `web` group |
| Password confirmation for sensitive actions | Implemented | `PasswordConfirmationTest` |
| Preferred language and currency | **Not yet implemented** | F02 gap |

## Spreadsheet exports

A CSV cell whose text begins with `=`, `+`, `-`, `@`, a tab, or a carriage
return is executed as a **formula** by Excel, LibreOffice, and Google Sheets. A
customer stored as `=cmd|'/c calc'!A1` would therefore run on the machine of
whoever opened the export, without any flaw in this application.

`App\Support\Export\CsvCell` neutralises every trigger by prefixing a single
quote, and strips control characters that could break a row apart or hide
content from a reviewer. `StreamedCsv` routes every cell — headings included —
through it, so no exporter can forget.

Exports are also:

- **streamed**, never assembled in memory, so a large range cannot exhaust the
  shared-hosting memory limit;
- **column-explicit**, so no password hash, remember token, two-factor secret,
  provider reference, webhook payload, or idempotency material can travel out
  with the data;
- **role-gated** — operational datasets for staff, financial ones for managers
  and above;
- **audited** — `export.generated` records who took what, and for which window.
  Taking a copy of customer or payment data out of the system is a data-access
  event, not a page view.

## Authorization

Navigation visibility is never treated as authorization. Every protected route
carries middleware **and** a policy check.

Layered enforcement, outermost first:

1. `auth` — session required.
2. `verified` — email confirmed.
3. `EnsureAccountIsActive` — a suspended account is logged out, not merely denied.
4. `role:…` middleware — coarse role gate.
5. `2fa.required` — mandatory TOTP for configured roles.
6. **Policy** — ownership and per-record ability.
7. **Action-layer re-check under a row lock** — the actor and any assignee are
   re-validated after locking, so a role change or suspension mid-request cannot
   slip through a TOCTOU window.

Registered policies (`AppServiceProvider::boot`): `StaffUserPolicy` (for `User`),
`TourCategoryPolicy`, `TourPackagePolicy`, `TourDeparturePolicy`,
`TourBookingPolicy`, `VehiclePolicy`, `CarHireBookingPolicy`,
`CarHireDocumentPolicy`, `CarHireContractPolicy`, `AirportPolicy`,
`AirportTransferLocationPolicy`, `AirportTransferRatePolicy`,
`AirportTransferBookingPolicy`, `FlightInquiryPolicy`.

### Ownership

Customer records resolve through **scoped route bindings**, not through a
where-clause the controller might forget:

```php
Route::bind('customerFlightInquiry', function (string $reference): FlightInquiry {
    $user = request()->user();
    abort_unless($user instanceof User, 404);

    return FlightInquiry::query()->forCustomer($user)
        ->where('reference', $reference)->firstOrFail();
});
```

A foreign reference returns **404, not 403** — a 403 would confirm the record
exists. Bindings exist for `customerTourBooking`, `customerCarHireBooking`,
`customerAirportTransferBooking`, and `customerFlightInquiry`, each covered by a
cross-account isolation test.

### Privilege escalation

- Public customer registration can never create staff. Staff accounts are
  provisioned inactive by a super administrator and activated through an
  expiring hashed invitation token (`StaffInvitationService`).
- The last super administrator cannot be demoted or deactivated.
- Reopening a resolved flight inquiry is a **narrower ability than editing it**:
  `FlightInquiryPolicy::reopen` restricts it to the roles in
  `flight_inquiries.reopen.roles`, and `TransitionFlightInquiryRequest`
  re-checks that ability server-side rather than relying on the hidden UI option.

## Request and input security

| Control | State |
|---|---|
| CSRF on every state-changing form | Implemented (`web` middleware group; all forms use `@csrf`) |
| Form Request validation on every mutation | Implemented |
| Mass-assignment protection | Implemented — server-owned fields are set with `forceFill` inside actions, never from request input. Tested: `http booking submission discards server owned fields` |
| Output escaping | Implemented — Blade `{{ }}` everywhere; no `{!! !!}` in shipped views |
| Server-authoritative pricing | Implemented — the browser never supplies an amount. `QuoteAirportTransfer` resolves the same rate version `CreateAirportTransferBooking` selects |
| Server-authoritative identity | Implemented — a signed-in customer's name/email/phone overwrite anything the form submitted. Tested in both transfer and flight suites |
| Rich-text sanitisation | **Not yet implemented** — no rich-text input exists yet (arrives with F12) |
| CORS configuration | **Not yet implemented** — no API routes exist yet |

## Rate limiting

| Surface | Limit |
|---|---|
| Contact form | 5/min |
| Newsletter | 10/min |
| Booking and enquiry submission | 10/min |
| Cancellation | 6/min |
| Private document download | 30/min (customer), 60/min (admin) |
| Signed guest tracking pages | 30/min |
| Notification mail dispatch | Per-domain `RateLimited` queue middleware, mail channel only, so database notifications are never delayed by the SMTP allowance |

## Secrets and sensitive data

- All credentials live in environment variables. `.env` is git-ignored; so are
  `.env.backup`, `.env.production`, and `auth.json`.
- No real credential is committed. `.env.example` carries placeholders only.
- TOTP secrets, recovery codes, self-drive identity/permit numbers, transfer
  flight numbers, and service addresses use the `encrypted` cast.
- `$hidden` removes `idempotency_owner_hash`, `idempotency_key`,
  `request_fingerprint`, `internal_notes`, and encrypted operational fields from
  serialisation.
- Idempotency and fingerprint hashes are HMAC-keyed with `APP_KEY`, so they
  cannot be recomputed from a leaked database alone.

### Audit redaction

`Services\AuditLogger` redacts by **key pattern** before persisting, covering
password, token, secret, authorization, cookie, signature, api key, private key,
recovery codes, OTP, national id, identity, passport, driving permit/licence,
document path, idempotency key/owner hash, request fingerprint, contact
name/email/phone, service address, flight number, special requests, internal
notes, and cancellation/unassignment/replacement reasons. URLs are redacted for
invitation, password-reset, and email-verification token segments, and for
sensitive query parameters. Covered by `tests/Feature/AuditLoggerTest.php`.

## Private files

Identity documents, driving permits, and selfies are stored on a **private
disk**, outside the public web root. They are served only through
`CarHire\CarHireDocumentController`, which authorizes via `CarHireDocumentPolicy`
before streaming. There is no public URL for a private document.

Guest-facing pages that have no account to authorize against — the transfer
acknowledgement and the flight-enquiry tracking page — use **temporary signed
URLs** with a configurable expiry, and additionally refuse to serve a record that
belongs to an account (`abort_unless($record->isGuest(), 404)`) so the guest
surface cannot become a bypass for the authenticated portal. Both behaviours are
tested, including expired and unsigned requests.

## Concurrency safety

Booking availability, assignment, and status transitions run in transactions
with row locks in a fixed order (`actor → driver → vehicle → booking → rate`).
Status changes are guarded by explicit enum transition graphs; invalid jumps are
rejected and tested. Repeating a terminal transition is a no-op rather than a
second side effect, and loyalty-eligibility events use `firstOrCreate` so a
retry cannot award twice.

## Known gaps

These are required by the brief and **not implemented**:

- **Security headers middleware** — CSP, HSTS, `X-Frame-Options`,
  `X-Content-Type-Options`, `Referrer-Policy` are not set.
- **Payment webhook signature verification, replay windows, amount/currency and
  ownership verification** — F14 does not exist. No payment path is live, so
  there is no unverified webhook accepting money today.
- **WhatsApp webhook verification and signature validation** — F19 does not exist.
- **Upload content inspection** — real MIME sniffing beyond extension/declared
  type, and an explicit no-executable rule, arrive with F27.
- **Database least privilege** — deployment currently assumes a single
  application user; a reduced-grant runtime user is not yet documented per
  environment.
- **Automated security review** — `composer audit` and `npm audit` run in CI;
  there is no SAST beyond PHPStan level 5.

## Reporting

Report suspected vulnerabilities to the PISFA technical owner. Do not open a
public issue containing exploit detail.
