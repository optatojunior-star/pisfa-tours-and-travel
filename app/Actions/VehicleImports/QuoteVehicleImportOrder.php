<?php

namespace App\Actions\VehicleImports;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\VehicleImportEventType;
use App\Enums\VehicleImportStatus;
use App\Models\User;
use App\Models\VehicleImportOrder;
use App\Notifications\VehicleImports\VehicleImportQuotedNotification;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Publishes or replaces the quotation for an import.
 *
 * A quote can be revised while it is unpaid. Once the deposit has settled the
 * price is fixed: re-quoting then would change what a customer already paid
 * against, so it is refused.
 */
class QuoteVehicleImportOrder
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(User $actor, VehicleImportOrder $order, array $attributes): VehicleImportOrder
    {
        $this->ensureOperations($actor);

        $validated = Validator::make($attributes, [
            'total_price' => ['required', 'string', 'max:24', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'deposit' => ['required', 'string', 'max:24', 'regex:/\A(?:0|[1-9][0-9]*)(?:\.[0-9]{1,2})?\z/'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'estimated_arrival_on' => ['required', 'date_format:Y-m-d'],
            'valid_for_days' => ['sometimes', 'integer', 'min:1', 'max:90'],
        ])->validate();

        $currency = strtoupper($validated['currency']);
        $total = Money::parse($validated['total_price'], $currency);
        $deposit = Money::parse($validated['deposit'], $currency);

        if ($total < 1) {
            throw ValidationException::withMessages([
                'total_price' => 'Enter a total price greater than zero.',
            ]);
        }

        if ($deposit < 1) {
            throw ValidationException::withMessages([
                'deposit' => 'Enter a deposit greater than zero.',
            ]);
        }

        if ($deposit > $total) {
            throw ValidationException::withMessages([
                'deposit' => 'The deposit cannot exceed the total price.',
            ]);
        }

        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        // createFromFormat returns null (not false) on failure in this Carbon
        // version, so the guard must test for null or an unparseable date would
        // slip through and be dereferenced below.
        $arrival = CarbonImmutable::createFromFormat('!Y-m-d', $validated['estimated_arrival_on'], $timezone);

        if ($arrival === null || $arrival->isBefore(CarbonImmutable::now($timezone)->startOfDay())) {
            throw ValidationException::withMessages([
                'estimated_arrival_on' => 'The estimated arrival date must be today or later.',
            ]);
        }

        $validForDays = (int) ($validated['valid_for_days']
            ?? config('vehicle_imports.quote_validity_days', 14));

        return DB::transaction(function () use (
            $actor, $order, $total, $deposit, $currency, $arrival, $validForDays
        ): VehicleImportOrder {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperations($lockedActor);

            $locked = VehicleImportOrder::query()
                ->with('customer')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === VehicleImportStatus::Cancelled) {
                throw ValidationException::withMessages([
                    'status' => 'A cancelled import cannot be quoted.',
                ]);
            }

            // Once money has been taken against a price, that price is settled.
            if ($locked->depositIsSettled()) {
                throw ValidationException::withMessages([
                    'total_price' => 'The deposit has already been paid against the current quotation. '
                        .'Raise a new import request to change the price.',
                ]);
            }

            $previous = [
                'total_price_minor' => $locked->total_price_minor,
                'deposit_minor' => $locked->deposit_minor,
                'quote_currency' => $locked->quote_currency,
            ];

            $now = now();
            $locked->forceFill([
                'total_price_minor' => $total,
                'deposit_minor' => $deposit,
                'quote_currency' => $currency,
                'quoted_at' => $now,
                'quote_expires_at' => $now->copy()->addDays($validForDays),
                'estimated_arrival_on' => $arrival->toDateString(),
            ])->save();

            $locked->recordEvent(
                VehicleImportEventType::Quoted,
                'Quotation issued: '.Money::format($total, $currency)
                    .' with a '.Money::format($deposit, $currency).' deposit.',
                [
                    'total_minor' => $total,
                    'deposit_minor' => $deposit,
                    'currency' => $currency,
                    'expires_at' => $locked->quote_expires_at?->toIso8601String(),
                ],
                $lockedActor,
            );

            $this->auditLogger->record(
                event: 'vehicle_import.quoted',
                auditable: $locked,
                oldValues: $previous,
                newValues: [
                    'total_price_minor' => $total,
                    'deposit_minor' => $deposit,
                    'quote_currency' => $currency,
                    'estimated_arrival_on' => $arrival->toDateString(),
                ],
                user: $lockedActor,
            );

            DB::afterCommit(fn () => $this->notify($locked));

            return $locked->fresh(['customer', 'events']);
        }, 3);
    }

    private function ensureOperations(User $actor): void
    {
        if ($actor->status !== AccountStatus::Active || ! $actor->hasAnyRole(
            UserRole::Staff,
            UserRole::Manager,
            UserRole::SuperAdmin,
        )) {
            throw new AuthorizationException;
        }
    }

    private function notify(VehicleImportOrder $order): void
    {
        $notification = new VehicleImportQuotedNotification(
            recipientName: $order->contact_name,
            reference: $order->reference,
            vehicleSummary: $order->vehicleSummary(),
            totalPrice: Money::format((int) $order->total_price_minor, (string) $order->quote_currency),
            deposit: Money::format((int) $order->deposit_minor, (string) $order->quote_currency),
            expiresAt: $order->quote_expires_at?->toIso8601String(),
            trackingUrl: $order->paymentReturnUrl(),
        );

        if ($order->customer !== null) {
            $order->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$order->contact_email => $order->contact_name])
            ->notify($notification);
    }
}
