<?php

namespace Tests\Feature\AirportTransfers;

use App\Actions\AirportTransfers\AssignAirportTransferResources;
use App\Actions\AirportTransfers\TransitionAirportTransferBooking;
use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferEventType;
use App\Enums\VehicleOperationalStatus;
use App\Models\AirportTransferBooking;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\AirportTransfers\Concerns\BuildsAirportTransferFixtures;
use Tests\TestCase;

class AirportTransferPortalAndLifecycleTest extends TestCase
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

    public function test_the_portal_shows_an_empty_state_before_any_request(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.index'))
            ->assertOk()
            ->assertSee('No transfers found')
            ->assertSee(route('airport-transfers.index'));
    }

    public function test_the_portal_lists_and_filters_only_the_signed_in_customers_transfers(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $customer = $this->customer();
        $stranger = $this->customer();

        $mine = $this->createBooking($customer, $airport, $location);
        $theirs = $this->createBooking($stranger, $airport, $location);

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.index'))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.index', ['period' => 'past']))
            ->assertOk()
            ->assertDontSee($mine->reference);

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.index', ['q' => $mine->reference]))
            ->assertOk()
            ->assertSee($mine->reference);
    }

    public function test_a_customer_cancels_their_own_pending_transfer_with_a_reason(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $customer = $this->customer();
        $booking = $this->createBooking($customer, $airport, $location);

        $this->actingAs($customer)
            ->from(route('portal.airport-transfer-bookings.show', $booking))
            ->patch(route('portal.airport-transfer-bookings.cancel', $booking), [
                'confirm_cancellation' => '1',
            ])
            ->assertSessionHasErrors('reason');

        $this->actingAs($customer)
            ->patch(route('portal.airport-transfer-bookings.cancel', $booking), [
                'reason' => 'Flight was rescheduled by the airline.',
                'confirm_cancellation' => '1',
            ])
            ->assertRedirect(route('portal.airport-transfer-bookings.show', $booking))
            ->assertSessionHas('success');

        $booking->refresh();
        $this->assertSame(AirportTransferBookingStatus::Cancelled, $booking->status);
        $this->assertSame($customer->getKey(), $booking->cancelled_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'airport_transfer_booking.cancelled',
            'auditable_id' => $booking->getKey(),
        ]);
    }

    public function test_a_customer_cannot_cancel_after_the_persisted_cutoff(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $customer = $this->customer();
        $booking = $this->createBooking($customer, $airport, $location);

        $this->travelTo($booking->cancellation_cutoff_at->addMinute());

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.show', $booking))
            ->assertOk()
            ->assertSee('can no longer be cancelled online');

        $this->actingAs($customer)
            ->from(route('portal.airport-transfer-bookings.show', $booking))
            ->patch(route('portal.airport-transfer-bookings.cancel', $booking), [
                'reason' => 'Trying after the deadline has passed.',
                'confirm_cancellation' => '1',
            ])
            ->assertSessionHasErrors('booking');

        $this->assertSame(AirportTransferBookingStatus::Pending, $booking->refresh()->status);
    }

    public function test_the_full_lifecycle_completes_and_records_exactly_one_loyalty_event(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $driver = $this->driver();
        $vehicle = Vehicle::factory()->create([
            'operational_status' => VehicleOperationalStatus::Available,
            'vehicle_type' => 'sedan',
            'seating_capacity' => 6,
            'luggage_capacity' => 6,
        ]);
        $booking = $this->createBooking($this->customer(), $airport, $location);

        app(AssignAirportTransferResources::class)->execute($staff, $booking, $vehicle, $driver);
        $booking->refresh();

        $transition = app(TransitionAirportTransferBooking::class);
        $transition->execute($staff, $booking, AirportTransferBookingStatus::Confirmed);
        $booking->refresh();

        // A confirmed transfer cannot start before its service time.
        try {
            $transition->execute($staff, $booking, AirportTransferBookingStatus::InProgress);
            $this->fail('An early start should have been rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->travelTo($booking->service_starts_at->addMinutes(5));
        $transition->execute($staff, $booking->refresh(), AirportTransferBookingStatus::InProgress);
        $booking->refresh();
        $this->assertSame(AirportTransferBookingStatus::InProgress, $booking->status);
        $this->assertNotNull($booking->in_progress_at);

        $this->travelTo($booking->service_ends_at->addMinutes(5));
        $transition->execute($staff, $booking->refresh(), AirportTransferBookingStatus::Completed);
        $booking->refresh();

        $this->assertSame(AirportTransferBookingStatus::Completed, $booking->status);
        $this->assertNotNull($booking->completed_at);
        $this->assertNull($booking->assigned_driver_user_id);
        $this->assertNull($booking->assigned_vehicle_id);
        $this->assertDatabaseCount('airport_transfer_events', 1);
        $this->assertDatabaseHas('airport_transfer_events', [
            'airport_transfer_booking_id' => $booking->getKey(),
            'event_type' => AirportTransferEventType::LoyaltyEligible->value,
            'processed_at' => null,
        ]);
    }

    public function test_repeating_a_completed_transition_is_a_no_op_rather_than_a_second_award(): void
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

        app(AssignAirportTransferResources::class)->execute($staff, $booking, $vehicle, $driver);
        $transition = app(TransitionAirportTransferBooking::class);
        $transition->execute($staff, $booking->refresh(), AirportTransferBookingStatus::Confirmed);

        $this->travelTo($booking->refresh()->service_starts_at->addMinutes(5));
        $transition->execute($staff, $booking->refresh(), AirportTransferBookingStatus::InProgress);

        $this->travelTo($booking->refresh()->service_ends_at->addMinutes(5));
        $transition->execute($staff, $booking->refresh(), AirportTransferBookingStatus::Completed);
        $transition->execute($staff, $booking->refresh(), AirportTransferBookingStatus::Completed);

        $this->assertDatabaseCount('airport_transfer_events', 1);
        $this->assertSame(
            AirportTransferBookingStatus::Completed,
            AirportTransferBooking::query()->sole()->status,
        );
    }

    public function test_a_terminal_transfer_cannot_be_reopened_by_a_status_request(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $booking = $this->createBooking($this->customer(), $airport, $location);

        app(TransitionAirportTransferBooking::class)->execute(
            $staff,
            $booking,
            AirportTransferBookingStatus::Declined,
            'Route not served that morning.',
        );

        $this->actingAs($staff)
            ->get(route('admin.airport-transfer-bookings.show', $booking->refresh()))
            ->assertOk()
            ->assertSee('is a terminal status');

        $this->actingAs($staff)
            ->from(route('admin.airport-transfer-bookings.show', $booking))
            ->patch(route('admin.airport-transfer-bookings.transition', $booking), [
                'status' => AirportTransferBookingStatus::Confirmed->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(AirportTransferBookingStatus::Declined, $booking->refresh()->status);
    }
}
