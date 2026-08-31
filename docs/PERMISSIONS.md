# Permissions

## Status and purpose

This document is the proposed authorization baseline. The Laravel scaffold does
not yet implement the complete permission set. Every rule must ultimately be
enforced in middleware, policies, scoped queries, actions, and tests.

The system has six access levels:

- Visitor
- Customer
- Driver
- Staff
- Manager
- Super administrator

Role names describe default permission bundles. Policies remain the final
authority for a particular record and action.

## Permission vocabulary

- **Public:** no account required, subject to validation and rate limits.
- **Own:** only records owned by, assigned to, or securely linked to the current
  authenticated principal.
- **Operational:** records within staff service responsibilities, excluding
  privileged finance, payroll, role, security, audit, and system settings.
- **Financial:** manager-level payment, refund, reconciliation, expense,
  payroll, invoice, credit, and report actions.
- **System:** staff administration, permissions, audit, security policy,
  credentials, providers, and global settings.

## High-level matrix

| Capability | Visitor | Customer | Driver | Staff | Manager | Super administrator |
|---|---:|---:|---:|---:|---:|---:|
| Browse published public content | Yes | Yes | Yes | Yes | Yes | Yes |
| Submit eligible guest inquiry/booking | Yes | Yes | No | Yes | Yes | Yes |
| Manage personal profile and security | No | Own | Own | Own | Own | Own |
| View customer portal records | No | Own | No | Operational support only | Operational support only | Yes |
| Cancel an eligible customer booking | No | Own | No | Operational | Operational | Yes |
| Pay a customer balance | Secure guest flow where offered | Own | No | No | Record authorized manual payment | Yes |
| Download private customer documents | Signed guest flow where offered | Own | Assigned operational need | Operational need | Operational/financial need | Yes |
| View driver workspace | No | No | Own assignments | Operational support | Operational support | Yes |
| Update driver availability/trips/fuel | No | No | Own permitted records | Operational oversight | Operational oversight | Yes |
| Manage service catalogues and bookings | No | No | Assigned actions only | Operational | Operational | Yes |
| Assign drivers and vehicles | No | No | No | Operational | Operational | Yes |
| Manage public CMS and review moderation | No | No | No | Operational | Operational | Yes |
| View customer CRM | No | Own profile only | No | Operational | Operational | Yes |
| Manage corporate clients and groups | No | Own authorized account only | No | Operational where delegated | Yes | Yes |
| View payment transaction details | Own checkout status only | Own | No | Minimal operational state | Yes | Yes |
| Reconcile or refund payments | No | No | No | No | Yes | Yes |
| Create and send quotations | Request only | View own | No | Operational where delegated | Yes | Yes |
| Manage invoices and payment allocation | No | View/pay own | No | No unless explicitly delegated | Yes | Yes |
| Submit an expense | No | No | Own where policy permits | Own where policy permits | Yes | Yes |
| Approve/reject expenses | No | No | No | No | Yes, subject to segregation | Yes |
| View or manage payroll | No | Own payslip if enabled | Own payslip if enabled | Own payslip if enabled | Yes | Yes |
| View management analytics and exports | No | Own summaries | Own trip summaries | Limited operations | Yes | Yes |
| Create or manage staff accounts | No | No | No | No | No | Yes |
| Assign roles and permissions | No | No | No | No | No | Yes |
| View audit logs | No | No | No | No | No by default | Yes |
| Change global/system settings | No | No | No | No | Limited non-security values if delegated | Yes |
| Change provider credentials or 2FA policy | No | No | No | No | No | Yes |

## Current staff-administration enforcement

The implemented team-management surface is restricted to authenticated,
email-verified, active super administrators who have satisfied mandatory 2FA.
Policies authorize staff listing, invitation, access updates, and invitation
resends; the customer role is never provisioned through this workflow. Staff
mutations also require recent password confirmation.

