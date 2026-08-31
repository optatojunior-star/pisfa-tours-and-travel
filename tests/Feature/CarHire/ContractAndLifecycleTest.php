<?php

namespace Tests\Feature\CarHire;

use App\Actions\CarHire\AcceptCarHireContract;
use App\Actions\CarHire\CancelCarHireBooking;
use App\Actions\CarHire\TransitionCarHireBooking;
use App\Enums\CarHireBookingEventType;
use App\Enums\CarHireBookingStatus;
use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Models\CarHireDriverAssignment;
use App\Notifications\CarHire\CarHireBookingCancelledNotification;
use App\Notifications\CarHire\CarHireBookingConfirmedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class ContractAndLifecycleTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    public function test_owner_accepts_current_integrity_checked_contract_idempotently(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate);
        $contract = $this->contractFor($booking);
        $reorderedSnapshot = array_reverse($contract->snapshot, true);
        $reorderedSnapshot['customer'] = array_reverse($reorderedSnapshot['customer'], true);
        $contract->update([
            'snapshot' => $reorderedSnapshot,
            'content_sha256' => $this->expectedContractHash($reorderedSnapshot, $contract->terms_snapshot),
        ]);
        $action = app(AcceptCarHireContract::class);

        $accepted = $action->execute(
            $customer,
            $booking,
            $contract,
            '127.0.0.1',
            "PISFA\x00 Test\nAgent",
        );
        $replay = $action->execute($customer, $booking, $contract, '127.0.0.1', 'Different later agent');

        $this->assertTrue($accepted->is($replay));
        $this->assertSame($customer->getKey(), $accepted->accepted_by_user_id);
        $this->assertSame('PISFA Test Agent', $accepted->acceptance_user_agent);
        $this->assertNotNull($accepted->accepted_at);
        $this->assertDatabaseCount('car_hire_contracts', 1);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'car_hire_contract.accepted',
            'auditable_id' => $contract->getKey(),
            'user_id' => $customer->getKey(),
        ]);
    }

    public function test_foreign_stale_voided_or_tampered_contract_cannot_be_accepted(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate);
        $contract = $this->contractFor($booking);

        try {
            app(AcceptCarHireContract::class)->execute($this->customer(), $booking, $contract);
            $this->fail('A foreign customer accepted the contract.');
        } catch (AuthorizationException) {
            $this->assertNull($contract->fresh()->accepted_at);
        }

        $contract->update(['content_sha256' => str_repeat('0', 64)]);
        try {
            app(AcceptCarHireContract::class)->execute($customer, $booking, $contract->fresh());
            $this->fail('A tampered contract was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contract', $exception->errors());
        }

        $this->assertNull($contract->fresh()->accepted_at);
    }

    public function test_confirmation_requires_accepted_contract_and_approved_self_drive_application(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking(
            $customer,
            $vehicle,
            $rate,
            mode: HireMode::SelfDrive,
        );
        $contract = $this->contractFor($booking);
        $actor = $this->operationsUser();

        try {
            app(TransitionCarHireBooking::class)->execute(
                $actor,
                $booking,
                CarHireBookingStatus::Confirmed,
            );
            $this->fail('A booking without accepted contract was confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('contract', $exception->errors());
        }

        app(AcceptCarHireContract::class)->execute($customer, $booking, $contract);
        try {
            app(TransitionCarHireBooking::class)->execute(
                $actor,
                $booking,
                CarHireBookingStatus::Confirmed,
            );
            $this->fail('An unapproved self-drive application was confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('self_drive_application', $exception->errors());
        }

        $booking->selfDriveApplication->update([
            'status' => SelfDriveApplicationStatus::Approved,
            'driving_permit_expires_on' => $booking->return_at->addDay()->toDateString(),
        ]);
        $confirmed = app(TransitionCarHireBooking::class)->execute(
            $actor,
            $booking,
            CarHireBookingStatus::Confirmed,
        );

        $this->assertSame(CarHireBookingStatus::Confirmed, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
        Notification::assertSentToTimes($customer, CarHireBookingConfirmedNotification::class, 1);
    }

    public function test_confirmation_rechecks_the_locked_rate_against_the_booking_snapshot(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate);
        $this->contractFor($booking, accepted: true);
        $actor = $this->operationsUser();

        $rate->forceFill(['is_active' => false])->save();

        try {
            app(TransitionCarHireBooking::class)->execute(
                $actor,
                $booking,
                CarHireBookingStatus::Confirmed,
            );
            $this->fail('A booking with an inactive selected rate was confirmed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate', $exception->errors());
        }

        $this->assertSame(CarHireBookingStatus::Pending, $booking->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_customer_cancellation_requires_reason_and_cutoff_but_operations_can_assist_after_cutoff(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, CarHireBookingStatus::Confirmed);

        try {
            app(CancelCarHireBooking::class)->execute($customer, $booking, null);
            $this->fail('A cancellation without a reason succeeded.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('reason', $exception->errors());
        }

        $booking->update(['cancellation_cutoff_at' => now()->subMinute()]);
        try {
            app(CancelCarHireBooking::class)->execute($customer, $booking->fresh(), 'Plans changed.');
            $this->fail('A customer cancelled after the cutoff.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('booking', $exception->errors());
        }

        $cancelled = app(CancelCarHireBooking::class)->execute(
            $this->operationsUser(),
            $booking->fresh(),
            'Customer contacted operations after the cutoff.',
        );
        $this->assertSame(CarHireBookingStatus::Cancelled, $cancelled->status);
        Notification::assertSentToTimes($customer, CarHireBookingCancelledNotification::class, 1);
    }

    public function test_cancellation_voids_contract_releases_driver_and_is_idempotent(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, CarHireBookingStatus::Confirmed);
        $contract = $this->contractFor($booking, accepted: true);
        $assignment = CarHireDriverAssignment::factory()->for($booking, 'booking')->create();
        $booking->refresh();
        $actor = $this->operationsUser();
        $action = app(CancelCarHireBooking::class);

        $cancelled = $action->execute($actor, $booking, 'Vehicle unavailable after inspection.');
        $replay = $action->execute($actor, $cancelled, 'A later duplicate reason.');

        $this->assertTrue($cancelled->is($replay));
        $this->assertSame(CarHireBookingStatus::Cancelled, $cancelled->status);
        $this->assertNull($cancelled->assigned_driver_user_id);
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertNotNull($contract->fresh()->voided_at);
        $this->assertSame(1, $booking->driverAssignments()->count());
        $this->assertSame(1, $booking->contracts()->count());
    }

    public function test_decline_requires_reason_and_expiry_requires_elapsed_hold(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $actor = $this->operationsUser();
        $booking = $this->persistedBooking($this->customer(), $vehicle, $rate);

        foreach ([CarHireBookingStatus::Declined, CarHireBookingStatus::Expired] as $next) {
            try {
                app(TransitionCarHireBooking::class)->execute($actor, $booking, $next);
                $this->fail("A premature or reasonless {$next->value} transition succeeded.");
            } catch (ValidationException $exception) {
                $this->assertNotEmpty(array_intersect(['reason', 'status'], array_keys($exception->errors())));
            }
        }

        $declined = app(TransitionCarHireBooking::class)->execute(
            $actor,
            $booking,
            CarHireBookingStatus::Declined,
            'The requested vehicle cannot be supplied.',
        );
        $this->assertSame(CarHireBookingStatus::Declined, $declined->status);
    }

    public function test_completion_waits_for_return_releases_assignment_and_records_loyalty_once(): void
    {
        [$vehicle, $rate] = $this->bookableVehicle();
        $customer = $this->customer();
        $pickup = now()->toImmutable()->subHour();
        $return = now()->toImmutable()->addHour();
        $booking = $this->persistedBooking(
            $customer,
            $vehicle,
            $rate,
            CarHireBookingStatus::Confirmed,
            attributes: ['pickup_at' => $pickup, 'return_at' => $return],
        );
        $this->contractFor($booking, accepted: true);
        $assignment = CarHireDriverAssignment::factory()->for($booking, 'booking')->create();
        $booking->refresh();
        $actor = $this->operationsUser();
        $inProgress = app(TransitionCarHireBooking::class)->execute(
            $actor,
            $booking,
            CarHireBookingStatus::InProgress,
        );

        try {
            app(TransitionCarHireBooking::class)->execute(
                $actor,
                $inProgress,
                CarHireBookingStatus::Completed,
            );
            $this->fail('A hire was completed before return.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->travelTo($return->addMinute());
        $completed = app(TransitionCarHireBooking::class)->execute(
            $actor,
            $inProgress->fresh(),
            CarHireBookingStatus::Completed,
        );
        $replay = app(TransitionCarHireBooking::class)->execute(
            $actor,
            $completed,
            CarHireBookingStatus::Completed,
        );

        $this->assertTrue($completed->is($replay));
        $this->assertSame(CarHireBookingStatus::Completed, $completed->status);
        $this->assertNull($completed->assigned_driver_user_id);
        $this->assertNotNull($assignment->fresh()->unassigned_at);
        $this->assertSame(1, $completed->events()
            ->where('event_type', CarHireBookingEventType::LoyaltyEligible->value)->count());
    }
}
