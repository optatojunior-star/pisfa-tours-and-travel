# Integrations

## Current state

| Integration | Status | Notes |
|---|---|---|
| SMTP email | **Live** | The only external integration in use today. |
| TOTP two-factor | **Live** | Local computation via Laravel Fortify; no third party. |
| Payment providers | **Not implemented** | F14. No provider interface exists yet. |
| Africa's Talking SMS | **Not implemented** | F18. |
| Meta WhatsApp | **Not implemented** | F18, F19. |
| Hosted broadcasting (Pusher/Ably) | **Not implemented** | F19. `BROADCAST_CONNECTION=null`. |
| Cloudinary / S3 storage | **Not implemented** | F27. Local private disk only. |
| Exchange rates | **Not implemented** | F14. `config/pisfa.php` holds a static configured rate. |
| Google Analytics | **Not implemented** | F01. |

No feature currently claims a capability it does not have. Every customer-facing
amount panel states that no payment is collected online, and no screen promises
SMS or WhatsApp delivery.

## Package inventory and rationale

The brief requires documenting every important package and why it was selected,
and preferring native Laravel functionality before adding a dependency.

| Package | Why | Native alternative rejected because |
|---|---|---|
| `laravel/framework` ^12 | Required by the brief. | — |
| `laravel/fortify` ^1.37 | Headless auth: registration, reset, verification, TOTP, recovery codes, two-step challenge. | Hand-rolling TOTP and recovery-code hashing is security-critical work with no upside. Fortify is headless, so all Blade markup stays ours. |
| `livewire/livewire` ^3.6 | Required by the brief for interactive interfaces. | **See "Livewire" below — currently installed but effectively unused.** |
| `laravel/tinker` | REPL for operational inspection. | — |
| `larastan/larastan` ^3 | Required static analysis. Adds Laravel-aware types on top of PHPStan. | Bare PHPStan cannot resolve Eloquent magic, so it would produce noise instead of signal. |
| `laravel/pint` | Required formatter. | — |
| `phpunit/phpunit` ^11 | Required test runner. | — |
| `fakerphp/faker`, `mockery/mockery`, `nunomaduro/collision`, `laravel/pail`, `laravel/sail`, `laravel/breeze` | Dev tooling and scaffolding. | — |

**Deliberately not installed yet**, because the features that need them do not
exist and an unused dependency is attack surface:

- A PDF generator (F27) — needed for rental/lease contracts, quotations,
  invoices, payroll, receipts, statements.
- A permission package such as `spatie/laravel-permission` — the five roles are a
  closed set enforced by enum, middleware, and policies. A package would be
  added only if F23 requires genuinely granular per-user permissions.
- An activity-log package — `Services\AuditLogger` already writes redacted,
  immutable entries and is called by real workflows.
- Payment SDKs — added per provider with F14.

### Livewire — approved usage policy

Livewire 3 is required by the brief and is installed. **Product owner decision:
use Livewire for genuinely interactive screens only.** This is a recorded,
approved scope for the dependency, not an unreviewed deviation.

**Server-rendered Blade** for document-style flows — a form, a validated POST, a
redirect. These work with JavaScript disabled and need no component lifecycle.
Covers everything shipped so far: the tour, car-hire, airport-transfer, and
flight-enquiry forms, portals, and operations consoles.

**Livewire** where interactivity is the feature and a full page reload would
break the experience:

| Feature | Why Livewire |
|---|---|
| F19 live chat | Message polling, unread state, typing indicators |
| F09 availability calendars | Month navigation and room/date selection |
| F13 customer portal | Live panels over several domains |
| F14 checkout status | Polling a pending provider result |

**F20 was on this list and was built without Livewire.** Once the screens
existed, the case did not hold: the dashboard has no chart state to preserve, and
the unified booking list is more useful with URL-addressable filters an operator
can bookmark and share than with partial updates. Server-rendered Blade also
works without JavaScript and costs nothing on shared hosting, where a polling
component is real load for little gain. Recorded here rather than silently
skipped, because it narrows a decision made earlier.

Retrofitting the existing static forms would add a component lifecycle and a JS
dependency to flows that work correctly without either. Every Livewire component
added must still enforce authorization server-side in its own methods —
component state is client-influenced and is never trusted.

## Provider interface design (planned, F14 onward)

Every external service will sit behind an interface in `app/Contracts/`, bound in
a service provider, with a fake used by tests. Automated tests must never depend
on real credentials.