Invited accounts remain inactive until the intended recipient accepts the
single-use email link and chooses a password. A super administrator may assign
a staff role, change account status, and apply an individual 2FA requirement,
but cannot demote or deactivate their own account or remove the final active
super administrator. Access changes revoke the affected user's stored database
sessions. These server controls are authoritative even when a navigation item
is hidden.

## Resource rules

### Current tours enforcement

Published packages in active categories are public; guessed draft,
future-published, archived, or inactive-category slugs return not found.
Checkout requires an active, email-verified customer account. Customer booking
lookups are owner-scoped during route binding so another customer's reference
also returns not found, and cancellation is separately policy- and state-
checked.

Tour catalogue, departure, and booking operations require an active staff,
manager, or super-administrator role, verified email, configured mandatory 2FA
where applicable, and the relevant model policy. Only those operational roles
can publish/archive packages, change departures, transition bookings, or assign
drivers. Drivers cannot access the staff console or customer portal; they may
receive only the assignment details needed for their own work. Every successful
package, category, departure, booking-state, cancellation, and assignment
mutation records a redacted audit event.

### Current car-hire enforcement

Only currently published, operationally available vehicles and effective
public rate/media data are exposed in the car-hire catalogue. Creating a hire
request requires an active, email-verified customer. Customer car-hire route
binding is owner-scoped, so a foreign booking reference returns not found even
before the policy check.

Customers may view and cancel only their own eligible requests, edit or submit
only their own editable self-drive application, manage its private files, and
accept or download only the current contract for their booking. Active staff,
managers, and super administrators manage catalogue/rates, booking review and
transitions, private operational downloads, and assignments behind verified-
email and mandatory-2FA middleware. Drivers cannot open the admin or customer
surfaces and cannot download identity files. Server actions recheck account,
ownership, timing, state, availability, and assignment prerequisites inside
their transactions; hidden navigation is not relied upon.

### Current airport-transfer enforcement

The public airport-transfer planner exposes only active airports, locations,
and effective route/type/currency rates. Guest submission is rate limited and
requires complete mail-confirmable contact details, a UUID idempotency key, and
an explicit booking-details acknowledgement. Its temporary signed response is
not a general reference lookup and never grants customer or staff authority.

Authenticated customer identity snapshots are server-owned. Customer transfer
binding is scoped through the authenticated account, so a foreign reference
returns not found before the policy check; customers may view and cancel only
their own eligible requests. Active staff, managers, and super administrators
manage catalogue/rates, assignments, and transitions behind verified-email,
role, mandatory-2FA, Form Request, and policy enforcement. Confirmation requires
one active atomic eligible driver/vehicle pair. Drivers cannot open customer or
administration screens and receive only the minimum operational notification
details for their assignment.

The server owns rate selection, capacity, amount, status, customer association,
and resource assignment. No F05 role may post or mutate a payment method,
payment state, receipt, refund, live-flight result, SMS, or WhatsApp result.

### Public and guest actions

Visitors may read only records explicitly published for public access. Guest
forms must collect enough contact information for fulfilment and must be rate
limited. Guest tracking and document actions require an unguessable reference
plus an additional verifier, expiring signed link, or one-time token where the
data is sensitive.

A public route must never reveal whether an arbitrary email, telephone number,
booking ID, payment ID, or sequential record exists.

### Customer ownership

Customers can read or mutate only records related to their account, except for
published catalogue content. This includes bookings, imports, payments,
receipts, contracts, quotations, invoices, reviews, loyalty records,
notifications, messages, and uploaded documents.

Ownership checks must query the relationship to the authenticated user; they
must not trust a hidden form field such as `customer_id` or `user_id`.

### Driver assignment

Drivers see only assignments connected to their driver profile. They may update
availability, acceptance, trip progress, odometer, and fuel data only within
defined transitions and time windows. A driver cannot assign vehicles, change
prices, alter customer payment state, inspect unrelated customer history, or
view another driver's work.

### Staff operations

Staff can manage operational catalogues and service records within the
permissions assigned to them. Default staff access excludes:

