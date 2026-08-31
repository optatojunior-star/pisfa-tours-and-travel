<?php

namespace Tests\Feature\Tours;

use App\Actions\Tours\CreateTourBooking;
use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageStatus;
use App\Enums\UserRole;
use App\Notifications\Tours\TourBookingReceivedNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class BookingCreationTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_sensitive_fields_prices_and_snapshots_are_owned_by_the_server(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $attacker = $this->customer();
        $package = $this->publishedTour([
            'name' => 'Rwenzori Explorer',
            'destination' => 'Kasese',
            'base_price_minor' => 900_000,
            'currency' => 'UGX',
        ]);
        $departure = $this->bookableDeparture($package, [
            'price_override_minor' => 12_345,
            'currency' => 'USD',
        ]);
        $originalStartsAt = $departure->starts_at;
        $originalEndsAt = $departure->ends_at;
        $originalCutoff = $departure->cancellation_cutoff_at;

        $payload = $this->bookingPayload($customer, 2, [
            'currency' => 'usd',
            'reference' => 'ATTACKER-REFERENCE',
            'customer_id' => $attacker->getKey(),
            'status' => TourBookingStatus::Confirmed->value,
            'unit_price_minor' => 1,
            'subtotal_minor' => 1,
            'total_minor' => 1,
            'package_name_snapshot' => 'Tampered name',
            'destination_snapshot' => 'Tampered destination',
            'assigned_driver_user_id' => $attacker->getKey(),
        ]);

        $booking = app(CreateTourBooking::class)->execute(
            $customer,
            $departure,
            $payload,
            'checkout-sensitive-fields-001',
        );

        $this->assertMatchesRegularExpression('/^TOUR-[0-9A-Z]{26}$/', $booking->reference);
        $this->assertNotSame('ATTACKER-REFERENCE', $booking->reference);
        $this->assertSame($customer->getKey(), $booking->customer_id);
        $this->assertSame(TourBookingStatus::Pending, $booking->status);
        $this->assertNull($booking->assigned_driver_user_id);
        $this->assertSame(12_345, $booking->unit_price_minor);
        $this->assertSame(24_690, $booking->subtotal_minor);
        $this->assertSame(24_690, $booking->total_minor);
        $this->assertSame('USD', $booking->currency);
        $this->assertSame('Rwenzori Explorer', $booking->package_name_snapshot);
        $this->assertSame('Kasese', $booking->destination_snapshot);
        $this->assertTrue($booking->departure_starts_at_snapshot->equalTo($originalStartsAt));
        $this->assertTrue($booking->departure_ends_at_snapshot->equalTo($originalEndsAt));
        $this->assertTrue($booking->cancellation_cutoff_at_snapshot->equalTo($originalCutoff));
        $this->assertCount(2, $booking->travelers);
        $this->assertTrue($booking->travelers->first()->is_lead);

        $package->update(['name' => 'Changed later', 'destination' => 'Changed later']);
        $departure->update([
            'starts_at' => $departure->starts_at->addMonth(),
            'ends_at' => $departure->ends_at->addMonth(),
            'price_override_minor' => 99_999,
        ]);

        $booking->refresh();
        $this->assertSame('Rwenzori Explorer', $booking->package_name_snapshot);
        $this->assertSame('Kasese', $booking->destination_snapshot);
        $this->assertSame(12_345, $booking->unit_price_minor);
        $this->assertTrue($booking->departure_starts_at_snapshot->equalTo($originalStartsAt));

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_booking.created',
            'auditable_id' => $booking->getKey(),
            'user_id' => $customer->getKey(),
        ]);
        Notification::assertSentToTimes($customer, TourBookingReceivedNotification::class, 1);
    }

    /** @return array<string, array{UserRole, AccountStatus, bool}> */
    public static function unauthorizedCustomers(): array
    {
        return [
            'staff role' => [UserRole::Staff, AccountStatus::Active, true],
            'driver role' => [UserRole::Driver, AccountStatus::Active, true],
            'inactive customer' => [UserRole::Customer, AccountStatus::Inactive, true],
            'suspended customer' => [UserRole::Customer, AccountStatus::Suspended, true],
            'unverified customer' => [UserRole::Customer, AccountStatus::Active, false],
        ];
    }

    #[DataProvider('unauthorizedCustomers')]
    public function test_only_active_verified_customer_accounts_can_book(
        UserRole $role,
        AccountStatus $status,
        bool $verified,
    ): void {
        $customer = $this->user($role, [
            'status' => $status,
            'email_verified_at' => $verified ? now() : null,
        ]);
        $departure = $this->bookableDeparture();

        try {
            app(CreateTourBooking::class)->execute(
                $customer,
                $departure,
                $this->bookingPayload($customer),
                'unauthorized-customer-001',
            );
            $this->fail('An unauthorized account created a booking.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('tour_bookings', 0);
            $this->assertDatabaseCount('tour_travelers', 0);
        }
    }

    /** @return array<string, array{string}> */
    public static function unavailableDepartureCases(): array
    {
        return [
            'draft package' => ['draft'],
            'future publication' => ['future-publication'],
            'inactive category' => ['inactive-category'],
            'closed departure' => ['closed'],
            'past departure' => ['past'],
            'elapsed cutoff' => ['elapsed-cutoff'],
        ];
    }

    #[DataProvider('unavailableDepartureCases')]
    public function test_only_currently_published_active_and_bookable_departures_are_accepted(string $case): void
    {
        $customer = $this->customer();
        $departure = $this->bookableDeparture();

        match ($case) {
            'draft' => $departure->tourPackage->update([
                'status' => TourPackageStatus::Draft,
                'published_at' => null,
            ]),
            'future-publication' => $departure->tourPackage->update(['published_at' => now()->addDay()]),
            'inactive-category' => $departure->tourPackage->category->update(['is_active' => false]),
            'closed' => $departure->update(['status' => TourDepartureStatus::Closed]),
            'past' => $departure->update([
                'starts_at' => now()->subDay(),
                'ends_at' => now()->addDay(),
                'cancellation_cutoff_at' => now()->subDays(2),
            ]),
            'elapsed-cutoff' => $departure->update(['cancellation_cutoff_at' => now()->subMinute()]),
        };

        try {
            app(CreateTourBooking::class)->execute(
                $customer,
                $departure->fresh(),
                $this->bookingPayload($customer),
                'unavailable-departure-001',
            );
            $this->fail('An unavailable departure accepted a booking.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('departure', $exception->errors());
            $this->assertDatabaseCount('tour_bookings', 0);
        }
    }

    /** @return array<string, array{int, int}> */
    public static function invalidTravelerCounts(): array
    {
        return [
            'below package minimum' => [1, 1],
            'above package maximum' => [4, 4],
            'declared count differs from list' => [2, 3],
        ];
    }

    #[DataProvider('invalidTravelerCounts')]
    public function test_package_limits_and_derived_traveler_count_are_enforced(int $actualCount, int $declaredCount): void
    {
        $customer = $this->customer();
        $package = $this->publishedTour(['min_travelers' => 2, 'max_travelers' => 3]);
        $departure = $this->bookableDeparture($package);

        try {
            app(CreateTourBooking::class)->execute(
                $customer,
                $departure,
                $this->bookingPayload($customer, $actualCount, ['traveler_count' => $declaredCount]),
                'invalid-traveler-count-001',
            );
            $this->fail('An invalid traveler count was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty(array_intersect(['travelers', 'traveler_count'], array_keys($exception->errors())));
            $this->assertDatabaseCount('tour_bookings', 0);
            $this->assertDatabaseCount('tour_travelers', 0);
        }
    }

    public function test_booking_action_accepts_the_configured_fifty_traveler_cap_and_date_of_birth_today(): void
    {
        Notification::fake();

        $maximum = (int) config('tours.maximum_booking_travelers');
        $this->assertSame(50, $maximum);
        $package = $this->publishedTour([
            'min_travelers' => 1,
            'max_travelers' => $maximum,
        ]);
        $departure = $this->bookableDeparture($package, ['capacity' => 500]);
        $customer = $this->customer();
        $payload = $this->bookingPayload($customer, $maximum);
        $payload['travelers'][0]['date_of_birth'] = now()->toDateString();

        $booking = app(CreateTourBooking::class)->execute(
            $customer,
            $departure,
            $payload,
            'canonical-fifty-travelers',
        );

        $this->assertSame(50, $booking->traveler_count);
        $this->assertCount(50, $booking->travelers);
        $this->assertSame(now()->toDateString(), $booking->travelers->first()->date_of_birth?->toDateString());

        $overflowCustomer = $this->customer();
        try {
            app(CreateTourBooking::class)->execute(
                $overflowCustomer,
                $departure,
                $this->bookingPayload($overflowCustomer, $maximum + 1),
                'canonical-fifty-one-travelers',
            );
            $this->fail('The booking action accepted more than 50 travelers.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('travelers', $exception->errors());
            $this->assertDatabaseCount('tour_bookings', 1);
        }
    }

    public function test_sequential_requests_cannot_reserve_more_than_departure_capacity(): void
    {
        $departure = $this->bookableDeparture(attributes: ['capacity' => 3]);
        $firstCustomer = $this->customer();
        $secondCustomer = $this->customer();
        $action = app(CreateTourBooking::class);

        $action->execute(
            $firstCustomer,
            $departure,
            $this->bookingPayload($firstCustomer, 2),
            'capacity-first-request',
        );

        try {
            $action->execute(
                $secondCustomer,
                $departure,
                $this->bookingPayload($secondCustomer, 2),
                'capacity-second-request',
            );
            $this->fail('A request exceeding the remaining capacity succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('travelers', $exception->errors());
        }

        $this->assertDatabaseCount('tour_bookings', 1);
        $this->assertSame(2, (int) $departure->bookings()->holdingCapacity()->sum('traveler_count'));
    }

    public function test_identical_idempotency_replay_returns_the_original_booking_without_duplicate_side_effects(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $departure = $this->bookableDeparture();
        $payload = $this->bookingPayload($customer, 2);
        $action = app(CreateTourBooking::class);

        $first = $action->execute($customer, $departure, $payload, 'idempotent-checkout-001');
        $second = $action->execute($customer, $departure, $payload, 'idempotent-checkout-001');

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('tour_bookings', 1);
        $this->assertDatabaseCount('tour_travelers', 2);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'tour_booking.created')->count());
        Notification::assertSentToTimes($customer, TourBookingReceivedNotification::class, 1);
    }

    public function test_reusing_an_idempotency_key_for_different_input_is_rejected(): void
    {
        $customer = $this->customer();
        $departure = $this->bookableDeparture();
        $payload = $this->bookingPayload($customer);
        $action = app(CreateTourBooking::class);

        $action->execute($customer, $departure, $payload, 'idempotency-mismatch-001');
        $payload['travelers'][0]['full_name'] = 'A different traveler';

        try {
            $action->execute($customer, $departure, $payload, 'idempotency-mismatch-001');
            $this->fail('A mismatched idempotency replay succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
            $this->assertDatabaseCount('tour_bookings', 1);
            $this->assertDatabaseCount('tour_travelers', 1);
        }
    }

    public function test_a_failure_after_record_creation_rolls_back_booking_travelers_and_audit(): void
    {
        Notification::fake();

        $customer = $this->customer();
        $departure = $this->bookableDeparture();
        $auditLogger = $this->mock(AuditLogger::class);
        $auditLogger->shouldReceive('record')->once()->andThrow(new RuntimeException('Synthetic audit failure.'));

        try {
            app(CreateTourBooking::class)->execute(
                $customer,
                $departure,
                $this->bookingPayload($customer, 2),
                'rollback-booking-001',
            );
            $this->fail('The synthetic transaction failure did not escape.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
        }

        $this->assertDatabaseCount('tour_bookings', 0);
        $this->assertDatabaseCount('tour_travelers', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        Notification::assertNothingSent();
    }

    public function test_true_parallel_lock_contention_is_explicitly_not_simulated_by_sqlite(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'SQLite does not provide MySQL-style row-level SELECT ... FOR UPDATE contention. '
                .'The sequential capacity invariant is tested above; parallel contention belongs in the MySQL integration suite.',
            );
        }

        $this->assertNotSame('sqlite', DB::getDriverName());
    }
}
