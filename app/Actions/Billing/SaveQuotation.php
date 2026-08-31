<?php

namespace App\Actions\Billing;

use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationItem;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Billing\DocumentNumberGenerator;
use App\Support\Billing\LineTotals;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates or rewrites a draft quotation and its line items.
 *
 * Editing is confined to Draft on purpose: once an offer has been sent, the
 * customer has seen those numbers, and silently changing them would make the
 * document they are holding a lie. Revising a sent offer goes through
 * TransitionQuotation, which pulls it back to Draft and bumps the revision.
 */
class SaveQuotation
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly DocumentNumberGenerator $numbers,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes, ?QuotationRequest $request = null): Quotation
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $input, $request): Quotation {
            $lockedActor = $this->lockedManager($actor);

            $lockedRequest = $request === null ? null : QuotationRequest::query()
                ->whereKey($request->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $quotation = new Quotation;
            $quotation->forceFill(array_merge($input['document'], [
                // Issued inside this transaction: if the write rolls back the
                // counter rolls back with it and the number is not burnt.
                'number' => $this->numbers->next((string) config('billing.numbering.quotation_prefix', 'QTN')),
                'tracking_token' => bin2hex(random_bytes(32)),
                'quotation_request_id' => $lockedRequest?->getKey(),
                'customer_id' => $lockedRequest?->customer_id,
                'created_by_user_id' => $lockedActor->getKey(),
                'status' => QuotationStatus::Draft,
                'revision' => 1,
            ]))->save();

            $this->writeItems($quotation, $input['items'], $input['totals']);

            // Pricing has started, so the enquiry is no longer merely new.
            if ($lockedRequest !== null
                && $lockedRequest->status === QuotationRequestStatus::New) {
                $lockedRequest->forceFill(['status' => QuotationRequestStatus::InReview])->save();
            }

            $this->auditLogger->record(
                event: 'quotation.created',
                auditable: $quotation,
                newValues: [
                    'number' => $quotation->number,
                    'total_minor' => $quotation->total_minor,
                    'currency' => $quotation->currency,
                    'items' => count($input['items']),
                ],
                user: $lockedActor,
            );

            return $quotation->fresh(['items', 'customer']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Quotation $quotation, array $attributes): Quotation
    {
        $input = $this->validated($attributes);

        return DB::transaction(function () use ($actor, $quotation, $input): Quotation {
            $lockedActor = $this->lockedManager($actor);

            $locked = Quotation::query()
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'A '.$locked->status->label().' quotation cannot be edited. Revise it first.',
                ]);
            }

            $previous = [
                'total_minor' => $locked->total_minor,
                'currency' => $locked->currency,
            ];

            $locked->forceFill($input['document'])->save();

            $this->writeItems($locked, $input['items'], $input['totals']);

            $this->auditLogger->record(
                event: 'quotation.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: [
                    'total_minor' => $locked->total_minor,
                    'currency' => $locked->currency,
                    'items' => count($input['items']),
                ],
                user: $lockedActor,
            );

            return $locked->fresh(['items', 'customer']);
        }, 3);
    }

    /**
     * Replaces the line set outright and restamps the header totals from it, so
     * the stored subtotal can never disagree with the lines beneath it.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $totals
     */
    private function writeItems(Quotation $quotation, array $items, array $totals): void
    {
        $quotation->items()->delete();

        foreach ($items as $index => $item) {
            QuotationItem::query()->create([
                'quotation_id' => $quotation->getKey(),
                'sort_order' => $index,
                'description' => $item['description'],
                'unit_label' => $item['unit_label'],
                'quantity' => $item['quantity'],
                'unit_price_minor' => $item['unit_price_minor'],
                'line_total_minor' => $totals['lines'][$index],
            ]);
        }

        $quotation->forceFill([
            'subtotal_minor' => $totals['subtotal_minor'],
            'discount_minor' => $totals['discount_minor'],
            'tax_amount_minor' => $totals['tax_amount_minor'],
            'total_minor' => $totals['total_minor'],
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{document: array<string, mixed>, items: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    private function validated(array $attributes): array
    {
        $maximumItems = (int) config('billing.quotations.maximum_items', 40);
        $maximumQuantity = (int) config('billing.quotations.maximum_quantity', 10_000);

        $validated = Validator::make($attributes, [
            'title' => ['required', 'string', 'min:3', 'max:180'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'company_name' => ['nullable', 'string', 'max:180'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'tax_rate_bps' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'discount' => ['nullable', 'string', 'max:24'],
            'deposit' => ['nullable', 'string', 'max:24'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['required', 'array', 'min:1', 'max:'.$maximumItems],
            'items.*.description' => ['required', 'string', 'min:2', 'max:255'],
            'items.*.unit_label' => ['nullable', 'string', 'max:24'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:'.$maximumQuantity],
            'items.*.unit_price' => ['required', 'string', 'max:24'],
        ])->validate();

        $currency = strtoupper((string) $validated['currency']);
        $items = [];

        foreach (array_values($validated['items']) as $index => $item) {
            try {
                $unitPrice = Money::parse((string) $item['unit_price'], $currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_price" => $exception->getMessage(),
                ]);
            }

            $items[] = [
                'description' => trim((string) $item['description']),
                'unit_label' => $this->nullable($item['unit_label'] ?? null),
                'quantity' => (int) $item['quantity'],
                'unit_price_minor' => $unitPrice,
            ];
        }

        $discountMinor = 0;

        if (filled($validated['discount'] ?? null)) {
            try {
                $discountMinor = Money::parse((string) $validated['discount'], $currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['discount' => $exception->getMessage()]);
            }
        }

        $taxRateBps = (int) ($validated['tax_rate_bps'] ?? config('billing.tax.default_rate_bps', 0));

        try {
            $totals = LineTotals::compute($items, $discountMinor, $taxRateBps);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['items' => $exception->getMessage()]);
        }

        // Silently capping a discount larger than the subtotal would hide a
        // typo that changes what the customer is charged.
        if ($discountMinor > $totals['subtotal_minor']) {
            throw ValidationException::withMessages([
                'discount' => 'The discount cannot exceed the subtotal of '
                    .Money::format($totals['subtotal_minor'], $currency).'.',
            ]);
        }

        $depositMinor = null;

        if (filled($validated['deposit'] ?? null)) {
            try {
                $depositMinor = Money::parse((string) $validated['deposit'], $currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['deposit' => $exception->getMessage()]);
            }

            // A deposit at or above the total is not a deposit, and a zero one
            // is not a stage. Either would leave the invoice unable to collect.
            if ($depositMinor < 1 || $depositMinor >= $totals['total_minor']) {
                throw ValidationException::withMessages([
                    'deposit' => 'The deposit must be more than zero and less than the total of '
                        .Money::format($totals['total_minor'], $currency).'.',
                ]);
            }
        }

        return [
            'document' => [
                'title' => trim((string) $validated['title']),
                'currency' => $currency,
                'contact_name' => trim((string) $validated['contact_name']),
                'contact_email' => mb_strtolower(trim((string) $validated['contact_email'])),
                'contact_phone' => preg_replace('/[\s().-]+/', '', trim((string) $validated['contact_phone'])) ?? '',
                'company_name' => $this->nullable($validated['company_name'] ?? null),
                'valid_until' => $validated['valid_until'] ?? null,
                'tax_rate_bps' => $taxRateBps,
                'deposit_minor' => $depositMinor,
                'terms' => $this->nullable($validated['terms'] ?? null),
                'notes' => $this->nullable($validated['notes'] ?? null),
                'internal_notes' => $this->nullable($validated['internal_notes'] ?? null),
            ],
            'items' => $items,
            'totals' => $totals,
        ];
    }

    private function lockedManager(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        BillingAccess::assertCanManage($locked);

        return $locked;
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
