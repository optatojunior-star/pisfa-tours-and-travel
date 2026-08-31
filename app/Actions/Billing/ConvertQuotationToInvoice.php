<?php

namespace App\Actions\Billing;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationRequestStatus;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Billing\DocumentNumberGenerator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Turns an accepted quotation into a draft invoice.
 *
 * Every figure and every line is copied, not referenced: revising the quotation
 * afterwards must not restate an invoice, and an invoice that has been issued or
 * paid must be able to survive the quotation being edited or deleted.
 *
 * `invoices.quotation_id` carries a unique index, so a double click produces one
 * invoice by construction rather than by an application check that could race.
 */
class ConvertQuotationToInvoice
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    public function execute(User $actor, Quotation $quotation): Invoice
    {
        return DB::transaction(function () use ($actor, $quotation): Invoice {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            BillingAccess::assertCanManage($lockedActor);

            $locked = Quotation::query()
                ->with('items')
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $existing = Invoice::query()->where('quotation_id', $locked->getKey())->first();

            // Converting twice is a no-op, not an error: a double submit should
            // land the operator on the invoice that already exists.
            if ($existing !== null) {
                return $existing;
            }

            if (! $locked->status->isConvertible()) {
                throw ValidationException::withMessages([
                    'status' => 'Only an accepted quotation can become an invoice. This one is '
                        .mb_strtolower($locked->status->label()).'.',
                ]);
            }

            $termsDays = (int) config('billing.invoices.default_payment_terms_days', 14);

            $invoice = new Invoice;

            try {
                $invoice->forceFill([
                    'number' => $this->numbers->next((string) config('billing.numbering.invoice_prefix', 'INV')),
                    'tracking_token' => bin2hex(random_bytes(32)),
                    'quotation_id' => $locked->getKey(),
                    'customer_id' => $locked->customer_id,
                    'created_by_user_id' => $lockedActor->getKey(),
                    'status' => InvoiceStatus::Draft,
                    'contact_name' => $locked->contact_name,
                    'contact_email' => $locked->contact_email,
                    'contact_phone' => $locked->contact_phone,
                    'company_name' => $locked->company_name,
                    'title' => $locked->title,
                    'currency' => $locked->currency,
                    'subtotal_minor' => $locked->subtotal_minor,
                    'discount_minor' => $locked->discount_minor,
                    'tax_rate_bps' => $locked->tax_rate_bps,
                    'tax_amount_minor' => $locked->tax_amount_minor,
                    'total_minor' => $locked->total_minor,
                    'deposit_minor' => $locked->deposit_minor,
                    'due_on' => now()->addDays($termsDays)->toDateString(),
                    'terms' => $locked->terms,
                    'notes' => $locked->notes,
                ])->save();
            } catch (QueryException) {
                // The unique index caught a concurrent conversion. Whoever won
                // has written the invoice; return theirs.
                return Invoice::query()->where('quotation_id', $locked->getKey())->firstOrFail();
            }

            foreach ($locked->items as $item) {
                InvoiceItem::query()->create([
                    'invoice_id' => $invoice->getKey(),
                    'sort_order' => $item->sort_order,
                    'description' => $item->description,
                    'unit_label' => $item->unit_label,
                    'quantity' => $item->quantity,
                    'unit_price_minor' => $item->unit_price_minor,
                    'line_total_minor' => $item->line_total_minor,
                ]);
            }

            $this->closeRequest($locked);

            $this->auditLogger->record(
                event: 'invoice.created_from_quotation',
                auditable: $invoice,
                newValues: [
                    'number' => $invoice->number,
                    'quotation_number' => $locked->number,
                    'total_minor' => $invoice->total_minor,
                    'currency' => $invoice->currency,
                    'items' => $locked->items->count(),
                ],
                user: $lockedActor,
            );

            return $invoice->fresh(['items', 'customer', 'quotation']);
        }, 3);
    }

    private function closeRequest(Quotation $quotation): void
    {
        if ($quotation->quotation_request_id === null) {
            return;
        }

        $request = QuotationRequest::query()
            ->whereKey($quotation->quotation_request_id)
            ->lockForUpdate()
            ->first();

        if ($request !== null && $request->canTransitionTo(QuotationRequestStatus::Closed)) {
            $request->forceFill([
                'status' => QuotationRequestStatus::Closed,
                'closed_at' => now(),
                'closure_reason' => 'Quotation accepted and invoiced.',
            ])->save();
        }
    }
}
