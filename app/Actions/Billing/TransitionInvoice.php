<?php

namespace App\Actions\Billing;

use App\Enums\DocumentCategory;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Billing\InvoiceIssuedNotification;
use App\Services\AuditLogger;
use App\Services\Documents\PdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Issues, cancels, and voids invoices.
 *
 * Payment progress is deliberately absent from this class: PartiallyPaid and
 * Paid are reached only by Invoice::applySettledPayment inside the F14
 * settlement transaction, so a status can never claim money that has not
 * actually arrived.
 */
class TransitionInvoice
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * Publishes the invoice and starts the clock. The PDF is filed after the
     * status flips but before the customer is told, so the link in the email
     * always resolves to a document.
     */
    public function issue(User $actor, Invoice $invoice): Invoice
    {
        // Whether this call is the one that actually issued. A second issue must
        // not file another PDF or email the customer again.
        $issued = false;

        $invoice = DB::transaction(function () use ($actor, $invoice, &$issued): Invoice {
            $lockedActor = $this->lockedManager($actor);

            $locked = Invoice::query()
                ->with('items')
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== InvoiceStatus::Draft) {
                // Re-issuing a live invoice is a no-op rather than an error, so
                // a double submit does not alarm the operator. A cancelled or
                // voided one falls through and is refused: it is closed, not
                // merely already done.
                if ($locked->status->hasBeenIssued()) {
                    return $locked;
                }

                $this->assertTransition($locked, InvoiceStatus::Issued);
            }

            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'An invoice must have at least one line before it is issued.',
                ]);
            }

            if ($locked->total_minor < 1) {
                throw ValidationException::withMessages([
                    'items' => 'An invoice must total more than zero before it is issued.',
                ]);
            }

            $termsDays = (int) config('billing.invoices.default_payment_terms_days', 14);
            $issuedOn = now();

            $locked->forceFill([
                'status' => InvoiceStatus::Issued,
                'issued_on' => $issuedOn->toDateString(),
                'issued_at' => $issuedOn,
                'due_on' => $locked->due_on?->toDateString()
                    ?? $issuedOn->copy()->addDays($termsDays)->toDateString(),
            ])->save();

            $this->auditLogger->record(
                event: 'invoice.issued',
                auditable: $locked,
                oldValues: ['status' => InvoiceStatus::Draft->value],
                newValues: [
                    'status' => InvoiceStatus::Issued->value,
                    'total_minor' => $locked->total_minor,
                    'currency' => $locked->currency,
                    'due_on' => $locked->due_on?->toDateString(),
                ],
                user: $lockedActor,
            );

            $issued = true;

            return $locked;
        }, 3);

        if (! $issued) {
            return $invoice->fresh(['items', 'customer']);
        }

        $this->fileDocument($actor, $invoice);

        DB::afterCommit(fn () => $this->notifyRecipient($invoice));

        return $invoice->fresh(['items', 'customer']);
    }

    /** Withdraws an invoice before any money has arrived. */
    public function cancel(User $actor, Invoice $invoice, string $reason): Invoice
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $invoice, $reason): Invoice {
            $lockedActor = $this->lockedManager($actor);

            $locked = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTransition($locked, InvoiceStatus::Cancelled);

            // The transition graph already forbids cancelling a paid invoice,
            // but a part-paid one reaches here through Issued, so re-check the
            // money rather than trusting the label.
            if ($locked->settledAmountMinor() > 0) {
                throw ValidationException::withMessages([
                    'status' => 'Money has already been received against this invoice. Void it instead.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => InvoiceStatus::Cancelled,
                'cancelled_at' => now(),
                'closure_reason' => $reason,
            ])->save();

            $this->auditLogger->record(
                event: 'invoice.cancelled',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => InvoiceStatus::Cancelled->value],
                user: $lockedActor,
            );

            return $locked->fresh(['items', 'customer']);
        }, 3);
    }

    /**
     * Voids an invoice that has already been issued, possibly after payment.
     *
     * This is a correction to money already recognised, so it is restricted to
     * managers and above and always records who did it and why.
     */
    public function void(User $actor, Invoice $invoice, string $reason): Invoice
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $invoice, $reason): Invoice {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            BillingAccess::assertCanWriteOff($lockedActor);

            $locked = Invoice::query()
                ->whereKey($invoice->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTransition($locked, InvoiceStatus::Void);

            $previous = $locked->status;
            $settled = $locked->settledAmountMinor();

            $locked->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'closure_reason' => $reason,
            ])->save();

            $this->auditLogger->record(
                event: 'invoice.voided',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: [
                    'status' => InvoiceStatus::Void->value,
                    'total_minor' => $locked->total_minor,
                    // Recorded so a reconciliation can find money that was
                    // received against a document later voided.
                    'settled_minor' => $settled,
                    'currency' => $locked->currency,
                ],
                user: $lockedActor,
            );

            return $locked->fresh(['items', 'customer']);
        }, 3);
    }

    private function assertTransition(Invoice $invoice, InvoiceStatus $next): void
    {
        if (! $invoice->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$invoice->status->label()} invoice cannot become {$next->label()}.",
            ]);
        }
    }

    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $reason;
    }

    private function fileDocument(User $actor, Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        $this->pdf->store(
            actor: $actor,
            owner: $invoice,
            category: DocumentCategory::Invoice,
            view: 'pdf.invoice',
            data: ['invoice' => $invoice],
            metadata: [
                'number' => $invoice->number,
                'total_minor' => $invoice->total_minor,
                'currency' => $invoice->currency,
                'due_on' => $invoice->due_on?->toDateString(),
            ],
        );
    }

    private function notifyRecipient(Invoice $invoice): void
    {
        $notification = new InvoiceIssuedNotification(
            recipientName: $invoice->contact_name,
            number: $invoice->number,
            title: $invoice->title,
            total: $invoice->formattedTotal(),
            dueOn: $invoice->due_on?->toIso8601String(),
            viewUrl: $invoice->viewUrl(),
        );

        $invoice->loadMissing('customer');

        if ($invoice->customer !== null) {
            $invoice->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$invoice->contact_email => $invoice->contact_name])
            ->notify($notification);
    }

    private function lockedManager(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        BillingAccess::assertCanManage($locked);

        return $locked;
    }
}
