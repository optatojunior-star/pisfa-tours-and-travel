<?php

namespace Tests\Feature\Fleet;

use App\Actions\Fleet\RecordFuelLog;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\DocumentCategory;
use App\Enums\DocumentVisibility;
use App\Enums\UserRole;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use App\Models\Document;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleFuelLog;
use App\Models\VehicleMaintenanceRecord;
use App\Notifications\Fleet\FleetAlertNotification;
use App\Services\Fleet\FleetReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FleetReportingTest extends TestCase
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

    private function vehicleDocument(
        Vehicle $vehicle,
        DocumentCategory $category,
        ?string $expiresAt,
    ): Document {
        // Documents are versioned per (owner, category), so a second one in the
        // same category has to be the next version.
        $version = 1 + (int) Document::query()
            ->where('documentable_type', $vehicle->getMorphClass())
            ->where('documentable_id', $vehicle->getKey())
            ->where('category', $category->value)
            ->max('version');

        $document = new Document;
        $document->forceFill([
            'documentable_type' => $vehicle->getMorphClass(),
            'documentable_id' => $vehicle->getKey(),
            'category' => $category,
            'visibility' => DocumentVisibility::Private,
            'disk' => 'local',
            'path' => 'vehicles/'.$vehicle->getKey().'/'.uniqid().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'content_sha256' => hash('sha256', uniqid()),
            'version' => $version,
            'is_current' => true,
            'is_generated' => false,
            'expires_at' => $expiresAt,
        ])->save();

        return $document;
    }

    // ---- Fuel ------------------------------------------------------------

    public function test_litres_are_stored_as_whole_millilitres(): void
    {
        $vehicle = $this->vehicle(50_000);

        $log = app(RecordFuelLog::class)->execute($this->staff(), $vehicle, [
            'filled_at' => now()->toDateTimeString(),
            'odometer_km' => 50_400,
            'litres' => 45.75,
            'cost' => '183000',
            'currency' => 'UGX',
        ]);

        // No float is ever persisted; litres are a display concern.
        $this->assertSame(45_750, $log->volume_ml);
        $this->assertSame(45.75, $log->litres());
        $this->assertSame('45.75 L', $log->formattedVolume());
    }

    public function test_price_per_litre_is_derived_and_never_stored(): void
    {
        $vehicle = $this->vehicle(50_000);

        $log = app(RecordFuelLog::class)->execute($this->staff(), $vehicle, [
            'filled_at' => now()->toDateTimeString(),
            'odometer_km' => 50_400,
            'litres' => 50,
            'cost' => '250000',
            'currency' => 'UGX',
        ]);

        $this->assertSame('UGX 5,000/L', $log->formattedPricePerLitre());
    }

    public function test_a_future_refuelling_is_refused(): void
    {
        $vehicle = $this->vehicle(50_000);

        $this->expectException(ValidationException::class);

        app(RecordFuelLog::class)->execute($this->staff(), $vehicle, [
            'filled_at' => now()->addDay()->toDateTimeString(),
            'odometer_km' => 50_400,
            'litres' => 50,
            'cost' => '250000',
            'currency' => 'UGX',
        ]);
    }

    public function test_consumption_is_computed_between_full_tanks(): void
    {
        $vehicle = $this->vehicle(50_000);

        // Full at 50,000. Then 40 L over 400 km, then 45 L over 450 km.
        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_000, 50_000)->create();
        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_400, 40_000)->create();
        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_850, 45_000)->create();

        $consumption = app(FleetReport::class)->consumptionFor($vehicle);

        // 85 L over 850 km = 10.0 L/100km. The first fill's volume is excluded
        // because it covered distance before the window.
        $this->assertSame(850, $consumption['distance_km']);
        $this->assertSame(85_000, $consumption['volume_ml']);
        $this->assertSame(10.0, $consumption['litres_per_100km']);
        $this->assertSame(2, $consumption['intervals']);
    }

    public function test_a_partial_fill_is_excluded_from_consumption(): void
    {
        $vehicle = $this->vehicle(50_000);

        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_000, 50_000)->create();
        // A partial fill leaves an unknown amount already in the tank, so the
        // figure would not be reproducible.
        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_200, 20_000)->partialFill()->create();
        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_400, 40_000)->create();

        $consumption = app(FleetReport::class)->consumptionFor($vehicle);

        $this->assertSame(400, $consumption['distance_km']);
        $this->assertSame(40_000, $consumption['volume_ml']);
        $this->assertSame(10.0, $consumption['litres_per_100km']);
    }

    public function test_a_single_fill_reports_no_consumption_rather_than_a_guess(): void
    {
        $vehicle = $this->vehicle(50_000);
        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_000, 50_000)->create();

        $this->assertNull(app(FleetReport::class)->consumptionFor($vehicle)['litres_per_100km']);
    }

    // ---- Costs -----------------------------------------------------------

    public function test_costs_are_grouped_by_currency_and_never_added_across_them(): void
    {
        $vehicle = $this->vehicle(50_000);

        VehicleMaintenanceRecord::factory()->forVehicle($vehicle)
            ->completed(50_100, 400_000)->create(['currency' => 'UGX']);
        VehicleMaintenanceRecord::factory()->forVehicle($vehicle)
            ->completed(50_200, 15_000)->create(['currency' => 'USD']);
        VehicleFuelLog::factory()->forVehicle($vehicle)->at(50_300, 50_000, 250_000)
            ->create(['currency' => 'UGX']);

        $costs = app(FleetReport::class)->costsFor($vehicle);

        $this->assertSame(400_000, $costs['by_currency']['UGX']['maintenance_minor']);
        $this->assertSame(250_000, $costs['by_currency']['UGX']['fuel_minor']);
        $this->assertSame(650_000, $costs['by_currency']['UGX']['total_minor']);
        // UGX and USD have different exponents, so they stay apart.
        $this->assertSame(15_000, $costs['by_currency']['USD']['maintenance_minor']);
        $this->assertSame(15_000, $costs['by_currency']['USD']['total_minor']);
    }

    public function test_only_completed_work_counts_towards_cost(): void
    {
        $vehicle = $this->vehicle(50_000);

        VehicleMaintenanceRecord::factory()->forVehicle($vehicle)
            ->completed(50_100, 400_000)->create(['currency' => 'UGX']);
        // A scheduled job has no real cost yet.
        VehicleMaintenanceRecord::factory()->forVehicle($vehicle)
            ->create(['currency' => 'UGX', 'cost_minor' => 999_999]);

        $costs = app(FleetReport::class)->costsFor($vehicle);

        $this->assertSame(400_000, $costs['by_currency']['UGX']['maintenance_minor']);
        $this->assertSame(1, $costs['by_currency']['UGX']['maintenance_jobs']);
    }

    // ---- Utilisation -----------------------------------------------------

    public function test_utilisation_is_derived_from_bookings_and_capped(): void
    {
        $vehicle = $this->vehicle();
        $now = CarbonImmutable::now();

        CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Confirmed,
            'pickup_at' => $now->subDays(5),
            'return_at' => $now->subDays(2),
            'billable_days' => 3,
        ]);

        $utilisation = app(FleetReport::class)->utilisation($now->subDays(10), $now);

        $this->assertSame(3, $utilisation[$vehicle->getKey()]['days_hired']);
        $this->assertSame(1, $utilisation[$vehicle->getKey()]['bookings']);
        $this->assertSame(30, $utilisation[$vehicle->getKey()]['utilisation_percent']);
    }

    public function test_utilisation_never_exceeds_one_hundred_percent(): void
    {
        $vehicle = $this->vehicle();
        $now = CarbonImmutable::now();

        // A booking can overhang the window at either end; reporting 140%
        // would be nonsense.
        CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Confirmed,
            'pickup_at' => $now->subDays(20),
            'return_at' => $now->addDays(20),
            'billable_days' => 40,
        ]);

        $utilisation = app(FleetReport::class)->utilisation($now->subDays(10), $now);

        $this->assertSame(100, $utilisation[$vehicle->getKey()]['utilisation_percent']);
    }

    // ---- Compliance and alerts -------------------------------------------

    public function test_expiring_and_expired_paperwork_is_reported(): void
    {
        $vehicle = $this->vehicle();

        $this->vehicleDocument($vehicle, DocumentCategory::InsuranceDocument, '2026-08-10');
        $this->vehicleDocument($vehicle, DocumentCategory::VehicleRegistration, '2026-09-05');
        // Well beyond the notice window.
        $this->vehicleDocument($vehicle, DocumentCategory::InsuranceDocument, '2027-06-01');

        $expiring = app(FleetReport::class)->expiringDocuments(30);

        $this->assertCount(2, $expiring);
        $this->assertTrue($expiring[0]['has_expired']);
        $this->assertFalse($expiring[1]['has_expired']);
    }

    public function test_the_alert_sweep_notifies_managers_once(): void
    {
        $vehicle = $this->vehicle(50_000);
        $manager = $this->user(UserRole::Manager, ['two_factor_required' => false]);
        $this->staff();

        VehicleMaintenanceRecord::factory()->forVehicle($vehicle)
            ->completed(40_000)->dueAtOdometer(49_000)->create();
        $this->vehicleDocument($vehicle, DocumentCategory::InsuranceDocument, '2026-08-10');

        $this->artisan('fleet:send-alerts')->assertSuccessful();

        Notification::assertSentTo($manager, FleetAlertNotification::class);

        // A daily sweep must not repeat the same warning until somebody acts.
        $this->artisan('fleet:send-alerts')->assertSuccessful();
        Notification::assertSentToTimes($manager, FleetAlertNotification::class, 1);
    }

    public function test_a_still_expired_document_is_raised_again_after_the_repeat_window(): void
    {
        $vehicle = $this->vehicle();
        $manager = $this->user(UserRole::Manager, ['two_factor_required' => false]);

        $this->vehicleDocument($vehicle, DocumentCategory::InsuranceDocument, '2026-08-10');

        $this->artisan('fleet:send-alerts')->assertSuccessful();
        Notification::assertSentToTimes($manager, FleetAlertNotification::class, 1);

        // Still expired a week later, so it is worth raising again — expired
        // insurance deserves nagging, just not an identical email every morning.
        $this->travelTo(now()->addDays(8));
        $this->artisan('fleet:send-alerts')->assertSuccessful();

        Notification::assertSentToTimes($manager, FleetAlertNotification::class, 2);
    }

    public function test_the_alert_sweep_is_quiet_when_nothing_is_due(): void
    {
        $manager = $this->user(UserRole::Manager, ['two_factor_required' => false]);
        $this->vehicle();

        $this->artisan('fleet:send-alerts')->assertSuccessful();

        Notification::assertNothingSentTo($manager);
    }

    public function test_an_expiring_driver_licence_is_reported_too(): void
    {
        $manager = $this->user(UserRole::Manager, ['two_factor_required' => false]);
        $driver = $this->user(UserRole::Driver);

        DriverProfile::query()->create([
            'user_id' => $driver->getKey(),
            'licence_number' => 'UG-DL-11223344',
            'licence_expires_at' => '2026-08-15',
        ]);

        $this->artisan('fleet:send-alerts')->assertSuccessful();

        // An unlicensed driver is the people-side of the same compliance
        // problem as an uninsured vehicle.
        Notification::assertSentTo(
            $manager,
            FleetAlertNotification::class,
            function (FleetAlertNotification $notification) use ($driver): bool {
                return count($notification->licences) === 1
                    && $notification->licences[0]['driver'] === $driver->name
                    && $notification->licences[0]['expired'] === true;
            },
        );

        // Marked, so the sweep does not repeat it tomorrow.
        $this->artisan('fleet:send-alerts')->assertSuccessful();
        Notification::assertSentToTimes($manager, FleetAlertNotification::class, 1);
    }

    public function test_staff_do_not_receive_fleet_alerts(): void
    {
        $vehicle = $this->vehicle(50_000);
        $staff = $this->staff();
        $this->user(UserRole::Manager, ['two_factor_required' => false]);

        VehicleMaintenanceRecord::factory()->forVehicle($vehicle)
            ->completed(40_000)->dueAtOdometer(49_000)->create();

        $this->artisan('fleet:send-alerts')->assertSuccessful();

        Notification::assertNothingSentTo($staff);
    }

    // ---- HTTP ------------------------------------------------------------

    public function test_staff_reach_the_fleet_console(): void
    {
        $vehicle = $this->vehicle(50_000);

        $this->actingAs($this->staff())
            ->get(route('admin.fleet.index'))
            ->assertOk()
            ->assertSee('Fleet');

        $this->actingAs($this->staff())
            ->get(route('admin.fleet.show', $vehicle))
            ->assertOk()
            ->assertSee('50,000 km');
    }

    public function test_a_customer_cannot_reach_the_fleet_console(): void
    {
        $vehicle = $this->vehicle();
        $customer = $this->user(UserRole::Customer);

        $this->actingAs($customer)->get(route('admin.fleet.index'))->assertForbidden();
        $this->actingAs($customer)->get(route('admin.fleet.show', $vehicle))->assertForbidden();
    }

    public function test_recording_fuel_over_http_advances_the_odometer(): void
    {
        $vehicle = $this->vehicle(50_000);

        $this->actingAs($this->staff())
            ->post(route('admin.fleet.fuel.store', $vehicle), [
                'filled_at' => now()->toDateTimeString(),
                'odometer_km' => 50_600,
                'litres' => 42.5,
                'cost' => '170000',
                'currency' => 'UGX',
            ])
            ->assertRedirect(route('admin.fleet.show', $vehicle));

        $this->assertSame(50_600, $vehicle->fresh()->current_odometer_km);
    }

    public function test_a_backwards_odometer_over_http_is_rejected(): void
    {
        $vehicle = $this->vehicle(50_000);

        $this->actingAs($this->staff())
            ->post(route('admin.fleet.fuel.store', $vehicle), [
                'filled_at' => now()->toDateTimeString(),
                'odometer_km' => 49_000,
                'litres' => 42.5,
                'cost' => '170000',
                'currency' => 'UGX',
            ])
            ->assertSessionHasErrors('odometer_km');

        $this->assertSame(50_000, $vehicle->fresh()->current_odometer_km);
        $this->assertDatabaseCount('vehicle_fuel_logs', 0);
    }

    public function test_scheduling_maintenance_over_http(): void
    {
        $vehicle = $this->vehicle();

        $this->actingAs($this->staff())
            ->post(route('admin.fleet.maintenance.store', $vehicle), [
                'type' => 'service',
                'title' => '20,000 km service',
                'currency' => 'UGX',
                'scheduled_for' => now()->addWeek()->toDateString(),
            ])
            ->assertRedirect(route('admin.fleet.show', $vehicle));

        $this->assertDatabaseHas('vehicle_maintenance_records', [
            'vehicle_id' => $vehicle->getKey(),
            'title' => '20,000 km service',
        ]);
    }
}
