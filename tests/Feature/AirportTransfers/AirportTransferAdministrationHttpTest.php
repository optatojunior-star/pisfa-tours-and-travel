<?php

namespace Tests\Feature\AirportTransfers;

use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferType;
use App\Enums\VehicleOperationalStatus;
use App\Models\Airport;
use App\Models\AirportTransferLocation;
use App\Models\AirportTransferRate;
use App\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\AirportTransfers\Concerns\BuildsAirportTransferFixtures;
use Tests\TestCase;

class AirportTransferAdministrationHttpTest extends TestCase
{
    use BuildsAirportTransferFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    public function test_the_console_filters_by_status_route_and_assignment_state(): void
    {
        [$airport, $location] = $this->bookableRoute();
        [$otherAirport, $otherLocation] = $this->bookableRoute();
        $staff = $this->operationsUser();

        $onRoute = $this->createBooking($this->customer(), $airport, $location);
        $elsewhere = $this->createBooking($this->customer(), $otherAirport, $otherLocation);

        $this->actingAs($staff)
            ->get(route('admin.airport-transfer-bookings.index', ['airport_id' => $airport->getKey()]))
            ->assertOk()
            ->assertSee($onRoute->reference)
            ->assertDontSee($elsewhere->reference);

        $this->actingAs($staff)
            ->get(route('admin.airport-transfer-bookings.index', ['assignment' => 'unassigned']))
            ->assertOk()
            ->assertSee($onRoute->reference)
            ->assertSee('Needs assignment');

        $this->actingAs($staff)
            ->get(route('admin.airport-transfer-bookings.index', [
                'status' => AirportTransferBookingStatus::Completed->value,
            ]))
            ->assertOk()
            ->assertSee('No airport transfers match these filters');
    }

