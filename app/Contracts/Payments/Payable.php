<?php

namespace App\Contracts\Payments;

use App\Models\Payment;
use App\Models\User;

/**
 * Implemented by anything a customer can pay for: tour bookings, car hire,
 * airport transfers, vehicle imports, invoices.
 *
 * The amount always comes from the record, never from the request. A browser
 * cannot propose what it owes.
 */
interface Payable
{
    /** Human-facing reference shown on checkout and receipts. */
    public function paymentReference(): string;

    /** Short description of what is being paid for. */
    public function paymentDescription(): string;

    /** Full price of the service in minor units. */
    public function payableAmountMinor(): int;

    public function payableCurrency(): string;

    /**
     * Still outstanding after settled allocations, in minor units.
     *
     * Returning zero means nothing is due and checkout must refuse, which is
     * what prevents a customer paying twice for the same booking.
     */
    public function outstandingAmountMinor(): int;

    /** The account that owes this, or null for a guest record. */
    public function payer(): ?User;

    /**
     * Whether the record is in a state that accepts payment at all. A cancelled
     * or expired booking is not payable regardless of its balance.
     */
    public function acceptsPayment(): bool;

    /**
     * Called inside the settlement transaction once a payment is confirmed and
     * allocated. Implementations update their own payment-status field and may
     * record a durable event; they must be safe to call twice.
     */
    public function applySettledPayment(Payment $payment): void;

    /** Where to send the customer after checkout completes. */
    public function paymentReturnUrl(): string;
}