- Payment refunds and reconciliation
- Payroll management
- Expense approval
- Corporate credit-limit changes
- Staff and permission administration
- Audit logs
- Provider secrets and security policy
- Destructive global settings

Staff should see the minimum customer and payment detail needed to fulfil a
service. Provider payloads and private identity documents require an explicit
operational purpose.

### Manager finance access

Managers may perform approved financial and reporting workflows, including
payments, refunds, reconciliation, invoices, expenses, payroll, corporate
accounts, exports, and analytics. High-risk operations should support
segregation of duties: a manager should not approve their own expense, and a
refund or large credit adjustment may require a second authorized actor when
the configured threshold is exceeded.

Manager status alone must not reveal provider credentials, encryption keys,
TOTP secrets, raw identity documents without purpose, or unrestricted audit and
staff-security controls.

### Super-administrator access

Super administrators configure staff, roles, permissions, security policy,
integrations, and global settings. Their actions remain validated, audited, and
subject to confirmation and two-factor authentication. Super-administrator
access is not an excuse to log or display secrets.

## Suggested permission names

Use stable, action-oriented permission keys. Initial groups should include:

- `dashboard.view-operations`, `dashboard.view-financial`
- `customers.view`, `customers.update-status`, `customers.communicate`
- `tours.manage`, `tour-bookings.manage`, `tour-bookings.assign`
- `hire.manage`, `hire-bookings.manage`, `hire-bookings.assign`
- `transfers.manage`, `transfers.assign`
- `flights.manage`
- `imports.manage`, `imports.price`, `imports.documents`
- `vehicle-sales.manage`, `vehicle-offers.manage`, `vehicle-sales.record`
- `properties.manage`, `property-bookings.manage`
- `leases.manage`, `leases.contracts`, `leases.earnings`
- `corporate.manage`, `group-bookings.manage`, `corporate.credit`
- `content.manage`, `reviews.moderate`
- `fleet.manage`, `fleet.documents`, `fleet.costs`
- `drivers.manage`, `drivers.assign`
- `payments.view`, `payments.record`, `payments.reconcile`, `payments.refund`
- `quotations.manage`, `invoices.manage`
- `expenses.submit`, `expenses.review`
- `payroll.view-own`, `payroll.manage`
- `analytics.view`, `reports.export`
- `notifications.manage-templates`, `chat.handle`
- `staff.manage`, `roles.manage`
- `audit.view`, `audit.export`
- `settings.manage-company`, `settings.manage-security`,
  `settings.manage-integrations`

Do not scatter raw role-name checks throughout business code. Policies should
ask whether the principal has the required permission and satisfies record
scope and state rules.

## Policy requirements

For each protected model, consider these abilities as applicable:

- `viewAny`
- `view`
- `create`
- `update`
- `delete`
- `restore`
- `cancel`
- `transition`
- `assign`
- `pay`
- `refund`
- `approve`
- `reject`
- `download`
- `export`

An action can require more than one condition. For example, downloading a
rental contract requires authentication, ownership or operational permission,
an eligible contract state, and access to the requested document version.

## Sensitive-field rules

Even when a user can view a record, serializers and views must omit values they
do not need. Restrict or redact:

- Password hashes, remember tokens, session tokens, recovery codes, and TOTP
  secrets
- Provider keys, webhook secrets, access tokens, and full callback payloads
- Identity documents, permit images, selfies, insurance papers, and payroll
  information
- Full bank or mobile-money account details
- Internal risk, fraud, and security notes
- Private staff notes from customer-facing responses

## Authorization tests

Every protected action needs tests for:

1. Unauthenticated denial.
2. Allowed role and valid scope.
3. Same role but wrong owner, assignment, corporate account, or operational
   scope.
4. A lower role attempting the action.
5. Invalid resource state.
6. Mass-assignment attempts for owner, price, status, or role fields.
7. Private download and export access.
8. Audit generation for successful material changes.

Maintain a role-journey browser test for customer, driver, staff, manager, and
super administrator. Navigation tests complement but do not replace policy
tests.