    public function test_staff_assign_a_vehicle_and_driver_then_confirm_the_transfer(): void
    {
        [$airport, $location, $rate] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $customer = $this->customer();
        $driver = $this->driver();
        $vehicle = Vehicle::factory()->create([
            'operational_status' => VehicleOperationalStatus::Available,
            'vehicle_type' => 'sedan',
            'seating_capacity' => 6,
            'luggage_capacity' => 6,
        ]);
        $booking = $this->createBooking($customer, $airport, $location);

        $this->actingAs($staff)
            ->patch(route('admin.airport-transfer-bookings.assignment', $booking), [
                'vehicle_id' => $vehicle->getKey(),
                'driver_user_id' => $driver->getKey(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame($vehicle->getKey(), $booking->assigned_vehicle_id);
        $this->assertSame($driver->getKey(), $booking->assigned_driver_user_id);
        $this->assertDatabaseHas('airport_transfer_assignments', [
            'airport_transfer_booking_id' => $booking->getKey(),
            'vehicle_id' => $vehicle->getKey(),
            'driver_user_id' => $driver->getKey(),
            'unassigned_at' => null,
        ]);

        $this->actingAs($staff)
            ->patch(route('admin.airport-transfer-bookings.transition', $booking), [
                'status' => AirportTransferBookingStatus::Confirmed->value,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame(AirportTransferBookingStatus::Confirmed, $booking->status);
        $this->assertNotNull($booking->confirmed_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'airport_transfer_booking.status_changed',
            'auditable_id' => $booking->getKey(),
        ]);
    }

    public function test_confirmation_is_refused_until_a_vehicle_and_driver_are_assigned(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $booking = $this->createBooking($this->customer(), $airport, $location);

        $this->actingAs($staff)
            ->from(route('admin.airport-transfer-bookings.show', $booking))
            ->patch(route('admin.airport-transfer-bookings.transition', $booking), [
                'status' => AirportTransferBookingStatus::Confirmed->value,
            ])
            ->assertSessionHasErrors('assignment');

        $this->assertSame(
            AirportTransferBookingStatus::Pending,
            $booking->refresh()->status,
        );
    }

    public function test_an_invalid_status_jump_is_rejected(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $booking = $this->createBooking($this->customer(), $airport, $location);

        // Pending may not skip straight to completed.
        $this->actingAs($staff)
            ->from(route('admin.airport-transfer-bookings.show', $booking))
            ->patch(route('admin.airport-transfer-bookings.transition', $booking), [
                'status' => AirportTransferBookingStatus::Completed->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(AirportTransferBookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_declining_requires_a_reason_and_releases_the_assigned_team(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $driver = $this->driver();
        $vehicle = Vehicle::factory()->create([
            'vehicle_type' => 'sedan',
            'seating_capacity' => 6,
            'luggage_capacity' => 6,
        ]);
        $booking = $this->createBooking($this->customer(), $airport, $location);

        $this->actingAs($staff)->patch(route('admin.airport-transfer-bookings.assignment', $booking), [
            'vehicle_id' => $vehicle->getKey(),
            'driver_user_id' => $driver->getKey(),
        ])->assertRedirect();

        $this->actingAs($staff)
            ->from(route('admin.airport-transfer-bookings.show', $booking))
            ->patch(route('admin.airport-transfer-bookings.transition', $booking), [
                'status' => AirportTransferBookingStatus::Declined->value,
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($staff)
            ->patch(route('admin.airport-transfer-bookings.transition', $booking->refresh()), [
                'status' => AirportTransferBookingStatus::Declined->value,
                'reason' => 'No vehicle is available for that corridor.',
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(AirportTransferBookingStatus::Declined, $booking->status);
        $this->assertNull($booking->assigned_driver_user_id);
        $this->assertNull($booking->assigned_vehicle_id);
        $this->assertNotNull($booking->declined_at);
        $this->assertDatabaseMissing('airport_transfer_assignments', [
            'airport_transfer_booking_id' => $booking->getKey(),
            'unassigned_at' => null,
        ]);
    }

    public function test_staff_publish_an_airport_a_location_and_a_rate_version(): void
    {
        $staff = $this->operationsUser();

        $this->actingAs($staff)
            ->post(route('admin.airport-transfer-settings.airports.store'), [
                'code' => 'EBB',
                'name' => 'Entebbe International Airport',
                'city' => 'Entebbe',
                'country_code' => 'UG',
                'timezone' => 'Africa/Kampala',
                'is_active' => '1',
                'sort_order' => 0,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $airport = Airport::query()->where('code', 'EBB')->sole();

        $this->actingAs($staff)
            ->post(route('admin.airport-transfer-settings.locations.store'), [
                'name' => 'Kampala Central',
                'region' => 'Kampala',
                'is_active' => '1',
                'sort_order' => 0,
            ])
            ->assertRedirect();

        $location = AirportTransferLocation::query()->where('name', 'Kampala Central')->sole();

        $this->actingAs($staff)
            ->post(route('admin.airport-transfer-settings.rates.store'), [
                'airport_id' => $airport->getKey(),
                'airport_transfer_location_id' => $location->getKey(),
                'transfer_type' => AirportTransferType::Pickup->value,
                'vehicle_type' => 'sedan',
                'currency' => 'UGX',
                'passenger_capacity' => 4,
                'luggage_capacity' => 4,
                'amount' => '250000',
                'estimated_duration_minutes' => 90,
                'effective_from' => CarbonImmutable::now(config('pisfa.business_timezone'))
                    ->addHour()
                    ->format('Y-m-d\TH:i'),
                'is_active' => '1',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $rate = AirportTransferRate::query()->sole();
        $this->assertSame(250_000, $rate->amount_minor);
        $this->assertSame('UGX', $rate->currency);
        $this->assertTrue($rate->is_active);

        $this->assertDatabaseHas('audit_logs', ['event' => 'airport.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'airport_transfer_location.created']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'airport_transfer_rate.created']);
    }

    public function test_deactivating_an_airport_removes_it_from_the_public_planner(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();

        $this->get(route('airport-transfers.index'))->assertOk()->assertSee($airport->code);

        $this->actingAs($staff)
            ->patch(route('admin.airport-transfer-settings.airports.status', $airport), ['is_active' => '0'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse($airport->refresh()->is_active);
        $this->get(route('airport-transfers.index'))->assertOk()->assertDontSee($airport->code);
    }

    public function test_a_rate_cannot_be_activated_while_it_overlaps_another_active_version(): void
    {
        [$airport, $location, $rate] = $this->bookableRoute();
        $staff = $this->operationsUser();

        $overlapping = AirportTransferRate::factory()->inactive()->create([
            'airport_id' => $airport->getKey(),
            'airport_transfer_location_id' => $location->getKey(),
            'transfer_type' => $rate->transfer_type,
            'vehicle_type' => $rate->vehicle_type,
            'currency' => $rate->currency,
            'effective_from' => $rate->effective_from->addDay(),
            'effective_until' => null,
        ]);

        $this->actingAs($staff)
            ->from(route('admin.airport-transfer-settings.index'))
            ->patch(route('admin.airport-transfer-settings.rates.status', $overlapping), ['is_active' => '1'])
            ->assertSessionHasErrors('is_active');

        $this->assertFalse($overlapping->refresh()->is_active);
    }

    public function test_rescheduling_moves_the_service_window_and_records_an_audit_entry(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $booking = $this->createBooking($this->customer(), $airport, $location);
        $newStart = CarbonImmutable::now(config('pisfa.business_timezone'))
            ->addDays(14)
            ->startOfHour();

        $this->actingAs($staff)
            ->patch(route('admin.airport-transfer-bookings.reschedule', $booking), [
                'service_starts_at' => $newStart->format('Y-m-d\TH:i'),
                'flight_number' => 'KQ 202',
                'reason' => 'Customer moved to a later flight.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame(
            $newStart->utc()->toDateTimeString(),
            $booking->service_starts_at->utc()->toDateTimeString(),
        );
        $this->assertSame('KQ 202', $booking->flight_number);
    }

    public function test_the_detail_screen_shows_assignment_history_and_available_resources(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $driver = $this->driver(['name' => 'Moses Okello']);
        Vehicle::factory()->create([
            'make' => 'Toyota',
            'model' => 'Alphard',
            'vehicle_type' => 'sedan',
            'seating_capacity' => 6,
            'luggage_capacity' => 6,
        ]);
        $booking = $this->createBooking($this->customer(), $airport, $location);

        $this->actingAs($staff)
            ->get(route('admin.airport-transfer-bookings.show', $booking))
            ->assertOk()
            ->assertSee($booking->reference)
            ->assertSee('Moses Okello')
            ->assertSee('Alphard')
            ->assertSee('No vehicle or driver has been assigned');
    }
}
