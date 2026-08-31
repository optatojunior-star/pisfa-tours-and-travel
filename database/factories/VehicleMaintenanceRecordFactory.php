<?php

namespace Database\Factories;

use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<VehicleMaintenanceRecord> */
class VehicleMaintenanceRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'reference' => 'MNT-'.Str::upper((string) Str::ulid()),
            'vehicle_id' => Vehicle::factory(),
            'type' => MaintenanceType::Service,
            'status' => MaintenanceStatus::Scheduled,
            'title' => 'Routine service',
            'scheduled_for' => now()->addDays(7)->toDateString(),
            'cost_minor' => 0,
            'currency' => 'UGX',
        ];
    }

    public function forVehicle(Vehicle $vehicle): static
    {
        return $this->state(fn (): array => ['vehicle_id' => $vehicle->getKey()]);
    }

    public function ofType(MaintenanceType $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /** A closed job with a real cost and odometer reading. */
    public function completed(int $odometerKm, int $costMinor = 250_000): static
    {
        return $this->state(fn (): array => [
            'status' => MaintenanceStatus::Completed,
            'completed_at' => now(),
            'odometer_km' => $odometerKm,
            'cost_minor' => $costMinor,
        ]);
    }

    public function dueOn(string $date): static
    {
        return $this->state(fn (): array => ['next_due_on' => $date]);
    }

    public function dueAtOdometer(int $km): static
    {
        return $this->state(fn (): array => ['next_due_odometer_km' => $km]);
    }

    public function withStatus(MaintenanceStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'started_at' => $status === MaintenanceStatus::InProgress ? now() : null,
            'completed_at' => $status === MaintenanceStatus::Completed ? now() : null,
            'cancelled_at' => $status === MaintenanceStatus::Cancelled ? now() : null,
        ]);
    }
}
