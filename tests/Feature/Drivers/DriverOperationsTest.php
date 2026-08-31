<?php

namespace Tests\Feature\Drivers;

use App\Actions\Drivers\CompleteDriverTrip;
use App\Actions\Drivers\RecordVehicleInspection;
use App\Actions\Drivers\StartDriverTrip;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\DriverTripStatus;
use App\Enums\InspectionPhase;
use App\Enums\MaintenanceStatus;
use App\Enums\MaintenanceType;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use App\Models\CarHireDriverAssignment;
use App\Models\DriverProfile;
use App\Models\DriverTrip;
use App\Models\TourAssignment;
use App\Models\TourBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleMaintenanceRecord;
use App\Services\Drivers\DriverAssignmentQuery;
use App\Support\Fleet\InspectionChecklist;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DriverOperationsTest extends TestCase
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

    private function driver(array $attributes = []): User
    {
        return $this->user(UserRole::Driver, $attributes);
    }

    private function vehicle(int $odometerKm = 50_000): Vehicle
    {
        return Vehicle::factory()->create([
            'operational_status' => VehicleOperationalStatus::Available,
            'current_odometer_km' => $odometerKm,
        ]);
    }

    /** @return array{0: CarHireDriverAssignment, 1: User, 2: Vehicle} */
    private function assignedJob(int $odometerKm = 50_000): array
    {
        $driver = $this->driver();
        $vehicle = $this->vehicle($odometerKm);

        $booking = CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Confirmed,
            'pickup_at' => now()->addHours(2),
            'return_at' => now()->addDays(3),
            'assigned_driver_user_id' => $driver->getKey(),
        ]);

        $assignment = CarHireDriverAssignment::query()->create([
            'car_hire_booking_id' => $booking->getKey(),
            'driver_user_id' => $driver->getKey(),
            'assigned_by_user_id' => $this->user(UserRole::Staff, ['two_factor_required' => false])->getKey(),
            'starts_at' => $booking->pickup_at,
            'ends_at' => $booking->return_at,
            'assigned_at' => now(),
        ]);

        return [$assignment, $driver, $vehicle];
    }

    /** @return array<string, string> */
    private function allOk(): array
    {
        return array_fill_keys(InspectionChecklist::keys(), 'ok');
    }

    /** @return array<string, mixed> */
    private function inspectionPayload(InspectionPhase $phase, int $odometerKm, array $overrides = []): array
    {
        return array_merge([
            'phase' => $phase->value,
            'odometer_km' => $odometerKm,
            'answers' => $this->allOk(),
        ], $overrides);
    }

    // ---- Assignment visibility -------------------------------------------

    public function test_a_driver_sees_only_their_own_work(): void
    {
        [, $mine] = $this->assignedJob();
        [, $theirs] = $this->assignedJob();

        $query = app(DriverAssignmentQuery::class);

        $this->assertCount(1, $query->upcoming($mine));
        $this->assertCount(1, $query->upcoming($theirs));
    }

    public function test_a_withdrawn_assignment_disappears_from_the_schedule(): void
    {
        [$assignment, $driver] = $this->assignedJob();

        $this->assertCount(1, app(DriverAssignmentQuery::class)->upcoming($driver));

        $assignment->forceFill([
            'unassigned_at' => now(),
            'unassignment_reason' => 'Reassigned to another driver.',
        ])->save();

        // Once the office takes the job away it is no longer this driver's.
        $this->assertCount(0, app(DriverAssignmentQuery::class)->upcoming($driver));
    }

    public function test_the_schedule_unions_every_assignment_source(): void
    {
        $driver = $this->driver();
        $staff = $this->user(UserRole::Staff, ['two_factor_required' => false]);

        // Car hire.
        $vehicle = $this->vehicle();
        $hire = CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Confirmed,
            'pickup_at' => now()->addDay(),
            'return_at' => now()->addDays(2),
        ]);
        CarHireDriverAssignment::query()->create([
            'car_hire_booking_id' => $hire->getKey(),
            'driver_user_id' => $driver->getKey(),
            'assigned_by_user_id' => $staff->getKey(),
            'starts_at' => $hire->pickup_at,
            'ends_at' => $hire->return_at,
            'assigned_at' => now(),
        ]);

        // A tour, from a different table entirely.
        $booking = TourBooking::factory()->create([
            'status' => TourBookingStatus::Confirmed,
        ]);
        TourAssignment::query()->create([
            'tour_booking_id' => $booking->getKey(),
            'driver_user_id' => $driver->getKey(),
            'assigned_by_user_id' => $staff->getKey(),
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(5),
            'assigned_at' => now(),
        ]);

        $rows = app(DriverAssignmentQuery::class)->upcoming($driver);

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(
            ['car-hire', 'tours'],
            $rows->pluck('source')->all(),
        );
    }

    public function test_a_tour_assignment_carries_no_fleet_vehicle(): void
    {
        $driver = $this->driver();
        $staff = $this->user(UserRole::Staff, ['two_factor_required' => false]);
        $booking = TourBooking::factory()->create([
            'status' => TourBookingStatus::Confirmed,
        ]);
        $assignment = TourAssignment::query()->create([
            'tour_booking_id' => $booking->getKey(),
            'driver_user_id' => $driver->getKey(),
            'assigned_by_user_id' => $staff->getKey(),
            'starts_at' => now()->addDay(),
            'ends_at' => now()->addDays(3),
            'assigned_at' => now(),
        ]);

        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);

        // A tour may run in something outside the hire fleet, so there is no
        // odometer to capture and no vehicle check to demand.
        $this->assertFalse($trip->tracksOdometer());
        $this->assertNull($trip->vehicle_id);
    }

    // ---- Checks gate the trip --------------------------------------------

    public function test_a_trip_cannot_start_without_a_pre_trip_check(): void
    {
        [$assignment, $driver] = $this->assignedJob();

        // A check that can be skipped authorises nothing.
        $this->expectException(ValidationException::class);

        app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_000]);
    }

    public function test_a_failed_pre_trip_check_keeps_the_vehicle_in(): void
    {
        [$assignment, $driver, $vehicle] = $this->assignedJob();
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);

        $answers = $this->allOk();
        $answers['brakes'] = 'defect';

        app(RecordVehicleInspection::class)->execute($driver, $trip, $this->inspectionPayload(
            InspectionPhase::PreTrip,
            50_000,
            ['answers' => $answers, 'defect_notes' => 'Pedal goes to the floor.'],
        ));

        $this->assertSame(VehicleOperationalStatus::Maintenance, $vehicle->fresh()->operational_status);

        $this->expectException(ValidationException::class);

        app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_000]);
    }

    public function test_an_unchecked_critical_item_fails_the_check(): void
    {
        [$assignment, $driver] = $this->assignedJob();
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);

        $answers = $this->allOk();
        unset($answers['lights']);

        $inspection = app(RecordVehicleInspection::class)->execute($driver, $trip, $this->inspectionPayload(
            InspectionPhase::PreTrip,
            50_000,
            ['answers' => $answers],
        ));

        // "I did not look" is not the same as "it is fine".
        $this->assertFalse($inspection->passed);
        $this->assertFalse($inspection->has_defects);
    }

    public function test_a_defect_raises_a_repair_job_in_the_fleet_queue(): void
    {
        [$assignment, $driver, $vehicle] = $this->assignedJob();
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);

        $answers = $this->allOk();
        $answers['bodywork'] = 'defect';

        $inspection = app(RecordVehicleInspection::class)->execute($driver, $trip, $this->inspectionPayload(
            InspectionPhase::PreTrip,
            50_000,
            ['answers' => $answers, 'defect_notes' => 'Deep scrape along the near side.'],
        ));

        // A check nobody acted on would be paperwork, not safety.
        $this->assertNotNull($inspection->maintenance_record_id);

        $record = VehicleMaintenanceRecord::query()->sole();
        $this->assertSame($vehicle->getKey(), $record->vehicle_id);
        $this->assertSame(MaintenanceType::Repair, $record->type);
        $this->assertSame(MaintenanceStatus::Scheduled, $record->status);
        $this->assertStringContainsString('Bodywork', $record->title);

        // Bodywork is not critical, so the vehicle may still go out.
        $this->assertTrue($inspection->passed);
        $this->assertSame(VehicleOperationalStatus::Available, $vehicle->fresh()->operational_status);
    }

    public function test_a_defect_without_notes_is_refused(): void
    {
        [$assignment, $driver] = $this->assignedJob();
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);

        $answers = $this->allOk();
        $answers['tyres'] = 'defect';

        $this->expectException(ValidationException::class);

        app(RecordVehicleInspection::class)->execute($driver, $trip, $this->inspectionPayload(
            InspectionPhase::PreTrip,
            50_000,
            ['answers' => $answers],
        ));
    }

    public function test_a_check_of_the_same_phase_cannot_be_redone(): void
    {
        [$assignment, $driver] = $this->assignedJob();
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);
        $action = app(RecordVehicleInspection::class);

        $answers = $this->allOk();
        $answers['brakes'] = 'defect';
        $action->execute($driver, $trip, $this->inspectionPayload(
            InspectionPhase::PreTrip,
            50_000,
            ['answers' => $answers, 'defect_notes' => 'Pedal goes to the floor.'],
        ));

        // A driver cannot quietly redo a failed check until it passes.
        $this->expectException(ValidationException::class);

        $action->execute($driver, $trip->fresh(), $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));
    }

    // ---- The trip itself -------------------------------------------------

    public function test_a_full_job_runs_from_check_to_close(): void
    {
        [$assignment, $driver, $vehicle] = $this->assignedJob(50_000);
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);

        app(RecordVehicleInspection::class)->execute($driver, $trip,
            $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));

        $started = app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_000]);
        $this->assertSame(DriverTripStatus::InProgress, $started->status);
        $this->assertSame(50_000, $started->start_odometer_km);

        app(RecordVehicleInspection::class)->execute($driver, $started->fresh(),
            $this->inspectionPayload(InspectionPhase::PostTrip, 50_420));

        $completed = app(CompleteDriverTrip::class)->execute($driver, $started->fresh(), [
            'odometer_km' => 50_420,
        ]);

        $this->assertSame(DriverTripStatus::Completed, $completed->status);
        $this->assertSame(50_420, $completed->end_odometer_km);
        $this->assertSame(420, $completed->distance_km);
        // The trip's mileage lands in the fleet's single odometer truth.
        $this->assertSame(50_420, $vehicle->fresh()->current_odometer_km);
    }

    public function test_a_trip_cannot_close_without_a_post_trip_check(): void
    {
        [$assignment, $driver] = $this->assignedJob(50_000);
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);
        app(RecordVehicleInspection::class)->execute($driver, $trip,
            $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));
        $started = app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_000]);

        $this->expectException(ValidationException::class);

        app(CompleteDriverTrip::class)->execute($driver, $started, ['odometer_km' => 50_400]);
    }

    public function test_a_closing_reading_below_the_departure_reading_is_refused(): void
    {
        [$assignment, $driver] = $this->assignedJob(50_000);
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);
        app(RecordVehicleInspection::class)->execute($driver, $trip,
            $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));
        $started = app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_100]);
        app(RecordVehicleInspection::class)->execute($driver, $started->fresh(),
            $this->inspectionPayload(InspectionPhase::PostTrip, 50_100));

        $this->expectException(ValidationException::class);

        app(CompleteDriverTrip::class)->execute($driver, $started->fresh(), ['odometer_km' => 50_050]);
    }

    public function test_one_trip_per_assignment_ever(): void
    {
        [$assignment, $driver] = $this->assignedJob();
        $action = app(StartDriverTrip::class);

        $first = $action->prepare($driver, $assignment);
        $second = $action->prepare($driver, $assignment);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, DriverTrip::query()->count());
    }

    public function test_an_abandoned_job_records_no_distance(): void
    {
        [$assignment, $driver] = $this->assignedJob(50_000);
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);
        app(RecordVehicleInspection::class)->execute($driver, $trip,
            $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));
        $started = app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_000]);

        $abandoned = app(CompleteDriverTrip::class)
            ->abandon($driver, $started, 'Customer never arrived at the pickup point.');

        $this->assertSame(DriverTripStatus::Abandoned, $abandoned->status);
        // A job that never happened must not appear in distance or utilisation
        // figures as if it had.
        $this->assertNull($abandoned->distance_km);
    }

    public function test_abandoning_requires_a_reason(): void
    {
        [$assignment, $driver] = $this->assignedJob(50_000);
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);
        app(RecordVehicleInspection::class)->execute($driver, $trip,
            $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));
        $started = app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_000]);

        $this->expectException(ValidationException::class);

        app(CompleteDriverTrip::class)->abandon($driver, $started, '');
    }

    public function test_starting_twice_is_a_no_op(): void
    {
        [$assignment, $driver] = $this->assignedJob(50_000);
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);
        app(RecordVehicleInspection::class)->execute($driver, $trip,
            $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));
        $action = app(StartDriverTrip::class);

        $action->execute($driver, $assignment, ['odometer_km' => 50_000]);
        $startedAt = DriverTrip::query()->sole()->started_at;

        $this->travelTo(now()->addHour());
        $action->execute($driver, $assignment, ['odometer_km' => 50_900]);

        $fresh = DriverTrip::query()->sole();
        $this->assertEquals($startedAt, $fresh->started_at);
        $this->assertSame(50_000, $fresh->start_odometer_km);
    }

    // ---- Authorisation ---------------------------------------------------

    public function test_a_driver_cannot_act_on_someone_elses_assignment(): void
    {
        [$assignment] = $this->assignedJob();
        $stranger = $this->driver();

        $this->expectException(AuthorizationException::class);

        app(StartDriverTrip::class)->prepare($stranger, $assignment);
    }

    public function test_a_withdrawn_assignment_cannot_be_started(): void
    {
        [$assignment, $driver] = $this->assignedJob();
        $assignment->forceFill(['unassigned_at' => now()])->save();

        // Holding the link is not enough once the office has taken the job away.
        $this->expectException(AuthorizationException::class);

        app(StartDriverTrip::class)->prepare($driver, $assignment->fresh());
    }

    public function test_a_customer_cannot_run_driver_actions(): void
    {
        [$assignment] = $this->assignedJob();

        $this->expectException(AuthorizationException::class);

        app(StartDriverTrip::class)->prepare($this->user(UserRole::Customer), $assignment);
    }

    public function test_a_suspended_driver_cannot_act(): void
    {
        [$assignment, $driver] = $this->assignedJob();
        $driver->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->expectException(AuthorizationException::class);

        app(StartDriverTrip::class)->prepare($driver->fresh(), $assignment);
    }

    // ---- Profile ---------------------------------------------------------

    public function test_an_expired_licence_disqualifies_regardless_of_availability(): void
    {
        $driver = $this->driver();
        $profile = DriverProfile::query()->create([
            'user_id' => $driver->getKey(),
            'licence_number' => 'UG-DL-99887766',
            'licence_expires_at' => '2026-08-01',
            'is_available' => true,
        ]);

        // No office toggle should be able to put an unlicensed driver on the road.
        $this->assertTrue($profile->licenceHasExpired());
        $this->assertFalse($profile->canBeAssigned());
    }

    public function test_the_licence_number_is_encrypted_and_masked(): void
    {
        $driver = $this->driver();
        DriverProfile::query()->create([
            'user_id' => $driver->getKey(),
            'licence_number' => 'UG-DL-99887766',
        ]);

        $stored = (string) DB::table('driver_profiles')->value('licence_number');
        $this->assertStringNotContainsString('99887766', $stored);

        $profile = DriverProfile::query()->sole();
        $this->assertSame('UG-DL-99887766', $profile->licence_number);
        $this->assertStringEndsWith('7766', $profile->maskedLicence());
        $this->assertStringNotContainsString('99887766', $profile->maskedLicence());
        // Never part of a serialised payload.
        $this->assertArrayNotHasKey('licence_number', $profile->toArray());
    }

    // ---- HTTP ------------------------------------------------------------

    public function test_a_driver_dashboard_replaces_the_not_built_page(): void
    {
        [, $driver] = $this->assignedJob();

        $this->actingAs($driver)->get(route('dashboard'))->assertRedirect(route('drivers.index'));

        $this->actingAs($driver)
            ->get(route('drivers.index'))
            ->assertOk()
            ->assertSee('Coming up')
            ->assertDontSee('Your workspace is not built yet');
    }

    public function test_a_driver_opens_their_own_job_and_not_a_strangers(): void
    {
        [$assignment, $driver] = $this->assignedJob();
        $stranger = $this->driver();

        $this->actingAs($driver)
            ->get(route('drivers.jobs.show', ['car-hire', $assignment->getKey()]))
            ->assertOk()
            ->assertSee('Vehicle checks');

        // A foreign job is a 404: a driver learns nothing about whose it is.
        $this->actingAs($stranger)
            ->get(route('drivers.jobs.show', ['car-hire', $assignment->getKey()]))
            ->assertNotFound();
    }

    public function test_an_unknown_assignment_source_is_a_404(): void
    {
        [$assignment, $driver] = $this->assignedJob();

        $this->actingAs($driver)
            ->get(route('drivers.jobs.show', ['not-a-service', $assignment->getKey()]))
            ->assertNotFound();
    }

    public function test_a_customer_cannot_reach_the_driver_portal(): void
    {
        $this->actingAs($this->user(UserRole::Customer))
            ->get(route('drivers.index'))
            ->assertForbidden();
    }

    public function test_a_driver_records_a_check_over_http(): void
    {
        [$assignment, $driver] = $this->assignedJob(50_000);

        $this->actingAs($driver)
            ->post(route('drivers.jobs.inspection', ['car-hire', $assignment->getKey()]), [
                'phase' => InspectionPhase::PreTrip->value,
                'odometer_km' => 50_000,
                'answers' => $this->allOk(),
            ])
            ->assertRedirect(route('drivers.jobs.show', ['car-hire', $assignment->getKey()]));

        $this->assertDatabaseHas('vehicle_inspections', [
            'driver_user_id' => $driver->getKey(),
            'phase' => InspectionPhase::PreTrip->value,
            'passed' => true,
        ]);
    }

    public function test_history_lists_only_the_drivers_own_closed_trips(): void
    {
        [$assignment, $driver] = $this->assignedJob(50_000);
        $trip = app(StartDriverTrip::class)->prepare($driver, $assignment);
        app(RecordVehicleInspection::class)->execute($driver, $trip,
            $this->inspectionPayload(InspectionPhase::PreTrip, 50_000));
        $started = app(StartDriverTrip::class)->execute($driver, $assignment, ['odometer_km' => 50_000]);
        app(RecordVehicleInspection::class)->execute($driver, $started->fresh(),
            $this->inspectionPayload(InspectionPhase::PostTrip, 50_300));
        app(CompleteDriverTrip::class)->execute($driver, $started->fresh(), ['odometer_km' => 50_300]);

        $this->actingAs($driver)
            ->get(route('drivers.history'))
            ->assertOk()
            ->assertSee('300 km');

        $this->actingAs($this->driver())
            ->get(route('drivers.history'))
            ->assertOk()
            ->assertSee('No completed trips yet');
    }
}
