<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingEventType;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use App\Models\CarHireBookingEvent;
use App\Models\CarHireContract;
use App\Models\CarHireDriverAssignment;
use App\Models\CarHireSelfDriveApplication;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleHireRate;
use App\Notifications\CarHire\CarHireBookingConfirmedNotification;
use App\Notifications\CarHire\CarHireDriverAssignmentNotification;
use App\Services\AuditLogger;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransitionCarHireBooking
{
    use InteractsWithCarHireDomain;

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CancelCarHireBooking $cancelBooking,
    ) {}

    public function execute(
        User $actor,
        CarHireBooking $booking,
        CarHireBookingStatus $nextStatus,
        ?string $reason = null,
    ): CarHireBooking {
        if ($nextStatus === CarHireBookingStatus::Cancelled) {
            return $this->cancelBooking->execute($actor, $booking, $reason);
        }

        $reason = $this->nullableString($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        $this->ensureOperationsActor($actor);

        return DB::transaction(function () use ($actor, $booking, $nextStatus, $reason): CarHireBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $vehicle = Vehicle::query()
                ->whereKey($booking->vehicle_id)
                ->lockForUpdate()
                ->firstOrFail();
            $rate = VehicleHireRate::query()
                ->whereKey($booking->vehicle_hire_rate_id)
                ->lockForUpdate()
                ->firstOrFail();

            $lockedBooking = CarHireBooking::query()
                ->with(['customer', 'assignedDriver'])
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->vehicle_id !== $vehicle->getKey()) {
                $this->invalid('vehicle', 'The booking vehicle changed while the request was being processed.');
            }

            if ($lockedBooking->vehicle_hire_rate_id !== $rate->getKey()) {
                $this->invalid('rate', 'The booking rate changed while the request was being processed.');
            }

            if ($lockedBooking->status === $nextStatus) {
                return $lockedBooking;
            }

            if (! $lockedBooking->canTransitionTo($nextStatus)) {
                $this->invalid(
                    'status',
                    "A {$lockedBooking->status->label()} booking cannot move to {$nextStatus->label()}.",
                );
            }

            $now = now();

            if ($nextStatus === CarHireBookingStatus::Confirmed) {
                $this->assertCanConfirm($lockedBooking, $vehicle, $rate, $now);
            } elseif ($nextStatus === CarHireBookingStatus::InProgress) {
                $this->assertCanStart($lockedBooking, $vehicle, $now);
            } elseif ($nextStatus === CarHireBookingStatus::Completed) {
                if ($lockedBooking->return_at->isAfter($now)) {
                    $this->invalid('status', 'This hire cannot be completed before its scheduled return time.');
                }
            } elseif ($nextStatus === CarHireBookingStatus::Declined) {
                if ($reason === null) {
                    $this->invalid('reason', 'Enter a reason for declining this booking.');
                }
            } elseif ($nextStatus === CarHireBookingStatus::Expired
                && $lockedBooking->hold_expires_at->isAfter($now)) {
                $this->invalid('status', 'This pending booking hold has not expired yet.');
            }

            $oldStatus = $lockedBooking->status;
            $oldDriver = $lockedBooking->assignedDriver;
            $releasedAssignments = collect();
            $voidedContracts = collect();
            $changes = ['status' => $nextStatus];

            if ($nextStatus === CarHireBookingStatus::Confirmed) {
                $changes['confirmed_at'] = $now;
            } elseif ($nextStatus === CarHireBookingStatus::InProgress) {
                $changes['in_progress_at'] = $now;
            } elseif ($nextStatus === CarHireBookingStatus::Completed) {
                $changes['completed_at'] = $now;
                $changes['assigned_driver_user_id'] = null;
                $releasedAssignments = $this->releaseAssignments(
                    $lockedBooking,
                    $lockedActor,
                    $now,
                    'Hire completed.',
                );
            } elseif (in_array($nextStatus, [
                CarHireBookingStatus::Declined,
                CarHireBookingStatus::Expired,
            ], true)) {
                $terminalReason = $reason ?? 'Pending booking hold expired.';
                $changes['cancellation_reason'] = $terminalReason;
                $changes['assigned_driver_user_id'] = null;
                $releasedAssignments = $this->releaseAssignments(
                    $lockedBooking,
                    $lockedActor,
                    $now,
                    $terminalReason,
                );
                $voidedContracts = $this->voidContracts(
                    $lockedBooking,
                    $lockedActor,
                    $now,
                    $terminalReason,
                );
            }

            $lockedBooking->forceFill($changes)->save();

            if ($nextStatus === CarHireBookingStatus::Expired) {
                CarHireBookingEvent::query()->firstOrCreate(
                    [
                        'car_hire_booking_id' => $lockedBooking->getKey(),
                        'event_type' => CarHireBookingEventType::BookingExpired->value,
                    ],
                    [
                        'payload' => [
                            'schema_version' => 1,
                            'booking_id' => $lockedBooking->getKey(),
                            'booking_reference' => $lockedBooking->reference,
                            'hold_expires_at' => $lockedBooking->hold_expires_at->toIso8601String(),
                            'expired_at' => $now->toIso8601String(),
                        ],
                        'processed_at' => $now,
                    ],
                );
            }

            if ($nextStatus === CarHireBookingStatus::Completed) {
                CarHireBookingEvent::query()->firstOrCreate(
                    [
                        'car_hire_booking_id' => $lockedBooking->getKey(),
                        'event_type' => CarHireBookingEventType::LoyaltyEligible->value,
                    ],
                    [
                        'payload' => [
                            'schema_version' => 1,
                            'booking_id' => $lockedBooking->getKey(),
                            'booking_reference' => $lockedBooking->reference,
                            'customer_id' => $lockedBooking->customer_id,
                            'total_minor' => $lockedBooking->total_minor,
                            'currency' => $lockedBooking->currency,
                            'completed_at' => $now->toIso8601String(),
                        ],
                        'processed_at' => null,
                    ],
                );
            }

            $this->auditLogger->record(
                event: 'car_hire_booking.status_changed',
                auditable: $lockedBooking,
                oldValues: [
                    'status' => $oldStatus->value,
                    'assigned_driver_user_id' => $oldDriver?->getKey(),
                ],
                newValues: [
                    'status' => $nextStatus->value,
                    'transitioned_at' => $now->toIso8601String(),
                    'assigned_driver_user_id' => $lockedBooking->assigned_driver_user_id,
                    'released_assignments' => $releasedAssignments->count(),
                    'voided_contracts' => $voidedContracts->count(),
                    'loyalty_event_recorded' => $nextStatus === CarHireBookingStatus::Completed,
                ],
                context: ['reason' => $reason],
                user: $lockedActor,
            );

            if ($nextStatus === CarHireBookingStatus::Confirmed) {
                $customer = $lockedBooking->customer;

                DB::afterCommit(function () use ($customer, $lockedBooking): void {
                    $customer->notify(new CarHireBookingConfirmedNotification(
                        bookingReference: $lockedBooking->reference,
                        vehicleName: $lockedBooking->vehicle_name_snapshot,
                        pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                        totalMinor: $lockedBooking->total_minor,
                        currency: $lockedBooking->currency,
                    ));
                });
            }

            if (in_array($nextStatus, [
                CarHireBookingStatus::Completed,
                CarHireBookingStatus::Declined,
                CarHireBookingStatus::Expired,
            ], true) && $oldDriver !== null) {
                $releaseReason = match ($nextStatus) {
                    CarHireBookingStatus::Completed => 'Hire completed.',
                    CarHireBookingStatus::Declined => $reason ?? 'Booking declined.',
                    CarHireBookingStatus::Expired => 'Pending booking hold expired.',
                    default => 'Assignment closed.',
                };

                DB::afterCommit(function () use ($oldDriver, $lockedBooking, $releaseReason): void {
                    $oldDriver->notify(new CarHireDriverAssignmentNotification(
                        bookingReference: $lockedBooking->reference,
                        vehicleName: $lockedBooking->vehicle_name_snapshot,
                        pickupAt: $lockedBooking->pickup_at->toIso8601String(),
                        assigned: false,
                        forDriver: true,
                        reason: $releaseReason,
                    ));
                });
            }

            return $lockedBooking->fresh([
                'customer',
                'vehicle.coverMedia',
                'selfDriveApplication',
                'contracts',
                'assignedDriver',
                'driverAssignments',
                'events',
            ]);
        }, 3);
    }

    private function assertCanConfirm(
        CarHireBooking $booking,
        Vehicle $vehicle,
        VehicleHireRate $rate,
        DateTimeInterface $now,
    ): void {
        if (! $booking->hold_expires_at->isAfter($now)) {
            $this->invalid('status', 'This pending booking hold has expired.');
        }

        if (! $booking->pickup_at->isAfter($now)) {
            $this->invalid('status', 'Only a future vehicle pickup can be confirmed.');
        }

        if ($vehicle->operational_status !== VehicleOperationalStatus::Available) {
            $this->invalid('vehicle', 'This vehicle is not operationally available.');
        }

        if ($rate->vehicle_id !== $booking->vehicle_id
            || ! $rate->is_active
            || $rate->currency !== $booking->currency
            || $rate->effective_from->isAfter($booking->pickup_at)
            || $rate->rateFor($booking->hire_mode) !== $booking->daily_rate_minor
            || $rate->security_deposit_minor !== $booking->security_deposit_minor) {
            $this->invalid('rate', 'The selected rate is no longer eligible or does not match the booking snapshot.');
        }

        $hasConflict = CarHireBooking::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->where('id', '!=', $booking->getKey())
            ->holdingVehicle($now)
            ->overlapping($booking->pickup_at, $booking->return_at)
            ->exists();

        if ($hasConflict) {
            $this->invalid('vehicle', 'This vehicle has another active booking for the selected interval.');
        }

        $this->assertAcceptedContract($booking);

        if ($booking->hire_mode === HireMode::SelfDrive) {
            $this->assertApprovedSelfDriveApplication($booking, false);
        }
    }

    private function assertCanStart(CarHireBooking $booking, Vehicle $vehicle, DateTimeInterface $now): void
    {
        if ($booking->pickup_at->isAfter($now)) {
            $this->invalid('status', 'This hire cannot start before its scheduled pickup time.');
        }

        if (! $booking->return_at->isAfter($now)) {
            $this->invalid('status', 'This hire interval has already ended.');
        }

        if ($vehicle->operational_status !== VehicleOperationalStatus::Available) {
            $this->invalid('vehicle', 'This vehicle is not operationally available for handover.');
        }

        $this->assertAcceptedContract($booking);

        if ($booking->hire_mode === HireMode::SelfDrive) {
            $this->assertApprovedSelfDriveApplication($booking, true);

            return;
        }

        $driver = $booking->assignedDriver;

        if ($driver === null
            || $driver->status !== AccountStatus::Active
            || ! $driver->hasRole(UserRole::Driver)
            || $driver->email_verified_at === null) {
            $this->invalid('driver', 'Assign an active, verified driver before starting this hire.');
        }

        $activeAssignments = CarHireDriverAssignment::query()
            ->where('car_hire_booking_id', $booking->getKey())
            ->active()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ($activeAssignments->count() !== 1
            || (int) $activeAssignments->first()->driver_user_id !== $driver->getKey()) {
            $this->invalid('driver', 'The active driver assignment is incomplete or inconsistent.');
        }
    }

    private function assertAcceptedContract(CarHireBooking $booking): void
    {
        $contract = CarHireContract::query()
            ->where('car_hire_booking_id', $booking->getKey())
            ->whereNull('voided_at')
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->lockForUpdate()
            ->first();

        if ($contract === null
            || ! $contract->isAccepted()
            || $contract->accepted_by_user_id !== $booking->customer_id) {
            $this->invalid('contract', 'The customer must accept the current rental contract first.');
        }
    }

    private function assertApprovedSelfDriveApplication(CarHireBooking $booking, bool $requireOriginals): void
    {
        $application = CarHireSelfDriveApplication::query()
            ->where('car_hire_booking_id', $booking->getKey())
            ->lockForUpdate()
            ->first();

        if ($application === null || $application->status !== SelfDriveApplicationStatus::Approved) {
            $this->invalid('self_drive_application', 'The self-drive application must be approved first.');
        }

        if ($application->driving_permit_expires_on === null
            || $application->driving_permit_expires_on->toDateString() < $booking->return_at->toDateString()) {
            $this->invalid('self_drive_application', 'The approved driving permit does not cover the full hire interval.');
        }

        if ($requireOriginals && $application->originals_verified_at === null) {
            $this->invalid('self_drive_application', 'Verify the original identity and driving-permit documents before handover.');
        }
    }

    /** @return Collection<int, CarHireDriverAssignment> */
    private function releaseAssignments(
        CarHireBooking $booking,
        User $actor,
        DateTimeInterface $releasedAt,
        string $reason,
    ): Collection {
        $assignments = CarHireDriverAssignment::query()
            ->where('car_hire_booking_id', $booking->getKey())
            ->active()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $assignments->each(function (CarHireDriverAssignment $assignment) use ($actor, $releasedAt, $reason): void {
            $assignment->forceFill([
                'unassigned_at' => $releasedAt,
                'unassigned_by_user_id' => $actor->getKey(),
                'unassignment_reason' => $reason,
            ])->save();
        });

        return $assignments;
    }

    /** @return Collection<int, CarHireContract> */
    private function voidContracts(
        CarHireBooking $booking,
        User $actor,
        DateTimeInterface $voidedAt,
        string $reason,
    ): Collection {
        $contracts = CarHireContract::query()
            ->where('car_hire_booking_id', $booking->getKey())
            ->whereNull('voided_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $contracts->each(function (CarHireContract $contract) use ($actor, $voidedAt, $reason): void {
            $contract->forceFill([
                'voided_at' => $voidedAt,
                'voided_by_user_id' => $actor->getKey(),
                'void_reason' => $reason,
            ])->save();
        });

        return $contracts;
    }
}
