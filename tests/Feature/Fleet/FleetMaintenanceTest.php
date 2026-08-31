<?php

namespace Tests\Feature\Fleet;

use App\Actions\Fleet\CompleteMaintenanceRecord;
use App\Actions\Fleet\RecordFuelLog;
use App\Actions\Fleet\RecordOdometerReading;
use App\Actions\Fleet\SaveMaintenanceRecord;
use App\Enums\AccountStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FleetMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    private function vehicle(int $odometerKm = 50_000): Vehicle
    {
        return Vehicle::factory()->create([
            'operational_status' => VehicleOperationalStatus::Available,
            'current_odometer_km' => $odometerKm,
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'type' => MaintenanceType::Service->value,
            'title' => '10,000 km service',
            'currency' => 'UGX',
        ], $overrides);
    }

    // ---- Odometer, the invariant everything rests on ----------------------

    public function test_a_reading_below_the_current_one_is_refused(): void
    {
        $vehicle = $this->vehicle(50_000);

        // An odometer going backwards would corrupt every consumption and
        // service-due figure derived from it.
        $this->expectException(ValidationException::class);

        RecordOdometerReading::advance($vehicle, 49_999);
    }

    public function test_an_equal_reading_is_accepted(): void
    {
        $vehicle = $this->vehicle(50_000);

        // Two records on the same day at the same mileage are ordinary.
        RecordOdometerReading::advance($vehicle, 50_000);

        $this->assertSame(50_000, $vehicle->fresh()->current_odometer_km);
    }

    public function test_an_implausible_jump_is_refused(): void
    {
        $vehicle = $this->vehicle(50_000);

        // A typo of an extra digit would permanently corrupt the mileage.
        $this->expectException(ValidationException::class);

        RecordOdometerReading::advance($vehicle, 500_000);
    }

    public function test_a_valid_reading_advances_the_vehicle(): void
    {
        $vehicle = $this->vehicle(50_000);

        RecordOdometerReading::advance($vehicle, 52_500);

        $vehicle->refresh();
        $this->assertSame(52_500, $vehicle->current_odometer_km);
        $this->assertNotNull($vehicle->odometer_updated_at);
    }

    public function test_maintenance_and_fuel_share_one_odometer_guard(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();

        app(RecordFuelLog::class)->execute($staff, $vehicle, [
            'filled_at' => now()->toDateTimeString(),
            'odometer_km' => 51_000,
            'litres' => 45,
            'cost' => '180000',
            'currency' => 'UGX',
        ]);

        $this->assertSame(51_000, $vehicle->fresh()->current_odometer_km);

        // Maintenance closed at a lower reading must now be refused, because
        // both paths check the same vehicle column.
        $record = VehicleMaintenanceRecord::factory()->forVehicle($vehicle)->create();

        $this->expectException(ValidationException::class);

        app(CompleteMaintenanceRecord::class)->complete($staff, $record, [
            'odometer_km' => 50_500,
            'cost' => '250000',
        ]);
    }

    // ---- Lifecycle -------------------------------------------------------

    public function test_scheduling_records_the_job_without_a_cost(): void
    {
        $vehicle = $this->vehicle();
        $record = app(SaveMaintenanceRecord::class)->create($this->staff(), $vehicle, $this->payload());

        // A scheduled job has neither cost nor reading yet; inventing them
        // would put fiction into the cost report.
        $this->assertSame(MaintenanceStatus::Scheduled, $record->status);
        $this->assertSame(0, $record->cost_minor);
        $this->assertNull($record->odometer_km);
        $this->assertDatabaseHas('audit_logs', ['event' => 'vehicle_maintenance.scheduled']);
    }

    public function test_starting_work_takes_the_vehicle_off_hire(): void
    {
        $vehicle = $this->vehicle();
        $staff = $this->staff();
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());

        app(CompleteMaintenanceRecord::class)->start($staff, $record);

        $this->assertSame(MaintenanceStatus::InProgress, $record->fresh()->status);
        $this->assertSame(VehicleOperationalStatus::Maintenance, $vehicle->fresh()->operational_status);
        $this->assertFalse($vehicle->fresh()->acceptsHireAt());
    }

    public function test_completing_returns_the_vehicle_and_records_the_cost(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());
        app(CompleteMaintenanceRecord::class)->start($staff, $record);

        $completed = app(CompleteMaintenanceRecord::class)->complete($staff, $record->fresh(), [
            'odometer_km' => 52_000,
            'cost' => '450000',
            'next_due_on' => now()->addMonths(6)->toDateString(),
            'next_due_odometer_km' => 62_000,
        ]);

        $this->assertSame(MaintenanceStatus::Completed, $completed->status);
        $this->assertSame(450_000, $completed->cost_minor);
        $this->assertSame(52_000, $completed->odometer_km);
        $this->assertSame(52_000, $vehicle->fresh()->current_odometer_km);
        $this->assertSame(VehicleOperationalStatus::Available, $vehicle->fresh()->operational_status);
    }

    public function test_a_second_open_job_keeps_the_vehicle_off_hire(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();
        $action = app(CompleteMaintenanceRecord::class);

        $first = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());
        $second = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload([
            'title' => 'Brake pads',
            'type' => MaintenanceType::Repair->value,
        ]));

        $action->start($staff, $first);
        $action->start($staff, $second);

        $action->complete($staff, $first->fresh(), ['odometer_km' => 50_100, 'cost' => '100000']);

        // Releasing unconditionally would put the vehicle back on hire while it
        // was still in the workshop for the second job.
        $this->assertSame(VehicleOperationalStatus::Maintenance, $vehicle->fresh()->operational_status);

        $action->complete($staff, $second->fresh(), ['odometer_km' => 50_100, 'cost' => '80000']);

        $this->assertSame(VehicleOperationalStatus::Available, $vehicle->fresh()->operational_status);
    }

    public function test_a_completed_record_cannot_be_edited(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());
        app(CompleteMaintenanceRecord::class)->complete($staff, $record, [
            'odometer_km' => 51_000,
            'cost' => '300000',
        ]);

        // A closed record is evidence; editing it would rewrite the service
        // history and the cost report.
        $this->expectException(ValidationException::class);

        app(SaveMaintenanceRecord::class)->update($staff, $record->fresh(), $this->payload([
            'title' => 'Quietly changed',
        ]));
    }

    public function test_completing_twice_is_a_no_op(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();
        $action = app(CompleteMaintenanceRecord::class);
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());

        $action->complete($staff, $record, ['odometer_km' => 51_000, 'cost' => '300000']);
        $completedAt = $record->fresh()->completed_at;

        $this->travelTo(now()->addHour());
        $action->complete($staff, $record->fresh(), ['odometer_km' => 55_000, 'cost' => '999999']);

        $fresh = $record->fresh();
        $this->assertEquals($completedAt, $fresh->completed_at);
        $this->assertSame(300_000, $fresh->cost_minor);
        $this->assertSame(51_000, $vehicle->fresh()->current_odometer_km);
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $vehicle = $this->vehicle();
        $staff = $this->staff();
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());

        $this->expectException(ValidationException::class);

        app(CompleteMaintenanceRecord::class)->cancel($staff, $record, '');
    }

    public function test_a_cancelled_record_is_terminal(): void
    {
        $vehicle = $this->vehicle();
        $staff = $this->staff();
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());
        app(CompleteMaintenanceRecord::class)->cancel($staff, $record, 'Vehicle sold before the service.');

        $this->expectException(ValidationException::class);

        app(CompleteMaintenanceRecord::class)->start($staff, $record->fresh());
    }

    public function test_a_customer_cannot_record_fleet_work(): void
    {
        $vehicle = $this->vehicle();

        $this->expectException(AuthorizationException::class);

        app(SaveMaintenanceRecord::class)->create($this->user(UserRole::Customer), $vehicle, $this->payload());
    }

    public function test_a_suspended_staff_member_cannot_record_fleet_work(): void
    {
        $vehicle = $this->vehicle();
        $suspended = $this->user(UserRole::Staff, [
            'status' => AccountStatus::Suspended,
            'two_factor_required' => false,
        ]);

        $this->expectException(AuthorizationException::class);

        app(SaveMaintenanceRecord::class)->create($suspended, $vehicle, $this->payload());
    }

    // ---- Next-due behaviour ----------------------------------------------

    public function test_a_one_off_repair_proposes_no_next_service(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload([
            'type' => MaintenanceType::Repair->value,
            'title' => 'Replace wing mirror',
        ]));

        $completed = app(CompleteMaintenanceRecord::class)->complete($staff, $record, [
            'odometer_km' => 50_500,
            'cost' => '90000',
            'next_due_on' => now()->addMonths(6)->toDateString(),
            'next_due_odometer_km' => 60_000,
        ]);

        // A repair does not recur, so a next-due point would be noise in the
        // alert queue even when the operator supplies one.
        $this->assertNull($completed->next_due_on);
        $this->assertNull($completed->next_due_odometer_km);
    }

    public function test_a_next_service_reading_must_be_ahead_of_the_current_one(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();
        $record = app(SaveMaintenanceRecord::class)->create($staff, $vehicle, $this->payload());

        // Otherwise the record would be born overdue.
        $this->expectException(ValidationException::class);

        app(CompleteMaintenanceRecord::class)->complete($staff, $record, [
            'odometer_km' => 52_000,
            'cost' => '300000',
            'next_due_odometer_km' => 51_000,
        ]);
    }

    public function test_service_is_due_by_date_or_by_odometer_whichever_comes_first(): void
    {
        $vehicle = $this->vehicle(50_000);

        $byDate = VehicleMaintenanceRecord::factory()
            ->forVehicle($vehicle)
            ->completed(48_000)
            ->dueOn('2026-08-19')
            ->create();

        $byOdometer = VehicleMaintenanceRecord::factory()
            ->forVehicle($vehicle)
            ->completed(40_000)
            ->dueAtOdometer(49_000)
            ->create();

        $notYet = VehicleMaintenanceRecord::factory()
            ->forVehicle($vehicle)
            ->completed(49_000)
            ->dueOn('2026-12-01')
            ->dueAtOdometer(60_000)
            ->create();

        $this->assertTrue($byDate->fresh()->isDue());
        $this->assertTrue($byOdometer->fresh()->isDue());
        $this->assertFalse($notYet->fresh()->isDue());

        $this->assertSame(2, VehicleMaintenanceRecord::query()->due()->count());
    }

    public function test_the_due_reason_says_why(): void
    {
        $vehicle = $this->vehicle(50_000);
        $record = VehicleMaintenanceRecord::factory()
            ->forVehicle($vehicle)
            ->completed(40_000)
            ->dueAtOdometer(49_000)
            ->create();

        $this->assertStringContainsString('49,000 km', (string) $record->fresh()->dueReason());
    }
}