```
PaymentGateway      initiate(), verify(), refund(), parseWebhook()
SmsSender           send(recipient, message): DispatchResult
WhatsAppSender      send(...), verifyWebhookSignature(...)
DocumentStorage     put(), temporaryUrl(), delete()
ExchangeRateSource  rate(from, to, at): int
```

Planned implementations: MTN Mobile Money, Airtel Money, Stripe, PayPal,
PesaPal, Flutterwave, bank transfer, administrator-recorded cash; Africa's
Talking; Meta WhatsApp Cloud API; local/Cloudinary/S3; configured static rate.

### Webhook rules (to be enforced when F14/F19 land)

Before any business state changes, a webhook must pass **all** of:

1. Signature verification with the provider's documented scheme.
2. Timestamp/replay window validation where the provider supports it.
3. Amount **and** currency match against the stored intent.
4. Transaction ownership verification.
5. Idempotency-key deduplication, so a duplicate delivery is a no-op.

Provider responses are logged with secrets redacted. Card numbers are never
stored.

## Environment variables

Names are fixed here so UI, config, and deployment cannot drift apart.

### Active today

```
MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD,
MAIL_ENCRYPTION, MAIL_FROM_ADDRESS, MAIL_FROM_NAME

DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD
QUEUE_CONNECTION=database   CACHE_STORE=database   SESSION_DRIVER=database

PISFA_TWO_FACTOR_REQUIRED_ROLES=super_admin,manager
PISFA_TWO_FACTOR_CHALLENGE_TTL=300
```

Domain tuning (all optional, all with safe clamped defaults) is documented in
`config/tours.php`, `config/car_hire.php`, `config/airport_transfers.php`, and
`config/flight_inquiries.php`. Each key is listed in the matching feature
document.

### Reserved for unimplemented integrations

Declared here so no future work invents a second spelling:

```
# F14
STRIPE_KEY  STRIPE_SECRET  STRIPE_WEBHOOK_SECRET
PAYPAL_CLIENT_ID  PAYPAL_CLIENT_SECRET  PAYPAL_WEBHOOK_ID  PAYPAL_MODE
MTN_MOMO_SUBSCRIPTION_KEY  MTN_MOMO_API_USER  MTN_MOMO_API_KEY  MTN_MOMO_ENVIRONMENT
AIRTEL_MONEY_CLIENT_ID  AIRTEL_MONEY_CLIENT_SECRET  AIRTEL_MONEY_ENVIRONMENT
PESAPAL_CONSUMER_KEY  PESAPAL_CONSUMER_SECRET  PESAPAL_ENVIRONMENT
FLUTTERWAVE_PUBLIC_KEY  FLUTTERWAVE_SECRET_KEY  FLUTTERWAVE_ENCRYPTION_KEY
PISFA_EXCHANGE_RATE_UGX_PER_USD

# F18 / F19
AFRICASTALKING_USERNAME  AFRICASTALKING_API_KEY  AFRICASTALKING_SENDER_ID
WHATSAPP_PHONE_NUMBER_ID  WHATSAPP_ACCESS_TOKEN
WHATSAPP_VERIFY_TOKEN  WHATSAPP_APP_SECRET
BROADCAST_CONNECTION  PUSHER_APP_ID  PUSHER_APP_KEY  PUSHER_APP_SECRET  PUSHER_APP_CLUSTER

# F27
FILESYSTEM_DISK  CLOUDINARY_URL
AWS_ACCESS_KEY_ID  AWS_SECRET_ACCESS_KEY  AWS_DEFAULT_REGION  AWS_BUCKET  AWS_ENDPOINT

# F01
GOOGLE_ANALYTICS_MEASUREMENT_ID
```

## Hostinger constraints

Production must run without Docker, Redis, PostgreSQL, Supervisor, root access,
a persistent Node process, or a self-hosted inbound WebSocket server.

Consequences that shape integration design:

- **Broadcasting must be hosted** (Pusher/Ably) and every live feature needs a
  working AJAX polling fallback. Laravel Reverb is not an option.
- **Queues are database-backed** and processed by a short-lived cron worker, so
  every job must be idempotent and safe to run twice.
- **Assets are prebuilt** in CI or locally and deployed; `npm` never runs in
  production.

## Callback and webhook URLs

To be registered with each provider when F14/F19 land. Recorded here in advance
so deployment and provider dashboards cannot diverge:

```
https://<domain>/webhooks/payments/{provider}
https://<domain>/webhooks/whatsapp        (GET verify + POST receive)
```

These routes are CSRF-exempt by necessity and therefore rely entirely on
signature verification and idempotency for their integrity.
