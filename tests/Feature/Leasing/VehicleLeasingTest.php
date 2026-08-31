<?php

namespace Tests\Feature\Leasing;

use App\Actions\Leasing\CalculateLeasePayout;
use App\Actions\Leasing\SaveVehicleLease;
use App\Actions\Leasing\SubmitLeaseApplication;
use App\Actions\Leasing\TransitionLeaseApplication;
use App\Actions\Leasing\TransitionLeasePayout;
use App\Actions\Leasing\TransitionVehicleLease;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\LeaseApplicationStatus;
use App\Enums\LeasePayoutStatus;
use App\Enums\LeaseStatus;
use App\Enums\UserRole;
use App\Enums\VehicleCatalogueStatus;
use App\Enums\VehicleOperationalStatus;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\AuditLog;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLease;
use App\Models\VehicleLeaseApplication;
use App\Models\VehicleLeasePayout;
use App\Notifications\Leasing\LeaseApplicationReceivedNotification;
use App\Notifications\Leasing\LeasePayoutReadyNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class VehicleLeasingTest extends TestCase
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

    /** Managers always need two-factor authentication; the column cannot opt out. */
    private function manager(): User
    {
        return $this->user(UserRole::Manager, [
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function verifiedSession(User $manager): array
    {
        return [
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->timestamp,
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $manager->id,
        ];
    }

    private function owner(): User
    {
        return $this->user(UserRole::Customer);
    }

    /** @return array<string, mixed> */
    private function applicationPayload(array $overrides = []): array
    {
        return array_merge([
            'contact_name' => 'Joseph Okello',
            'contact_email' => 'joseph@example.com',
            'contact_phone' => '+256700111222',
            'make' => 'Toyota',
            'model' => 'Hiace',
            'year' => 2018,
            'registration_plate' => 'UAX 123K',
            'colour' => 'White',
            'transmission' => 'Manual',
            'fuel_type' => 'Diesel',
            'seating_capacity' => 14,
            'mileage_km' => 120000,
            'condition' => 'Good',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function leasePayload(array $overrides = []): array
    {
        return array_merge([
            'payout_model' => 'revenue_share',
            'revenue_share_bps' => 2500,
            'currency' => 'UGX',
            'starts_on' => '2026-09-01',
            'notice_period_days' => 30,
        ], $overrides);
    }

    /** A completed hire that earned money on the given vehicle. */
    private function completedHire(Vehicle $vehicle, string $returnedOn, int $subtotalMinor, string $currency = 'UGX'): CarHireBooking
    {
        return CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Completed,
            'return_at' => $returnedOn,
            'rental_subtotal_minor' => $subtotalMinor,
            'security_deposit_minor' => 500_000,
            'total_minor' => $subtotalMinor + 500_000,
            'currency' => $currency,
        ]);
    }

    // ---- The offer ---------------------------------------------------------

    public function test_a_guest_can_offer_a_vehicle(): void
    {
        $application = app(SubmitLeaseApplication::class)
            ->execute(null, $this->applicationPayload(), (string) Str::uuid());

        $this->assertNull($application->owner_id);
        $this->assertTrue($application->isGuest());
        $this->assertSame(LeaseApplicationStatus::Submitted, $application->status);
        $this->assertSame('UAX 123K', $application->registration_plate);
        $this->assertDatabaseHas('audit_logs', ['event' => 'lease_application.created']);
    }

    public function test_the_registration_plate_is_never_written_to_the_audit_trail(): void
    {
        app(SubmitLeaseApplication::class)
            ->execute(null, $this->applicationPayload(), (string) Str::uuid());

        $entry = AuditLog::query()->where('event', 'lease_application.created')->firstOrFail();

        $this->assertStringNotContainsString('UAX 123K', (string) json_encode($entry->new_values));
    }

    public function test_a_signed_in_owner_cannot_spoof_their_identity(): void
    {
        $owner = $this->owner();

        $application = app(SubmitLeaseApplication::class)->execute(
            $owner,
            $this->applicationPayload(['contact_email' => 'someone.else@example.com']),
            (string) Str::uuid(),
        );

        $this->assertSame($owner->email, $application->contact_email);
        $this->assertSame($owner->getKey(), $application->owner_id);
    }

    public function test_replaying_the_same_key_returns_the_same_offer(): void
    {
        $key = (string) Str::uuid();
        $action = app(SubmitLeaseApplication::class);

        $first = $action->execute(null, $this->applicationPayload(), $key);
        $second = $action->execute(null, $this->applicationPayload(), $key);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, VehicleLeaseApplication::query()->count());
    }

    public function test_one_owner_offering_two_cars_files_two_offers(): void
    {
        $key = (string) Str::uuid();
        $action = app(SubmitLeaseApplication::class);

        $action->execute(null, $this->applicationPayload(), $key);
        $action->execute(null, $this->applicationPayload(['registration_plate' => 'UAX 999Z']), $key);

        $this->assertSame(2, VehicleLeaseApplication::query()->count());
    }

    public function test_the_database_refuses_a_duplicate_owner_and_key_pair(): void
    {
        $existing = VehicleLeaseApplication::factory()->create();

        $this->expectException(QueryException::class);

        VehicleLeaseApplication::factory()->create([
            'idempotency_owner_hash' => $existing->idempotency_owner_hash,
            'idempotency_key' => $existing->idempotency_key,
        ]);
    }

    public function test_an_offer_notifies_the_owner(): void
    {
        app(SubmitLeaseApplication::class)
            ->execute(null, $this->applicationPayload(), (string) Str::uuid());

        Notification::assertSentOnDemand(LeaseApplicationReceivedNotification::class);
    }

    public function test_an_offer_cannot_be_approved_before_the_vehicle_is_inspected(): void
    {
        $manager = $this->manager();
        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::UnderReview)->create();

        $this->expectException(ValidationException::class);

        app(TransitionLeaseApplication::class)->approve($manager, $application);
    }

    public function test_every_application_status_is_reachable(): void
    {
        $manager = $this->manager();
        $action = app(TransitionLeaseApplication::class);

        $application = VehicleLeaseApplication::factory()->create();
        $this->assertSame(LeaseApplicationStatus::Submitted, $application->status);

        $reviewing = $action->review($manager, $application);
        $this->assertSame(LeaseApplicationStatus::UnderReview, $reviewing->status);

        $arranged = $action->arrangeInspection($manager, $reviewing, '2026-08-25 10:00:00', 'Kampala yard');
        $this->assertSame(LeaseApplicationStatus::InspectionArranged, $arranged->status);

        $inspected = $action->recordInspection($manager, $arranged, 'Bodywork sound, tyres need replacing.');
        $this->assertSame(LeaseApplicationStatus::Inspected, $inspected->status);

        $approved = $action->approve($manager, $inspected);
        $this->assertSame(LeaseApplicationStatus::Approved, $approved->status);

        $declined = $action->decline(
            $manager,
            VehicleLeaseApplication::factory()->create(),
            'The vehicle is older than we take on.',
        );
        $this->assertSame(LeaseApplicationStatus::Declined, $declined->status);

        $withdrawn = $action->withdraw(
            $manager,
            VehicleLeaseApplication::factory()->create(),
            'The owner decided to sell it instead.',
        );
        $this->assertSame(LeaseApplicationStatus::Withdrawn, $withdrawn->status);
    }

    public function test_an_inspection_cannot_be_arranged_in_the_past(): void
    {
        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::UnderReview)->create();

        $this->expectException(ValidationException::class);

        app(TransitionLeaseApplication::class)
            ->arrangeInspection($this->staff(), $application, '2026-08-01 10:00:00', 'Kampala yard');
    }

    public function test_staff_cannot_approve_an_offer(): void
    {
        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::Inspected)->create();

        $this->expectException(AuthorizationException::class);

        app(TransitionLeaseApplication::class)->approve($this->staff(), $application);
    }

    // ---- The agreement -------------------------------------------------------

    public function test_a_lease_can_only_be_drawn_up_from_an_approved_offer(): void
    {
        $manager = $this->manager();
        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::Inspected)->create();

        $this->expectException(ValidationException::class);

        app(SaveVehicleLease::class)->create($manager, $this->owner(), $this->leasePayload(), $application);
    }

    public function test_one_offer_backs_only_one_lease(): void
    {
        $manager = $this->manager();
        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::Approved)->create();
        $action = app(SaveVehicleLease::class);

        $action->create($manager, $this->owner(), $this->leasePayload(), $application);

        $this->expectException(ValidationException::class);

        $action->create($manager, $this->owner(), $this->leasePayload(), $application);
    }

    public function test_a_revenue_share_lease_needs_a_percentage(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveVehicleLease::class)->create(
            $this->manager(),
            $this->owner(),
            $this->leasePayload(['revenue_share_bps' => null]),
        );
    }

    public function test_a_retainer_lease_needs_an_amount(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveVehicleLease::class)->create(
            $this->manager(),
            $this->owner(),
            $this->leasePayload(['payout_model' => 'fixed_monthly', 'revenue_share_bps' => null]),
        );
    }

    public function test_a_share_above_one_hundred_percent_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveVehicleLease::class)->create(
            $this->manager(),
            $this->owner(),
            $this->leasePayload(['revenue_share_bps' => 10001]),
        );
    }

    public function test_staff_cannot_draw_up_terms(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SaveVehicleLease::class)->create($this->staff(), $this->owner(), $this->leasePayload());
    }

    public function test_terms_are_frozen_once_the_lease_is_active(): void
    {
        $manager = $this->manager();
        $lease = VehicleLease::factory()->active()->create();

        $this->expectException(ValidationException::class);

        app(SaveVehicleLease::class)->update($manager, $lease, $this->leasePayload(['revenue_share_bps' => 9000]));
    }

    // ---- The fleet interlock ---------------------------------------------------

    public function test_activating_a_lease_puts_the_vehicle_in_the_fleet(): void
    {
        $manager = $this->manager();
        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::Approved)
            ->plated('UAX 555L')
            ->create();
        $lease = VehicleLease::factory()->fromApplication($application)->create();

        $activated = app(TransitionVehicleLease::class)->activate($manager, $lease);

        $this->assertSame(LeaseStatus::Active, $activated->status);
        $this->assertNotNull($activated->vehicle_id);

        $vehicle = $activated->vehicle;
        $this->assertSame('UAX 555L', $vehicle->registration_plate);
        // Operationally available so the desk can use it, but not published:
        // it needs photographs and a rate before the public sees it.
        $this->assertSame(VehicleOperationalStatus::Available, $vehicle->operational_status);
        $this->assertSame(VehicleCatalogueStatus::Draft, $vehicle->catalogue_status);
    }

    public function test_a_plate_already_in_the_fleet_is_refused_rather_than_duplicated(): void
    {
        $manager = $this->manager();
        Vehicle::factory()->create(['registration_plate' => 'UAX 777M']);

        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::Approved)
            ->plated('UAX 777M')
            ->create();
        $lease = VehicleLease::factory()->fromApplication($application)->create();

        $this->expectException(ValidationException::class);

        app(TransitionVehicleLease::class)->activate($manager, $lease);
    }

    public function test_suspending_takes_the_vehicle_off_hire_without_ending_the_agreement(): void
    {
        $vehicle = Vehicle::factory()->create([
            'operational_status' => VehicleOperationalStatus::Available,
        ]);
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->create();

        $suspended = app(TransitionVehicleLease::class)
            ->suspend($this->staff(), $lease, 'Insurance certificate has expired.');

        $this->assertSame(LeaseStatus::Suspended, $suspended->status);
        $this->assertSame(VehicleOperationalStatus::Unavailable, $vehicle->fresh()->operational_status);
        // The terms survive.
        $this->assertSame(2500, $suspended->revenue_share_bps);
    }

    public function test_resuming_puts_the_same_vehicle_back_on_hire(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create([
            'operational_status' => VehicleOperationalStatus::Unavailable,
        ]);
        $lease = VehicleLease::factory()->forVehicle($vehicle)->suspended()->create();

        $resumed = app(TransitionVehicleLease::class)->activate($manager, $lease);

        $this->assertSame(LeaseStatus::Active, $resumed->status);
        $this->assertSame($vehicle->getKey(), $resumed->vehicle_id);
        $this->assertSame(VehicleOperationalStatus::Available, $vehicle->fresh()->operational_status);
    }

    public function test_ending_a_lease_retires_the_vehicle_from_the_fleet(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create([
            'catalogue_status' => VehicleCatalogueStatus::Published,
            'operational_status' => VehicleOperationalStatus::Available,
        ]);
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->create();

        app(TransitionVehicleLease::class)->end($manager, $lease, 'The owner has sold the vehicle.');

        $vehicle->refresh();

        $this->assertSame(VehicleOperationalStatus::Retired, $vehicle->operational_status);
        $this->assertSame(VehicleCatalogueStatus::Archived, $vehicle->catalogue_status);
    }

    public function test_a_lease_with_unfinished_hires_cannot_be_ended(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->create();

        CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Confirmed,
            'return_at' => now()->addDays(5),
        ]);

        $this->expectException(ValidationException::class);

        app(TransitionVehicleLease::class)->end($manager, $lease, 'The owner wants it back.');
    }

    public function test_an_ended_lease_is_final(): void
    {
        $manager = $this->manager();
        $lease = VehicleLease::factory()->ended()->create();

        $this->assertSame([], $lease->status->allowedTransitions());

        $this->expectException(ValidationException::class);

        app(TransitionVehicleLease::class)->activate($manager, $lease);
    }

    // ---- The money --------------------------------------------------------------

    public function test_a_revenue_share_is_taken_from_the_rental_not_the_deposit(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->sharing(2500)->create();

        // 2,000,000 rental plus a 500,000 refundable deposit.
        $this->completedHire($vehicle, '2026-07-15 10:00:00', 2_000_000);

        $payout = app(CalculateLeasePayout::class)->forMonth($manager, $lease, '2026-07-10');

        $this->assertSame(2_000_000, $payout->gross_revenue_minor);
        // 25% of the rental only. Sharing the deposit would pay the owner out
        // of money PISFA is holding for the customer.
        $this->assertSame(500_000, $payout->earned_minor);
    }

    public function test_hires_in_another_currency_are_excluded_not_converted(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->sharing(2500, 'UGX')->create();

        $this->completedHire($vehicle, '2026-07-15 10:00:00', 2_000_000, 'UGX');
        $this->completedHire($vehicle, '2026-07-20 10:00:00', 40_000, 'USD');

        $payout = app(CalculateLeasePayout::class)->forMonth($manager, $lease, '2026-07-10');

        $this->assertSame(2_000_000, $payout->gross_revenue_minor);
        $this->assertSame(1, $payout->hire_count);
        $this->assertSame(1, $payout->excluded_hire_count);
    }

    public function test_only_completed_hires_count(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->sharing(5000)->create();

        $this->completedHire($vehicle, '2026-07-15 10:00:00', 1_000_000);

        CarHireBooking::factory()->create([
            'vehicle_id' => $vehicle->getKey(),
            'status' => CarHireBookingStatus::Cancelled,
            'return_at' => '2026-07-18 10:00:00',
            'rental_subtotal_minor' => 900_000,
            'total_minor' => 900_000,
            'currency' => 'UGX',
        ]);

        $payout = app(CalculateLeasePayout::class)->forMonth($manager, $lease, '2026-07-10');

        $this->assertSame(1_000_000, $payout->gross_revenue_minor);
        $this->assertSame(500_000, $payout->earned_minor);
    }

    public function test_a_share_rounds_half_up_rather_than_truncating(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        // 33.33% of 1,000,001 is 333,300.3 — truncation would shave it down.
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->sharing(3333)->create();

        $this->completedHire($vehicle, '2026-07-15 10:00:00', 1_000_005);

        $payout = app(CalculateLeasePayout::class)->forMonth($manager, $lease, '2026-07-10');

        // (1,000,005 * 3333 + 5000) / 10000, integer division.
        $this->assertSame(intdiv(1_000_005 * 3333 + 5000, 10000), $payout->earned_minor);
    }

    public function test_a_retainer_lease_pays_the_same_whether_it_earned_or_not(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->retainer(800_000)->create();

        $payout = app(CalculateLeasePayout::class)->forMonth($manager, $lease, '2026-07-10');

        $this->assertSame(0, $payout->gross_revenue_minor);
        $this->assertSame(800_000, $payout->earned_minor);
        $this->assertSame(800_000, $payout->net_payable_minor);
    }

    public function test_a_hire_lands_in_exactly_one_month(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->sharing(10000)->create();
        $action = app(CalculateLeasePayout::class);

        // Picked up in June, returned in July: attributed to July.
        $this->completedHire($vehicle, '2026-07-02 10:00:00', 600_000);

        $june = $action->forMonth($manager, $lease, '2026-06-10');
        $july = $action->forMonth($manager, $lease, '2026-07-10');

        $this->assertSame(0, $june->gross_revenue_minor);
        $this->assertSame(600_000, $july->gross_revenue_minor);
    }

    public function test_a_second_run_for_the_same_month_does_not_pay_twice(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->sharing(2500)->create();
        $action = app(CalculateLeasePayout::class);

        $this->completedHire($vehicle, '2026-07-15 10:00:00', 2_000_000);

        $first = $action->forMonth($manager, $lease, '2026-07-10');
        $second = $action->forMonth($manager, $lease, '2026-07-31');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, VehicleLeasePayout::query()->count());
    }

    public function test_the_database_refuses_two_payouts_for_one_period(): void
    {
        $lease = VehicleLease::factory()->active()->create();

        VehicleLeasePayout::factory()->forLease($lease)->month('2026-07-01')->create();

        $this->expectException(QueryException::class);

        VehicleLeasePayout::factory()->forLease($lease)->month('2026-07-15')->create();
    }

    public function test_an_approved_payout_is_not_recalculated(): void
    {
        $manager = $this->manager();
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->sharing(2500)->create();

        $payout = VehicleLeasePayout::factory()->forLease($lease)->month('2026-07-01')
            ->status(LeasePayoutStatus::Approved)
            ->create(['earned_minor' => 111_111, 'net_payable_minor' => 111_111]);

        $this->completedHire($vehicle, '2026-07-15 10:00:00', 8_000_000);

        $again = app(CalculateLeasePayout::class)->forMonth($manager, $lease, '2026-07-10');

        $this->assertTrue($payout->is($again));
        $this->assertSame(111_111, $again->earned_minor);
    }

    public function test_a_deduction_larger_than_the_earnings_is_refused(): void
    {
        $payout = VehicleLeasePayout::factory()->create(['earned_minor' => 500_000]);

        $this->expectException(ValidationException::class);

        app(CalculateLeasePayout::class)
            ->applyDeductions($this->staff(), $payout, '600000', 'Repairs after an accident.');
    }

    public function test_a_deduction_needs_a_reason_the_owner_can_read(): void
    {
        $payout = VehicleLeasePayout::factory()->create(['earned_minor' => 500_000]);

        $this->expectException(ValidationException::class);

        app(CalculateLeasePayout::class)->applyDeductions($this->staff(), $payout, '100000', null);
    }

    public function test_a_deduction_reduces_the_net_payable(): void
    {
        $payout = VehicleLeasePayout::factory()->create([
            'earned_minor' => 500_000,
            'net_payable_minor' => 500_000,
        ]);

        $updated = app(CalculateLeasePayout::class)
            ->applyDeductions($this->staff(), $payout, '120000', 'New tyres fitted in July.');

        $this->assertSame(120_000, $updated->deductions_minor);
        $this->assertSame(380_000, $updated->net_payable_minor);
    }

    public function test_every_payout_status_is_reachable(): void
    {
        $manager = $this->manager();
        $action = app(TransitionLeasePayout::class);

        $payout = VehicleLeasePayout::factory()->create();
        $this->assertSame(LeasePayoutStatus::Draft, $payout->status);

        $approved = $action->approve($manager, $payout);
        $this->assertSame(LeasePayoutStatus::Approved, $approved->status);

        $reopened = $action->reopen($manager, $approved, 'A deduction was missed.');
        $this->assertSame(LeasePayoutStatus::Draft, $reopened->status);

        $approvedAgain = $action->approve($manager, $reopened);
        $paid = $action->markPaid($manager, $approvedAgain, 'MM-99887766');
        $this->assertSame(LeasePayoutStatus::Paid, $paid->status);
        $this->assertSame('MM-99887766', $paid->payment_reference);

        $cancelled = $action->cancel($manager, VehicleLeasePayout::factory()->create(), 'Raised in error.');
        $this->assertSame(LeasePayoutStatus::Cancelled, $cancelled->status);
    }

    public function test_a_paid_payout_is_final(): void
    {
        $manager = $this->manager();
        $payout = VehicleLeasePayout::factory()->status(LeasePayoutStatus::Paid)->create();

        $this->assertSame([], $payout->status->allowedTransitions());

        $this->expectException(ValidationException::class);

        app(TransitionLeasePayout::class)->reopen($manager, $payout, 'We got it wrong.');
    }

    public function test_marking_paid_requires_a_transfer_reference(): void
    {
        $manager = $this->manager();
        $payout = VehicleLeasePayout::factory()->status(LeasePayoutStatus::Approved)->create();

        $this->expectException(ValidationException::class);

        app(TransitionLeasePayout::class)->markPaid($manager, $payout, '  ');
    }

    public function test_staff_cannot_approve_money(): void
    {
        $payout = VehicleLeasePayout::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(TransitionLeasePayout::class)->approve($this->staff(), $payout);
    }

    public function test_approving_a_payout_notifies_the_owner(): void
    {
        $manager = $this->manager();
        $owner = $this->owner();
        $lease = VehicleLease::factory()->ownedBy($owner)->active()->create();
        $payout = VehicleLeasePayout::factory()->forLease($lease)->create();

        app(TransitionLeasePayout::class)->approve($manager, $payout);

        Notification::assertSentTo($owner, LeasePayoutReadyNotification::class);
    }

    // ---- Surfaces -----------------------------------------------------------------

    public function test_the_public_form_is_open_to_guests(): void
    {
        $this->get(route('leasing.create'))->assertOk()->assertSee('lease');
    }

    public function test_a_guest_can_submit_the_form(): void
    {
        $this->post(route('leasing.store'), $this->applicationPayload([
            'acknowledge_request' => '1',
            'idempotency_key' => (string) Str::uuid(),
        ]))->assertRedirect()->assertSessionHas('success');

        $this->assertSame(1, VehicleLeaseApplication::query()->count());
    }

    public function test_a_guest_offer_is_readable_by_its_reference(): void
    {
        $application = VehicleLeaseApplication::factory()->create();

        $this->get(route('leasing.show', $application->reference))->assertOk();
    }

    public function test_an_owners_offer_is_not_readable_by_reference_alone(): void
    {
        $application = VehicleLeaseApplication::factory()->fromOwner($this->owner())->create();

        $this->get(route('leasing.show', $application->reference))->assertNotFound();
    }

    public function test_an_owner_sees_their_own_lease_and_not_another(): void
    {
        $owner = $this->owner();
        $mine = VehicleLease::factory()->ownedBy($owner)->active()->create();
        $theirs = VehicleLease::factory()->ownedBy($this->owner())->active()->create();

        $this->actingAs($owner)
            ->get(route('portal.leases.show', ['ownerLease' => $mine->reference]))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('portal.leases.show', ['ownerLease' => $theirs->reference]))
            ->assertNotFound();
    }

    public function test_an_owner_never_sees_a_draft_statement(): void
    {
        $owner = $this->owner();
        $lease = VehicleLease::factory()->ownedBy($owner)->active()->create();

        $draft = VehicleLeasePayout::factory()->forLease($lease)->month('2026-07-01')->create();
        $approved = VehicleLeasePayout::factory()->forLease($lease)->month('2026-06-01')
            ->status(LeasePayoutStatus::Approved)->create();

        $this->actingAs($owner)
            ->get(route('portal.leases.show', ['ownerLease' => $lease->reference]))
            ->assertOk()
            ->assertSee($approved->reference)
            ->assertDontSee($draft->reference);
    }

    public function test_the_console_is_closed_to_customers(): void
    {
        $this->actingAs($this->owner())
            ->get(route('admin.leasing.leases.index'))
            ->assertForbidden();
    }

    public function test_staff_can_work_the_console(): void
    {
        $staff = $this->staff();
        VehicleLeaseApplication::factory()->create();
        $lease = VehicleLease::factory()->active()->create();

        $this->actingAs($staff)->get(route('admin.leasing.applications.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.leasing.leases.index'))->assertOk();
        $this->actingAs($staff)->get(route('admin.leasing.leases.show', $lease))->assertOk();
    }

    public function test_a_manager_activates_a_lease_through_the_console(): void
    {
        $manager = $this->manager();
        $application = VehicleLeaseApplication::factory()
            ->status(LeaseApplicationStatus::Approved)->plated('UAX 321Q')->create();
        $lease = VehicleLease::factory()->fromApplication($application)->create();

        $this->actingAs($manager)
            ->withSession($this->verifiedSession($manager))
            ->post(route('admin.leasing.leases.activate', $lease))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(LeaseStatus::Active, $lease->fresh()->status);
    }

    public function test_internal_notes_and_plates_are_never_serialised(): void
    {
        $application = VehicleLeaseApplication::factory()->create(['internal_notes' => 'Owner is pushy']);
        $lease = VehicleLease::factory()->create(['internal_notes' => 'Watch the mileage']);

        $this->assertArrayNotHasKey('internal_notes', $application->toArray());
        $this->assertArrayNotHasKey('registration_plate', $application->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $application->toArray());
        $this->assertArrayNotHasKey('internal_notes', $lease->toArray());
    }
}
