<?php

namespace App\Actions\VehicleImports;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\VehicleImportEventType;
use App\Enums\VehicleImportStatus;
use App\Models\User;
use App\Models\VehicleImportOrder;
use App\Notifications\VehicleImports\VehicleImportStatusNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves an import through its lifecycle.
 *
 * Two stages are gated on money actually having arrived rather than on an
 * operator's say-so, because those are the points where PISFA commits its own
 * funds or releases the vehicle.
 */
class TransitionVehicleImportOrder
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        VehicleImportOrder $order,
        VehicleImportStatus $next,
        ?string $reason = null,
        bool $notifyCustomer = true,
    ): VehicleImportOrder {
        $reason = $reason === null ? null : trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        $this->ensureOperations($actor);

        return DB::transaction(function () use ($actor, $order, $next, $reason, $notifyCustomer): VehicleImportOrder {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperations($lockedActor);

            $locked = VehicleImportOrder::query()
                ->with('customer')
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->label()} import cannot move to {$next->label()}.",
                ]);
            }

            $this->assertPreconditions($locked, $next, $reason);

            $previous = $locked->status;
            $now = now();
            $changes = ['status' => $next];

            if ($next === VehicleImportStatus::Delivered) {
                $changes['delivered_at'] = $now;
            }

            if ($next === VehicleImportStatus::Cancelled) {
                $changes['cancelled_at'] = $now;
                $changes['cancellation_reason'] = $reason;
            }

            $locked->forceFill($changes)->save();

            $locked->recordEvent(
                VehicleImportEventType::StatusChanged,
                $next->label().' — '.$next->customerDescription(),
                ['from' => $previous->value, 'to' => $next->value],
                $lockedActor,
            );

            $this->auditLogger->record(
                event: 'vehicle_import.status_changed',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value, 'transitioned_at' => $now->toIso8601String()],
                context: ['reason_present' => $reason !== null],
                user: $lockedActor,
            );

            if ($notifyCustomer) {
                DB::afterCommit(fn () => $this->notify($locked, $next, $reason));
            }

            return $locked->fresh(['customer', 'assignee', 'events']);
        }, 3);
    }

    private function assertPreconditions(
        VehicleImportOrder $order,
        VehicleImportStatus $next,
        ?string $reason,
    ): void {
        if ($next === VehicleImportStatus::Cancelled && $reason === null) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a reason for cancelling this import.',
            ]);
        }

        if ($next === VehicleImportStatus::Quoted && ! $order->hasQuote()) {
            throw ValidationException::withMessages([
                'status' => 'Publish a quotation before moving this import to Quoted.',
            ]);
        }

        // Sourcing costs PISFA money, so it does not begin on an operator's
        // word — the deposit must actually have settled.
        if ($next === VehicleImportStatus::DepositPaid && ! $order->depositIsSettled()) {
            throw ValidationException::withMessages([
                'status' => 'The deposit has not been received yet.',
            ]);
        }

        // The vehicle is not released until the full price is settled.
        if ($next === VehicleImportStatus::Delivered
            && $order->settledAmountMinor() < (int) $order->total_price_minor) {
            throw ValidationException::withMessages([
                'status' => 'The balance must be settled in full before delivery.',
            ]);
        }
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

    private function notify(
        VehicleImportOrder $order,
        VehicleImportStatus $status,
        ?string $reason,
    ): void {
        $notification = new VehicleImportStatusNotification(
            recipientName: $order->contact_name,
            reference: $order->reference,
            vehicleSummary: $order->vehicleSummary(),
            statusLabel: $status->label(),
            statusDescription: $status->customerDescription(),
            trackingUrl: $order->paymentReturnUrl(),
            reason: $reason,
        );

        if ($order->customer !== null) {
            $order->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$order->contact_email => $order->contact_name])
            ->notify($notification);
    }
}
