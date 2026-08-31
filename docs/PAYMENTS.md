# Payments (F14)

The money path. Every guarantee here exists because getting it wrong means
charging a customer twice, settling a booking nobody paid for, or refunding more
than was collected.

## Non-negotiables

| Rule | How it is enforced |
|---|---|
| The browser never proposes an amount | `CreatePaymentIntent` reads `outstandingAmountMinor()` from the payable. There is no amount field in any request. |
| No floating-point money | Integer minor units throughout. Exchange rates are integer parts-per-million. `App\Support\Money` is the only parser/formatter. |
| One status vocabulary | `PaymentStatus`. Provider strings are translated at the gateway boundary; nothing outside an adapter sees a raw provider status. |
| A duplicate webhook changes nothing | Unique `(provider, event_id)` in the database, plus `SettlePayment` returning early when already settled. |
| A refund can never exceed what was collected | Headroom recomputed under a row lock, counting completed *and* in-flight refunds. |
| Historic revenue is immutable | The exchange rate is stamped on the payment at creation. Changing the configured rate later cannot rewrite it. |

## Schema

| Table | Purpose |
|---|---|
| `payments` | The intent and its outcome. Polymorphic `payable`. Carries amount + currency, base amount + base currency + rate, and a running `refunded_amount_minor`. |
| `payment_allocations` | Credits a payment to a service or invoice. Unique `(payment, target)`. |
| `refunds` | Refund ledger. Unique `(payment, idempotency_key)`. |
| `payment_webhook_events` | Immutable log of every verified delivery. Unique `(provider, event_id)`. |

Two unique keys carry most of the safety:

- `payments (idempotency_owner_hash, idempotency_key)` — a replayed checkout returns the original intent.
- `payments (provider, provider_transaction_id)` — a provider's transaction maps to at most one payment.

## The `Payable` contract

Anything payable implements `Contracts\Payments\Payable` and usually reuses
`Models\Concerns\IsPayable`. The trait derives balances from settled allocations
only, so a *pending* intent never makes a booking look paid — covered by
`test_a_pending_payment_never_reduces_the_outstanding_balance`.

`applySettledPayment()` runs inside the settlement transaction and **must be safe
to call twice**. `TourBooking` writes its `LoyaltyEligible` marker with
`firstOrCreate`, so a duplicate webhook cannot award points a second time.

Implemented by `TourBooking`, `CarHireBooking`, and `AirportTransferBooking`.
Imports and F15 invoices both adopt it.

`Support\Payments\PayableRegistry` maps URL segments to models **in both
directions**. The reverse lookup matters: the status page originally hardcoded
`tour-bookings` in its retry link, which would have 404'd for hire and transfer
customers. One map, one source of truth, covered by a test.

## Gateway seam

`Contracts\Payments\PaymentGateway` — `initiate`, `verify`,
`verifyWebhookSignature`, `parseWebhook`, `refund`. Adapters translate provider
vocabulary into shared enums and value objects, and **never write business
state**; the calling action does that in a transaction after verification.

| Adapter | State |
|---|---|
| `ManualGateway` (bank transfer, cash) | **Live.** No API. Shows instructions; an operator records receipt against evidence. It cannot settle anything itself — that is the point. |
| `FakeGateway` | **Live, test-only.** Scriptable success/pending/failure/cancellation/duplicate/retry, so no test needs credentials or network. |
| MTN MoMo, Airtel, Stripe, PayPal, PesaPal, Flutterwave | **Not written.** An enabled provider with no adapter throws at resolution rather than accepting money it cannot collect. |

Cash is staff-only (`isCustomerSelectable()` is false) — a customer selecting it
would be recording a fiction. Providers also declare
`supportedCurrencies()`: Ugandan mobile money is UGX-only, so offering it for a
USD booking is refused rather than failing later at the provider.

## Webhook handling — order is the security

1. **Verify signature.** An unverified payload is audited and rejected *before
   parsing*, so hostile input never reaches a parser.
2. **Record under the unique event key.** A replay collides at the database, not
   at an application check that could race.
