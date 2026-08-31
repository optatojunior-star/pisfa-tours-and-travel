<?php

namespace App\Actions\AirportTransfers;

use App\Actions\AirportTransfers\Concerns\InteractsWithAirportTransferDomain;
use App\Enums\AccountStatus;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Models\AirportTransferAssignment;
use App\Models\AirportTransferBooking;
use App\Models\CarHireBooking;
use App\Models\CarHireDriverAssignment;
use App\Models\TourAssignment;
use App\Models\User;
use App\Models\Vehicle;
use App\Notifications\AirportTransfers\AirportTransferTeamAssignmentNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssignAirportTransferResources
{
    use InteractsWithAirportTransferDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        AirportTransferBooking $booking,
        Vehicle $vehicle,
        User $driver,
        ?string $replacementReason = null,
    ): AirportTransferBooking {
        $replacementReason = $this->nullableString($replacementReason);

        Validator::make(
            ['replacement_reason' => $replacementReason],
            ['replacement_reason' => ['nullable', 'string', 'max:2000']],
        )->validate();
        $this->ensureOperationsActor($actor);

        return DB::transaction(function () use (
            $actor,
            $booking,
            $vehicle,
            $driver,
            $replacementReason,
        ): AirportTransferBooking {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            // Shared resource lock order across transport domains: driver,
            // vehicle, then booking. Conflict queries run only after both
            // serialization rows are held.
            $lockedDriver = User::query()->whereKey($driver->getKey())->lockForUpdate()->first();

            if ($lockedDriver === null
                || $lockedDriver->status !== AccountStatus::Active
                || ! $lockedDriver->hasRole(UserRole::Driver)
                || $lockedDriver->email_verified_at === null) {
                $this->invalid('driver', 'Select an active, verified driver account.');
            }

            $lockedVehicle = Vehicle::query()->whereKey($vehicle->getKey())->lockForUpdate()->firstOrFail();
            $lockedBooking = AirportTransferBooking::query()
                ->with(['customer', 'assignedDriver', 'assignedVehicle'])
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! in_array($lockedBooking->status, [
                AirportTransferBookingStatus::Pending,
                AirportTransferBookingStatus::Confirmed,
                AirportTransferBookingStatus::InProgress,
            ], true)) {
                $this->invalid('assignment', 'Resources cannot be assigned to this booking status.');
            }

            if ($lockedBooking->status === AirportTransferBookingStatus::Pending
                && ! $lockedBooking->request_expires_at->isFuture()) {
                $this->invalid('assignment', 'This pending transfer request has expired.');
            }

            if (! $lockedBooking->service_ends_at->isFuture()) {
                $this->invalid('assignment', 'Resources cannot be assigned after this transfer has ended.');
            }

            $this->assertEligiblePair($lockedBooking, $lockedVehicle, $lockedDriver);

            /** @var Collection<int, AirportTransferAssignment> $activeAssignments */
            $activeAssignments = AirportTransferAssignment::query()
                ->forBooking($lockedBooking)
                ->active()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($activeAssignments->count() > 1) {
                $this->invalid('assignment', 'This booking has inconsistent active assignment history.');
            }

            $activeAssignment = $activeAssignments->first();

            if ($activeAssignment !== null
                && $activeAssignment->vehicle_id === $lockedVehicle->getKey()
                && $activeAssignment->driver_user_id === $lockedDriver->getKey()
                && $lockedBooking->assigned_vehicle_id === $lockedVehicle->getKey()
                && $lockedBooking->assigned_driver_user_id === $lockedDriver->getKey()) {
                return $lockedBooking;
            }

            if ($activeAssignment === null
                && ($lockedBooking->assigned_vehicle_id !== null
                    || $lockedBooking->assigned_driver_user_id !== null)) {
                $this->invalid('assignment', 'The booking assignment projection is inconsistent with its history.');
            }

            if ($activeAssignment !== null && $replacementReason === null) {
                $this->invalid('replacement_reason', 'Enter a reason for replacing the current transfer team.');
            }

            $this->assertNoConflicts($lockedBooking, $lockedVehicle, $lockedDriver);

            $oldDriver = $lockedBooking->assignedDriver;
            $oldVehicle = $lockedBooking->assignedVehicle;
            $changedAt = now();

            if ($activeAssignment !== null) {
                $activeAssignment->forceFill([
                    'unassigned_at' => $changedAt,
                    'unassigned_by_user_id' => $lockedActor->getKey(),
                    'unassignment_reason' => $replacementReason,
                ])->save();
            }

            AirportTransferAssignment::query()->create([
                'airport_transfer_booking_id' => $lockedBooking->getKey(),
                'vehicle_id' => $lockedVehicle->getKey(),
                'driver_user_id' => $lockedDriver->getKey(),
                'assigned_by_user_id' => $lockedActor->getKey(),
                'starts_at' => $lockedBooking->service_starts_at,
                'ends_at' => $lockedBooking->service_ends_at,
                'assigned_at' => $changedAt,
            ]);

            $lockedBooking->forceFill([
                'assigned_vehicle_id' => $lockedVehicle->getKey(),
                'assigned_driver_user_id' => $lockedDriver->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: $activeAssignment === null
                    ? 'airport_transfer_booking.resources_assigned'
                    : 'airport_transfer_booking.resources_reassigned',
                auditable: $lockedBooking,
                oldValues: [
                    'assigned_vehicle_id' => $oldVehicle?->getKey(),
                    'assigned_driver_user_id' => $oldDriver?->getKey(),
                ],
                newValues: [
                    'assigned_vehicle_id' => $lockedVehicle->getKey(),
                    'assigned_driver_user_id' => $lockedDriver->getKey(),
                    'assignment_starts_at' => $lockedBooking->service_starts_at->toIso8601String(),
                    'assignment_ends_at' => $lockedBooking->service_ends_at->toIso8601String(),
                ],
                context: [
                    'replacement_reason_present' => $replacementReason !== null,
                ],
                user: $lockedActor,
            );

            DB::afterCommit(function () use (
                $lockedBooking,
                $oldDriver,
                $oldVehicle,
                $lockedDriver,
                $lockedVehicle,
                $replacementReason,
            ): void {
                $this->dispatchAssignmentNotifications(
                    $lockedBooking,
                    $oldDriver,
                    $oldVehicle,
                    $lockedDriver,
                    $lockedVehicle,
                    $replacementReason,
                );
            });

            return $lockedBooking->fresh([
                'customer',
                'airport',
                'location',
                'rate',
                'assignedVehicle',
                'assignedDriver',
                'assignments',
            ]);
        }, 3);
    }

    private function assertEligiblePair(
        AirportTransferBooking $booking,
        Vehicle $vehicle,
        User $driver,
    ): void {
        if ($vehicle->operational_status !== VehicleOperationalStatus::Available) {
            $this->invalid('vehicle', 'Select an operationally available vehicle.');
        }

        if (strtolower(trim($vehicle->vehicle_type)) !== $booking->vehicle_type_snapshot) {
            $this->invalid('vehicle', 'The selected vehicle does not match the booked vehicle type.');
        }

        if ($vehicle->seating_capacity < $booking->passenger_count) {
            $this->invalid('vehicle', 'The selected vehicle cannot carry all booked passengers.');
        }

        if ($vehicle->luggage_capacity < $booking->luggage_count) {
            $this->invalid('vehicle', 'The selected vehicle cannot carry all booked luggage.');
        }

        if ($driver->status !== AccountStatus::Active
            || ! $driver->hasRole(UserRole::Driver)
            || $driver->email_verified_at === null) {
            $this->invalid('driver', 'Select an active, verified driver account.');
        }
    }

    private function assertNoConflicts(
        AirportTransferBooking $booking,
        Vehicle $vehicle,
        User $driver,
    ): void {
        $now = now();
        $hasTransferDriverConflict = AirportTransferAssignment::query()
            ->active()
            ->forDriver($driver)
            ->overlapping($booking->service_starts_at, $booking->service_ends_at)
            ->where('airport_transfer_booking_id', '!=', $booking->getKey())
            ->whereHas('booking', fn ($query) => $query->holdingResources($now))
            ->exists();

        if ($hasTransferDriverConflict) {
            $this->invalid('driver', 'This driver already has an overlapping airport-transfer assignment.');
        }

        $hasTourConflict = TourAssignment::query()
            ->active()
            ->forDriver($driver)
            ->overlapping($booking->service_starts_at, $booking->service_ends_at)
            ->exists();

        if ($hasTourConflict) {
            $this->invalid('driver', 'This driver already has an overlapping tour assignment.');
        }

        $hasCarHireDriverConflict = CarHireDriverAssignment::query()
            ->active()
            ->forDriver($driver)
            ->overlapping($booking->service_starts_at, $booking->service_ends_at)
            ->exists();

        if ($hasCarHireDriverConflict) {
            $this->invalid('driver', 'This driver already has an overlapping vehicle-hire assignment.');
        }

        $hasTransferVehicleConflict = AirportTransferAssignment::query()
            ->active()
            ->forVehicle($vehicle)
            ->overlapping($booking->service_starts_at, $booking->service_ends_at)
            ->where('airport_transfer_booking_id', '!=', $booking->getKey())
            ->whereHas('booking', fn ($query) => $query->holdingResources($now))
            ->exists();

        if ($hasTransferVehicleConflict) {
            $this->invalid('vehicle', 'This vehicle already has an overlapping airport-transfer assignment.');
        }

        $hasCarHireVehicleConflict = CarHireBooking::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->holdingVehicle($now)
            ->overlapping($booking->service_starts_at, $booking->service_ends_at)
            ->exists();

        if ($hasCarHireVehicleConflict) {
            $this->invalid('vehicle', 'This vehicle already has an overlapping vehicle-hire booking.');
        }
    }

    private function dispatchAssignmentNotifications(
        AirportTransferBooking $booking,
        ?User $oldDriver,
        ?Vehicle $oldVehicle,
        User $driver,
        Vehicle $vehicle,
        ?string $replacementReason,
    ): void {
        $booking->loadMissing('customer');
        $vehicleName = $this->vehicleName($vehicle);

        if ($oldDriver !== null && ! $oldDriver->is($driver)) {
            $oldDriver->notify(new AirportTransferTeamAssignmentNotification(
                recipientName: $oldDriver->name,
                bookingReference: $booking->reference,
                serviceStartsAt: $booking->service_starts_at->toIso8601String(),
                airportName: $booking->airport_name_snapshot,
                locationName: $booking->location_name_snapshot,
                vehicleName: $oldVehicle === null ? 'Previous vehicle' : $this->vehicleName($oldVehicle),
                assigned: false,
                forDriver: true,
                viewUrl: route('dashboard'),
                reason: $replacementReason,
            ));
        }

        $this->notifyBookingRecipient($booking, new AirportTransferTeamAssignmentNotification(
            recipientName: $booking->contact_name,
            bookingReference: $booking->reference,
            serviceStartsAt: $booking->service_starts_at->toIso8601String(),
            airportName: $booking->airport_name_snapshot,
            locationName: $booking->location_name_snapshot,
            vehicleName: $vehicleName,
            assigned: true,
            forDriver: false,
            viewUrl: $this->bookingViewUrl($booking),
            driverName: $driver->name,
        ));

        $driver->notify(new AirportTransferTeamAssignmentNotification(
            recipientName: $driver->name,
            bookingReference: $booking->reference,
            serviceStartsAt: $booking->service_starts_at->toIso8601String(),
            airportName: $booking->airport_name_snapshot,
            locationName: $booking->location_name_snapshot,
            vehicleName: $vehicleName,
            assigned: true,
            forDriver: true,
            viewUrl: route('dashboard'),
            contactName: $booking->contact_name,
            contactPhone: $booking->contact_phone,
            serviceAddress: $booking->service_address,
        ));
    }

    private function vehicleName(Vehicle $vehicle): string
    {
        return trim($vehicle->year.' '.$vehicle->make.' '.$vehicle->model);
    }
}
