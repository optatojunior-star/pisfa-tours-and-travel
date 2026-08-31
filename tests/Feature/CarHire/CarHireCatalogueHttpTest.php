<?php

namespace Tests\Feature\CarHire;

use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Models\CarHireBooking;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class CarHireCatalogueHttpTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_public_catalogue_and_detail_only_expose_hire_ready_vehicles_with_a_current_rate(): void
    {
        [$visible] = $this->bookableVehicle(['make' => 'Visible', 'model' => 'Cruiser']);

        $draft = Vehicle::factory()->create([
            'make' => 'Draft',
            'model' => 'Vehicle',
            'catalogue_status' => VehicleCatalogueStatus::Draft,
        ]);
        $this->currentRate($draft);

        $future = Vehicle::factory()->scheduledForPublication()->create([
            'make' => 'Future',
            'model' => 'Vehicle',
        ]);
        $this->currentRate($future);

        $maintenance = $this->publishedVehicle([
            'make' => 'Maintenance',
            'model' => 'Vehicle',
            'operational_status' => VehicleOperationalStatus::Maintenance,
        ]);
        $this->currentRate($maintenance);

        $withoutRate = $this->publishedVehicle([
            'make' => 'Unpriced',
            'model' => 'Vehicle',
        ]);

        $this->get(route('car-hire.index'))
            ->assertOk()
            ->assertViewHas('vehicles', fn (LengthAwarePaginator $vehicles): bool => $vehicles
                ->getCollection()->modelKeys() === [$visible->getKey()])
            ->assertSee('Visible Cruiser')
            ->assertDontSee('Draft Vehicle')
            ->assertDontSee('Future Vehicle')
            ->assertDontSee('Maintenance Vehicle')
            ->assertDontSee('Unpriced Vehicle')
            ->assertSee('no payment is taken online');

        $this->get(route('car-hire.show', $visible))
            ->assertOk()
            ->assertSee('Visible Cruiser')
            ->assertSee('Submitting a request does not charge you.');

        foreach ([$draft, $future, $maintenance, $withoutRate] as $hidden) {
            $this->get(route('car-hire.show', $hidden))->assertNotFound();
        }
    }

    public function test_interval_availability_price_sort_and_cards_use_the_selected_covering_rate(): void
    {
        $pickup = now()->toImmutable()->addDays(10)->startOfHour();
        $return = $pickup->addDays(3);

        $cheap = $this->publishedVehicle(['make' => 'Affordable', 'model' => 'Safari']);
        $cheapCoveringRate = $this->currentRate($cheap, [
            'self_drive_daily_minor' => 200_000,
            'effective_from' => now()->subMonth(),
            'effective_until' => $return->addDay(),
        ]);
        $this->currentRate($cheap, [
            'self_drive_daily_minor' => 999_000,
            'effective_from' => now()->subDay(),
            'effective_until' => $pickup->addDay(),
        ]);

        $middle = $this->publishedVehicle(['make' => 'Middle', 'model' => 'Safari']);
        $middleRate = $this->currentRate($middle, ['self_drive_daily_minor' => 300_000]);

        $blocked = $this->publishedVehicle(['make' => 'Blocked', 'model' => 'Safari']);
        $blockedRate = $this->currentRate($blocked, ['self_drive_daily_minor' => 100_000]);
        $this->persistedBooking(
            $this->customer(),
            $blocked,
            $blockedRate,
            CarHireBookingStatus::Confirmed,
            HireMode::SelfDrive,
            ['pickup_at' => $pickup, 'return_at' => $return],
        );

        $query = [
            'pickup_at' => $pickup->setTimezone(config('pisfa.business_timezone'))->format('Y-m-d\TH:i'),
            'return_at' => $return->setTimezone(config('pisfa.business_timezone'))->format('Y-m-d\TH:i'),
            'hire_mode' => HireMode::SelfDrive->value,
            'currency' => 'UGX',
            'sort' => 'price_asc',
        ];

        $this->get(route('car-hire.index', $query))
            ->assertOk()
            ->assertViewHas('vehicles', function (LengthAwarePaginator $vehicles) use ($cheap, $middle): bool {
                $items = $vehicles->getCollection();

                return $items->modelKeys() === [$cheap->getKey(), $middle->getKey()]
                    && (int) $items->first()->getAttribute('catalogue_daily_minor') === 200_000
                    && (int) $items->last()->getAttribute('catalogue_daily_minor') === 300_000;
            })
            ->assertSee('Affordable Safari')
            ->assertSee('Middle Safari')
            ->assertDontSee('Blocked Safari')
            ->assertSee('UGX 200,000')
            ->assertSee('UGX 300,000');

        $this->assertSame($cheapCoveringRate->getKey(), $cheap->fresh()->hireRates()
            ->coveringInterval($pickup, $return)
            ->where('currency', 'UGX')
            ->whereNotNull('self_drive_daily_minor')
            ->latest('effective_from')
            ->value('id'));
        $this->assertSame(300_000, $middleRate->self_drive_daily_minor);
    }

    public function test_price_comparisons_require_an_explicit_mode_and_currency(): void
    {
        $this->from(route('car-hire.index'))
            ->get(route('car-hire.index', ['sort' => 'price_asc']))
            ->assertRedirect(route('car-hire.index'))
            ->assertSessionHasErrors(['hire_mode', 'currency']);
    }

    public function test_http_booking_uses_authenticated_customer_and_server_owned_snapshots(): void
    {
        Notification::fake();
        $customer = $this->customer([
            'name' => 'Amina Customer',
            'email' => 'amina-car-hire@example.test',
        ]);
        $attacker = $this->customer();
        [$vehicle, $rate] = $this->bookableVehicle(
            ['make' => 'Secure', 'model' => 'Cruiser', 'year' => 2025],
            [
                'currency' => 'UGX',
                'with_driver_daily_minor' => 450_000,
                'security_deposit_minor' => 500_000,
            ],
        );

        $this->actingAs($customer)
            ->get(route('car-hire-bookings.create', $vehicle))
            ->assertOk()
            ->assertSee('No payment is collected now.');

        $payload = $this->bookingPayload($customer, HireMode::WithDriver, [
            'idempotency_key' => (string) Str::uuid(),
            'pickup_at' => now()->addDays(10)->setTimezone(config('pisfa.business_timezone'))->format('Y-m-d\TH:i'),
            'return_at' => now()->addDays(13)->setTimezone(config('pisfa.business_timezone'))->format('Y-m-d\TH:i'),
            'customer_id' => $attacker->getKey(),
            'status' => CarHireBookingStatus::Completed->value,
            'vehicle_hire_rate_id' => -1,
            'daily_rate_minor' => 1,
            'rental_subtotal_minor' => 1,
            'security_deposit_minor' => 1,
            'total_minor' => 1,
            'vehicle_name_snapshot' => 'Forged vehicle',
        ]);

        $response = $this->actingAs($customer)
            ->post(route('car-hire-bookings.store', $vehicle), $payload);

        $booking = CarHireBooking::query()->sole();
        $response
            ->assertRedirect(route('portal.car-hire-bookings.show', $booking))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $this->assertSame($customer->getKey(), $booking->customer_id);
        $this->assertSame($vehicle->getKey(), $booking->vehicle_id);
        $this->assertSame($rate->getKey(), $booking->vehicle_hire_rate_id);
        $this->assertSame(CarHireBookingStatus::Pending, $booking->status);
        $this->assertSame('2025 Secure Cruiser', $booking->vehicle_name_snapshot);
        $this->assertSame(450_000, $booking->daily_rate_minor);
        $this->assertSame(1_350_000, $booking->rental_subtotal_minor);
        $this->assertSame(500_000, $booking->security_deposit_minor);
        $this->assertSame(1_850_000, $booking->total_minor);
        $this->assertSame('UGX', $booking->currency);
        $this->assertNotSame('Forged vehicle', $booking->vehicle_name_snapshot);
    }
}
