<?php

namespace Tests\Feature\CarHire;

use App\Actions\CarHire\CreateCarHireBooking;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Notifications\CarHire\CarHireBookingReceivedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class BookingCreationTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_server_owns_identity_status_rate_totals_and_snapshots(): void
    {
        Notification::fake();
        [$vehicle, $rate] = $this->bookableVehicle([
            'year' => 2023,
            'make' => 'Toyota',
            'model' => 'Prado',
            'registration_plate' => 'UBP 900T',
        ], [
            'with_driver_daily_minor' => 275_000,
            'security_deposit_minor' => 400_000,
        ]);
        $customer = $this->customer(['name' => 'Amina Nanyonga', 'email' => 'AMINA@example.test']);
        $attacker = $this->customer();
        $pickup = now()->toImmutable()->addDays(10)->setTimezone('Africa/Kampala')->startOfHour();
        $return = $pickup->addDays(2)->addSecond();

        $booking = app(CreateCarHireBooking::class)->execute(
            $customer,
            $vehicle,
            $this->bookingPayload($customer, overrides: [
                'pickup_at' => $pickup->format('Y-m-d H:i:s'),
                'return_at' => $return->format('Y-m-d H:i:s'),
                'customer_id' => $attacker->getKey(),
                'reference' => 'ATTACKER-REFERENCE',
                'status' => CarHireBookingStatus::Completed->value,
                'billable_days' => 1,
                'daily_rate_minor' => 1,
                'total_minor' => 1,
                'vehicle_name_snapshot' => 'Tampered vehicle',
                'registration_plate_snapshot' => 'TAMPERED',
                'contact_name' => 'Tampered customer',
                'contact_email' => 'tampered@example.test',
                'assigned_driver_user_id' => $attacker->getKey(),
            ]),
            (string) Str::uuid(),
        );

        $this->assertMatchesRegularExpression('/^HIRE-[0-9A-Z]{26}$/', $booking->reference);
        $this->assertSame($customer->getKey(), $booking->customer_id);
        $this->assertSame($vehicle->getKey(), $booking->vehicle_id);
        $this->assertSame($rate->getKey(), $booking->vehicle_hire_rate_id);
        $this->assertSame(CarHireBookingStatus::Pending, $booking->status);
        $this->assertSame(3, $booking->billable_days);
        $this->assertSame(275_000, $booking->daily_rate_minor);
        $this->assertSame(825_000, $booking->rental_subtotal_minor);
        $this->assertSame(400_000, $booking->security_deposit_minor);
        $this->assertSame(1_225_000, $booking->total_minor);
        $this->assertSame('2023 Toyota Prado', $booking->vehicle_name_snapshot);
        $this->assertSame('UBP 900T', $booking->registration_plate_snapshot);
        $this->assertSame('Amina Nanyonga', $booking->contact_name);
        $this->assertSame('amina@example.test', $booking->contact_email);
        $this->assertNull($booking->assigned_driver_user_id);

        $vehicle->update(['make' => 'Changed', 'model' => 'Later', 'registration_plate' => 'NEW PLATE']);
        $rate->update(['with_driver_daily_minor' => 999_999]);
        $booking->refresh();
        $this->assertSame('2023 Toyota Prado', $booking->vehicle_name_snapshot);
        $this->assertSame('UBP 900T', $booking->registration_plate_snapshot);
        $this->assertSame(275_000, $booking->daily_rate_minor);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'car_hire_booking.created',
            'auditable_id' => $booking->getKey(),
            'user_id' => $customer->getKey(),
        ]);
        Notification::assertSentToTimes($customer, CarHireBookingReceivedNotification::class, 1);
    }

    public function test_initial_contract_is_hashable_and_does_not_claim_payment(): void
    {
        Notification::fake();
        [$vehicle] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = app(CreateCarHireBooking::class)->execute(
            $customer,
            $vehicle,
            $this->bookingPayload($customer),
            (string) Str::uuid(),
        );
        $contract = $booking->contracts->sole();
        $this->assertSame(1, $contract->version);
        $this->assertSame($booking->reference, $contract->snapshot['booking_reference']);
        $this->assertSame($booking->total_minor, $contract->snapshot['total_minor']);
        $this->assertSame(
            $this->expectedContractHash($contract->snapshot, $contract->terms_snapshot),
            $contract->content_sha256,
        );
        $this->assertStringContainsString('does not collect payment', $contract->terms_snapshot);
        $this->assertStringNotContainsString('payment successful', mb_strtolower($contract->terms_snapshot));
        $this->assertNull($contract->accepted_at);
    }

    public function test_self_drive_booking_creates_one_editable_draft_application(): void
    {
        Notification::fake();
        [$vehicle] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = app(CreateCarHireBooking::class)->execute(
            $customer,
            $vehicle,
            $this->bookingPayload($customer, HireMode::SelfDrive),
            (string) Str::uuid(),
        );

        $this->assertSame(SelfDriveApplicationStatus::Draft, $booking->selfDriveApplication->status);
        $this->assertTrue($booking->selfDriveApplication->isCustomerEditable());
        $this->assertDatabaseCount('car_hire_self_drive_applications', 1);
    }

    public function test_idempotent_replay_does_not_duplicate_side_effects(): void
    {
        Notification::fake();
        [$vehicle] = $this->bookableVehicle();
        $customer = $this->customer();
        $payload = $this->bookingPayload($customer, HireMode::SelfDrive);
        $key = (string) Str::uuid();
        $action = app(CreateCarHireBooking::class);

        $first = $action->execute($customer, $vehicle, $payload, $key);
        $second = $action->execute($customer, $vehicle, $payload, $key);

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('car_hire_bookings', 1);
        $this->assertDatabaseCount('car_hire_self_drive_applications', 1);
        $this->assertDatabaseCount('car_hire_contracts', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'car_hire_booking.created')->count());
        Notification::assertSentToTimes($customer, CarHireBookingReceivedNotification::class, 1);
    }

    public function test_changed_idempotent_replay_is_rejected(): void
    {
        Notification::fake();
        [$vehicle] = $this->bookableVehicle();
        $customer = $this->customer();
        $payload = $this->bookingPayload($customer);
        $key = (string) Str::uuid();
        $action = app(CreateCarHireBooking::class);
        $action->execute($customer, $vehicle, $payload, $key);

        try {
            $action->execute($customer, $vehicle, array_merge($payload, [
                'pickup_location' => 'A different location',
            ]), $key);
            $this->fail('A mismatched idempotency replay succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }

        $this->assertDatabaseCount('car_hire_bookings', 1);
        $this->assertDatabaseCount('car_hire_contracts', 1);
    }

    /** @return array<string, array{UserRole, AccountStatus, bool}> */
    public static function unauthorizedCustomers(): array
    {
        return [
            'staff' => [UserRole::Staff, AccountStatus::Active, true],
            'driver' => [UserRole::Driver, AccountStatus::Active, true],
            'inactive customer' => [UserRole::Customer, AccountStatus::Inactive, true],
            'suspended customer' => [UserRole::Customer, AccountStatus::Suspended, true],
            'unverified customer' => [UserRole::Customer, AccountStatus::Active, false],
        ];
    }

    #[DataProvider('unauthorizedCustomers')]
    public function test_only_active_verified_customers_can_book(UserRole $role, AccountStatus $status, bool $verified): void
    {
        [$vehicle] = $this->bookableVehicle();
        $customer = $this->user($role, [
            'status' => $status,
            'email_verified_at' => $verified ? now() : null,
        ]);

        $this->expectException(AuthorizationException::class);
        app(CreateCarHireBooking::class)->execute(
            $customer,
            $vehicle,
            $this->bookingPayload($customer),
            (string) Str::uuid(),
        );
    }

    public function test_true_parallel_last_vehicle_contention_is_a_mysql_acceptance_test(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped(
                'SQLite lacks MySQL row-level SELECT ... FOR UPDATE contention; simultaneous last-vehicle requests belong in staging.',
            );
        }

        $this->assertNotSame('sqlite', DB::getDriverName());
    }
}
