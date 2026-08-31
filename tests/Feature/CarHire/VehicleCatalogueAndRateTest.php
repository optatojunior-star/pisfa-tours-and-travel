<?php

namespace Tests\Feature\CarHire;

use App\Actions\CarHire\SaveVehicle;
use App\Actions\CarHire\SaveVehicleRate;
use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\Vehicle;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class VehicleCatalogueAndRateTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_operations_can_create_a_draft_add_an_exact_rate_and_publish_it(): void
    {
        $actor = $this->operationsUser();
        $vehicle = app(SaveVehicle::class)->execute($actor, $this->vehiclePayload());

        $this->assertSame(VehicleCatalogueStatus::Draft, $vehicle->catalogue_status);
        $this->assertNull($vehicle->published_at);
        $this->assertSame('UBK 001A', $vehicle->registration_plate);
        $this->assertSame($actor->getKey(), $vehicle->created_by_user_id);
        $this->assertSame($actor->getKey(), $vehicle->updated_by_user_id);
        $this->assertCount(1, $vehicle->media);

        $rate = app(SaveVehicleRate::class)->execute($actor, $vehicle, [
            'currency' => 'usd',
            'self_drive_daily' => '1234.56',
            'with_driver_daily' => '1500.00',
            'security_deposit' => '200.25',
            'effective_from' => '2026-08-01 12:00',
        ]);

        $this->assertSame('USD', $rate->currency);
        $this->assertSame(123_456, $rate->self_drive_daily_minor);
        $this->assertSame(150_000, $rate->with_driver_daily_minor);
        $this->assertSame(20_025, $rate->security_deposit_minor);
        $this->assertSame($actor->getKey(), $rate->created_by_user_id);

        $vehicle = app(SaveVehicle::class)->execute($actor, $this->vehiclePayload([
            'catalogue_status' => VehicleCatalogueStatus::Published->value,
        ]), $vehicle);

        $this->assertSame(VehicleCatalogueStatus::Published, $vehicle->catalogue_status);
        $this->assertNotNull($vehicle->published_at);
        $this->assertTrue($vehicle->acceptsHireAt());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'vehicle_hire_rate.created',
            'auditable_id' => $rate->getKey(),
            'user_id' => $actor->getKey(),
        ]);
    }

    public function test_major_and_minor_amounts_must_match_exactly(): void
    {
        $actor = $this->operationsUser();
        $vehicle = Vehicle::factory()->create();

        try {
            app(SaveVehicleRate::class)->execute($actor, $vehicle, [
                'currency' => 'USD',
                'self_drive_daily' => '100.00',
                'self_drive_daily_minor' => 9999,
                'security_deposit_minor' => 0,
                'effective_from' => now()->subDay(),
            ]);
            $this->fail('A mismatched major/minor amount was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('self_drive_daily', $exception->errors());
        }

        $this->assertDatabaseCount('vehicle_hire_rates', 0);
    }

    /** @return array<string, array{string, string}> */
    public static function exactCurrencyCases(): array
    {
        return [
            'UGX rejects decimals' => ['UGX', '1000.25'],
            'USD rejects a third decimal place' => ['USD', '10.001'],
            'unsupported currency is rejected' => ['EUR', '10.00'],
        ];
    }

    #[DataProvider('exactCurrencyCases')]
    public function test_rates_reject_inexact_or_unsupported_currency_values(string $currency, string $amount): void
    {
        try {
            app(SaveVehicleRate::class)->execute($this->operationsUser(), Vehicle::factory()->create(), [
                'currency' => $currency,
                'self_drive_daily' => $amount,
                'effective_from' => now()->subDay(),
            ]);
            $this->fail('An invalid currency amount was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty(array_intersect(
                ['currency', 'self_drive_daily'],
                array_keys($exception->errors()),
            ));
        }
    }

    public function test_future_rate_version_closes_the_prior_interval_without_removing_todays_rate(): void
    {
        $actor = $this->operationsUser();
        $vehicle = Vehicle::factory()->create();
        $prior = app(SaveVehicleRate::class)->execute($actor, $vehicle, [
            'currency' => 'UGX',
            'with_driver_daily_minor' => 400_000,
            'security_deposit_minor' => 100_000,
            'effective_from' => now()->subMonth(),
        ]);
        $futureStart = now()->toImmutable()->addMonth()->startOfHour();
        $future = app(SaveVehicleRate::class)->execute($actor, $vehicle, [
            'currency' => 'UGX',
            'with_driver_daily_minor' => 450_000,
            'security_deposit_minor' => 150_000,
            'effective_from' => $futureStart,
        ]);

        $this->assertTrue($prior->fresh()->effective_until->equalTo($futureStart));
        $this->assertTrue($prior->fresh()->is_active);
        $this->assertTrue($prior->fresh()->isEffectiveAt(now()));
        $this->assertFalse($future->isEffectiveAt(now()));
        $this->assertSame(2, $vehicle->hireRates()->count());
        $this->assertSame(1, $vehicle->hireRates()->effectiveAt(now())->count());
    }

    public function test_rate_versions_must_be_strictly_chronological(): void
    {
        $actor = $this->operationsUser();
        $vehicle = Vehicle::factory()->create();
        app(SaveVehicleRate::class)->execute($actor, $vehicle, [
            'currency' => 'UGX',
            'self_drive_daily_minor' => 300_000,
            'effective_from' => '2026-08-01 12:00',
        ]);

        try {
            app(SaveVehicleRate::class)->execute($actor, $vehicle, [
                'currency' => 'UGX',
                'self_drive_daily_minor' => 350_000,
                'effective_from' => '2026-08-01 12:00',
            ]);
            $this->fail('A duplicate rate start was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('effective_from', $exception->errors());
        }

        $this->assertSame(1, $vehicle->hireRates()->count());
    }

    public function test_publishing_requires_exactly_one_cover_and_a_current_supported_rate(): void
    {
        $actor = $this->operationsUser();
        $vehicle = app(SaveVehicle::class)->execute($actor, $this->vehiclePayload(['media' => []]));

        try {
            app(SaveVehicle::class)->execute($actor, $this->vehiclePayload([
                'catalogue_status' => VehicleCatalogueStatus::Published->value,
                'media' => [],
            ]), $vehicle);
            $this->fail('A vehicle without a cover and rate was published.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty(array_intersect(['media', 'catalogue_status'], array_keys($exception->errors())));
        }

        $this->assertSame(VehicleCatalogueStatus::Draft, $vehicle->fresh()->catalogue_status);
    }

    public function test_media_urls_and_single_cover_invariant_are_enforced(): void
    {
        $actor = $this->operationsUser();

        foreach ([
            [[['url' => 'http://unsafe.example.test/car.jpg', 'is_cover' => true]], 'media.0.url'],
            [[
                ['url' => '/storage/vehicles/one.jpg', 'is_cover' => true],
                ['url' => 'https://cdn.example.test/two.jpg', 'is_cover' => true],
            ], 'media'],
        ] as [$media, $expectedField]) {
            try {
                app(SaveVehicle::class)->execute($actor, $this->vehiclePayload([
                    'registration_plate' => 'UBK '.fake()->unique()->numerify('###').'A',
                    'media' => $media,
                ]));
                $this->fail('Invalid vehicle media was accepted.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey($expectedField, $exception->errors());
            }
        }
    }

    public function test_archived_vehicle_must_return_to_draft_before_publication(): void
    {
        $actor = $this->operationsUser();
        [$vehicle] = $this->bookableVehicle();
        $vehicle = app(SaveVehicle::class)->execute($actor, $this->vehiclePayload([
            'catalogue_status' => VehicleCatalogueStatus::Archived->value,
        ]), $vehicle);

        try {
            app(SaveVehicle::class)->execute($actor, $this->vehiclePayload([
                'catalogue_status' => VehicleCatalogueStatus::Published->value,
            ]), $vehicle);
            $this->fail('An archived vehicle was directly republished.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('catalogue_status', $exception->errors());
        }

        $draft = app(SaveVehicle::class)->execute($actor, $this->vehiclePayload([
            'catalogue_status' => VehicleCatalogueStatus::Draft->value,
        ]), $vehicle);
        $this->assertSame(VehicleCatalogueStatus::Draft, $draft->catalogue_status);
    }

    /** @return array<string, array{UserRole, AccountStatus}> */
    public static function unauthorizedActors(): array
    {
        return [
            'customer' => [UserRole::Customer, AccountStatus::Active],
            'driver' => [UserRole::Driver, AccountStatus::Active],
            'inactive staff' => [UserRole::Staff, AccountStatus::Inactive],
            'suspended manager' => [UserRole::Manager, AccountStatus::Suspended],
        ];
    }

    #[DataProvider('unauthorizedActors')]
    public function test_only_active_operations_roles_can_mutate_catalogue(
        UserRole $role,
        AccountStatus $status,
    ): void {
        try {
            app(SaveVehicle::class)->execute(
                $this->user($role, ['status' => $status]),
                $this->vehiclePayload(),
            );
            $this->fail('An unauthorized actor created a vehicle.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('vehicles', 0);
        }
    }

    /** @return array<string, mixed> */
    private function vehiclePayload(array $overrides = []): array
    {
        return array_merge([
            'slug' => 'toyota-land-cruiser-2024',
            'registration_plate' => 'ubk   001a',
            'make' => 'Toyota',
            'model' => 'Land Cruiser',
            'year' => 2024,
            'color' => 'Pearl white',
            'condition' => 'excellent',
            'vehicle_type' => 'suv',
            'fuel_type' => 'diesel',
            'transmission' => 'automatic',
            'seating_capacity' => 7,
            'luggage_capacity' => 5,
            'summary' => 'A comfortable seven-seat vehicle for road trips and safaris.',
            'description' => 'Maintained by PISFA and inspected before every handover.',
            'catalogue_status' => VehicleCatalogueStatus::Draft->value,
            'operational_status' => VehicleOperationalStatus::Available->value,
            'is_featured' => false,
            'media' => [[
                'url' => '/storage/vehicles/land-cruiser.jpg',
                'alt_text' => 'White Toyota Land Cruiser',
                'is_cover' => true,
                'sort_order' => 0,
            ]],
        ], $overrides);
    }
}