3. **Then** resolve the payment and change state, after checking that the
   provider matches and the amount and currency match the intent.

A failure during step 3 keeps the event row with its error and returns 200, so
the provider stops retrying while the delivery stays visible for operator retry.
Only a bad signature returns 4xx, so a misconfigured provider surfaces loudly.

Payloads are redacted before storage — card fragments, tokens, phone numbers,
emails, account numbers.

## Refunds

Manager and super administrator only. **Staff are deliberately excluded**, per
the role matrix.

- Partial and full, both idempotency-keyed.
- Headroom counts completed *and* in-flight refunds, so an async provider refund
  holds its claim and cannot be double-issued.
- A **failed** provider refund releases its claim and leaves revenue untouched.
- A manual-provider refund stays `Processing` until finance confirms the money
  actually left.

## Currency

`ExchangeRateResolver` converts through integer parts-per-million, handling the
UGX (exponent 0) / USD (exponent 2) scale difference explicitly, with a single
half-up rounding step at the end. An unconfigured pair raises rather than
silently assuming 1:1 — which for UGX/USD would misprice by ~3,800×.

```
PISFA_BASE_CURRENCY=UGX
PISFA_RATE_USD_UGX=3800000000     # 3,800 UGX per USD, in parts per million
PISFA_PAYMENT_EXPIRY_MINUTES=60
PISFA_WEBHOOK_TOLERANCE_SECONDS=300
PISFA_WEBHOOK_RETENTION_DAYS=180
```

Provider credentials use the names fixed in `docs/INTEGRATIONS.md`. Nothing is
enabled by default.

## Tests — 74

`PaymentLifecycleTest` (15) · `PaymentWebhookTest` (12) · `RefundTest` (12) ·
`CheckoutHttpTest` (15) · `AdminPaymentConsoleTest` (14) ·
`PortalPaymentEntryPointTest` (6)

Covering the paths the brief names explicitly — success, pending, failure,
cancellation, duplicate callback, retry — plus: amount and currency mismatch
refused, a late failure never downgrading a settled payment, an event from the
wrong provider refused, PII redaction, cross-customer isolation, cash hidden
from customers, an unregistered provider failing loudly, and the rate stamp
surviving a config change.

## Operations console

`admin.payments.*`, staff and above behind the mandatory-2FA policy.

- **Index** — search by reference, provider transaction id, or customer; filter
  by status, method, currency, date range; and four buckets including
  **unreconciled** (settled but never credited to a service), which is the queue
  an operator works through.
- **Reconciliation totals** — collected, refunded, and net in the reporting base
  currency. Refunds are grouped by `(currency, rate)` and converted **in PHP**
  through the resolver, not in SQL: multiplying by the rate in SQL would skip
  the UGX/USD exponent difference and silently misstate the figure.
- **Record receipt** — bank transfer and cash only, requiring a bank-slip
  reference and an explicit confirmation that funds arrived. It routes through
  the same `SettlePayment` action as a webhook, so allocation and idempotency
  behave identically however money is confirmed. A card payment cannot be
  recorded by hand (404).
- **Refund** — manager and above. Staff see an explanation instead of the form,
  and the endpoint refuses them.

## Customer entry points

`<x-pay-now>` renders on the tour, car-hire, and transfer portal screens and has
exactly four states, all server-derived: **Pay now** with the amount due, *a
payment is already in progress* (linking to its status, so a customer cannot
start a second), **Paid in full**, or *not accepting payment*. No fake-success
state and no dead button.

## Outstanding

- **Six remote adapters** and their real signature schemes. Sandbox credentials
  needed for staging.
- Receipt PDFs (F27 renderer exists, template does not).
- Scheduled intent expiry — `Payment::scopeExpirable` exists; no command yet.
- Payment notifications (F18).
- Remaining payables: none. Tour bookings, car hire, transfers, imports, and
  F15 invoices all implement `Payable` and are reachable through
  `PayableRegistry`.
- **No MySQL concurrency run yet.** Every lock guarantee here is currently
  proven only on SQLite, which cannot exercise `lockForUpdate`. CI runs MariaDB
  but has not been executed against this code.
