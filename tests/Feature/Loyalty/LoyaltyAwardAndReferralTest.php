<?php

namespace Tests\Feature\Loyalty;

use App\Actions\Loyalty\RegisterReferral;
use App\Actions\Payments\SettlePayment;
use App\Enums\LoyaltyTier;
use App\Enums\LoyaltyTransactionType;
use App\Enums\PaymentProvider;
use App\Enums\PaymentStatus;
use App\Enums\ReferralStatus;
use App\Enums\TourBookingEventType;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyTransaction;
use App\Models\Payment;
use App\Models\Referral;
use App\Models\TourBooking;
use App\Models\User;
use App\Notifications\Loyalty\LoyaltyPointsExpiredNotification;
use App\Notifications\Loyalty\LoyaltyPointsExpiringNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class LoyaltyAwardAndReferralTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
        config(['loyalty.minor_units_per_point' => 10_000]);
    }

    /** Settle a tour booking in full, which writes the eligibility marker. */
    private function settleBooking(User $customer): TourBooking
    {
        $booking = $this->persistedBooking($customer, $this->bookableDeparture());

        $payment = Payment::factory()->create([
            'payable_type' => $booking->getMorphClass(),
            'payable_id' => $booking->getKey(),
            'customer_id' => $customer->getKey(),
            'provider' => PaymentProvider::BankTransfer,
            'status' => PaymentStatus::Pending,
            'amount_minor' => $booking->total_minor,
            'base_amount_minor' => $booking->total_minor,
            'currency' => $booking->currency,
            'base_currency' => $booking->currency,
        ]);

        app(SettlePayment::class)->execute(
            payment: $payment,
            actor: $this->operationsUser(),
            source: 'manual',
        );

        return $booking->fresh();
    }

    public function test_a_settled_booking_writes_an_unprocessed_eligibility_marker(): void
    {
        $customer = $this->customer();
        $booking = $this->settleBooking($customer);

        $this->assertDatabaseHas('tour_booking_events', [
            'tour_booking_id' => $booking->getKey(),
            'event_type' => TourBookingEventType::LoyaltyEligible->value,
            'processed_at' => null,
        ]);

        // Nothing is awarded until the command runs.
        $this->assertDatabaseCount('loyalty_transactions', 0);
    }

    public function test_the_command_awards_points_from_the_base_currency_amount(): void
    {
        $customer = $this->customer();
        $booking = $this->settleBooking($customer);

        $this->artisan('loyalty:process-awards')->assertSuccessful();

        $account = LoyaltyAccount::query()->where('user_id', $customer->getKey())->sole();
        $expected = intdiv((int) $booking->total_minor, 10_000);

        $this->assertSame($expected, $account->points_balance);
        $this->assertSame($expected, $account->lifetime_points);

        // The marker is consumed, so it will not be picked up again.
        $this->assertSame(
            0,
            $booking->events()
                ->where('event_type', TourBookingEventType::LoyaltyEligible->value)
                ->whereNull('processed_at')
                ->count(),
        );
    }

    public function test_running_the_command_twice_never_double_awards(): void
    {
        $customer = $this->customer();
        $this->settleBooking($customer);

        $this->artisan('loyalty:process-awards')->assertSuccessful();
        $balanceAfterFirst = LoyaltyAccount::query()->where('user_id', $customer->getKey())->sole()->points_balance;

        $this->artisan('loyalty:process-awards')->assertSuccessful();
        $this->artisan('loyalty:process-awards')->assertSuccessful();

        $this->assertSame(
            $balanceAfterFirst,
            LoyaltyAccount::query()->where('user_id', $customer->getKey())->sole()->points_balance,
        );
        $this->assertSame(1, LoyaltyTransaction::query()
            ->where('type', LoyaltyTransactionType::Earned->value)
            ->count());
    }

    public function test_a_guest_booking_earns_nothing_but_still_consumes_the_marker(): void
    {
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        // A marker with no customer, as a guest booking would produce.
        $booking->events()->create([
            'event_type' => TourBookingEventType::LoyaltyEligible->value,
            'payload' => ['customer_id' => null, 'total_minor' => 500_000, 'currency' => 'UGX'],
            'processed_at' => null,
        ]);

        $this->artisan('loyalty:process-awards')->assertSuccessful();

        $this->assertDatabaseCount('loyalty_transactions', 0);
        // Consumed, so it does not requeue forever.
        $this->assertSame(0, $booking->events()->whereNull('processed_at')->count());
    }

    // ---- Referrals -------------------------------------------------------

    public function test_a_referred_customer_receives_the_welcome_bonus_immediately(): void
    {
        $referrer = $this->customer();
        $referrerAccount = LoyaltyAccount::forUser($referrer);
        $joiner = $this->customer();

        $referral = app(RegisterReferral::class)->execute($joiner, $referrerAccount->referral_code);

        $this->assertNotNull($referral);
        $this->assertSame(ReferralStatus::Pending, $referral->status);

        $joinerAccount = LoyaltyAccount::query()->where('user_id', $joiner->getKey())->sole();
        $this->assertSame(100, $joinerAccount->points_balance);

        // The referrer is not paid yet.
        $this->assertSame(0, $referrerAccount->fresh()->points_balance);
    }

    public function test_the_referrer_is_paid_only_after_the_first_completed_booking(): void
    {
        $referrer = $this->customer();
        $referrerAccount = LoyaltyAccount::forUser($referrer);
        $joiner = $this->customer();

        app(RegisterReferral::class)->execute($joiner, $referrerAccount->referral_code);

        // Nothing yet.
        $this->artisan('loyalty:process-awards')->assertSuccessful();
        $this->assertSame(0, $referrerAccount->fresh()->points_balance);

        $this->settleBooking($joiner);
        $this->artisan('loyalty:process-awards')->assertSuccessful();

        $this->assertSame(200, $referrerAccount->fresh()->points_balance);
        $this->assertSame(
            ReferralStatus::Rewarded,
            Referral::query()->where('referred_user_id', $joiner->getKey())->sole()->status,
        );
    }

    public function test_the_referral_reward_is_paid_only_once(): void
    {
        $referrer = $this->customer();
        $referrerAccount = LoyaltyAccount::forUser($referrer);
        $joiner = $this->customer();

        app(RegisterReferral::class)->execute($joiner, $referrerAccount->referral_code);
        $this->settleBooking($joiner);

        $this->artisan('loyalty:process-awards')->assertSuccessful();
        $this->artisan('loyalty:process-awards')->assertSuccessful();
        $this->artisan('loyalty:process-awards')->assertSuccessful();

        $this->assertSame(200, $referrerAccount->fresh()->points_balance);
        $this->assertSame(1, LoyaltyTransaction::query()
            ->where('type', LoyaltyTransactionType::ReferralReward->value)
            ->count());
    }

    public function test_self_referral_earns_nothing(): void
    {
        $customer = $this->customer();
        $account = LoyaltyAccount::forUser($customer);

        $referral = app(RegisterReferral::class)->execute($customer, $account->referral_code);

        $this->assertNull($referral);
        $this->assertDatabaseCount('referrals', 0);
        $this->assertSame(0, $account->fresh()->points_balance);
    }

    public function test_an_unknown_code_is_ignored_rather_than_rejected(): void
    {
        $joiner = $this->customer();

        // A mistyped code must never block registration.
        $referral = app(RegisterReferral::class)->execute($joiner, 'PISFAZZZZZ');

        $this->assertNull($referral);
        $this->assertDatabaseCount('referrals', 0);
    }

    public function test_a_person_can_only_be_referred_once(): void
    {
        $first = LoyaltyAccount::forUser($this->customer());
        $second = LoyaltyAccount::forUser($this->customer());
        $joiner = $this->customer();

        $a = app(RegisterReferral::class)->execute($joiner, $first->referral_code);
        $b = app(RegisterReferral::class)->execute($joiner, $second->referral_code);

        $this->assertTrue($a->is($b));
        $this->assertDatabaseCount('referrals', 1);
        // The welcome bonus is paid once, not twice.
        $this->assertSame(100, LoyaltyAccount::query()->where('user_id', $joiner->getKey())->sole()->points_balance);
    }

    public function test_registration_attaches_a_referral_from_the_form(): void
    {
        $referrer = $this->customer();
        $account = LoyaltyAccount::forUser($referrer);

        $this->post(route('register'), [
            'name' => 'New Joiner',
            'email' => 'joiner@example.test',
            'password' => 'Str0ng-Passw0rd!x',
            'password_confirmation' => 'Str0ng-Passw0rd!x',
            'referral_code' => $account->referral_code,
        ]);

        $joiner = User::query()->where('email', 'joiner@example.test')->sole();
        $this->assertDatabaseHas('referrals', [
            'referred_user_id' => $joiner->getKey(),
            'referrer_account_id' => $account->getKey(),
        ]);
    }

    // ---- Expiry ----------------------------------------------------------

    public function test_points_expire_after_the_inactivity_window(): void
    {
        $customer = $this->customer();
        $account = LoyaltyAccount::factory()
            ->withPoints(3_000)
            ->inactiveSince(13)
            ->create(['user_id' => $customer->getKey()]);

        $this->artisan('loyalty:expire-points')->assertSuccessful();

        $account->refresh();
        $this->assertSame(0, $account->points_balance);
        // Standing survives expiry.
        $this->assertSame(3_000, $account->lifetime_points);
        $this->assertSame(LoyaltyTier::Silver, $account->tier);

        Notification::assertSentTo($customer, LoyaltyPointsExpiredNotification::class);
    }

    public function test_an_active_balance_is_not_expired(): void
    {
        $customer = $this->customer();
        $account = LoyaltyAccount::factory()
            ->withPoints(3_000)
            ->inactiveSince(2)
            ->create(['user_id' => $customer->getKey()]);

        $this->artisan('loyalty:expire-points')->assertSuccessful();

        $this->assertSame(3_000, $account->fresh()->points_balance);
        Notification::assertNothingSentTo($customer);
    }

    public function test_a_warning_is_sent_before_expiry(): void
    {
        $customer = $this->customer();
        // Inside the 30-day warning window before the 12-month cutoff.
        $account = LoyaltyAccount::factory()
            ->withPoints(1_500)
            ->create([
                'user_id' => $customer->getKey(),
                'last_activity_at' => now()->subMonths(12)->addDays(10),
            ]);

        $this->artisan('loyalty:expire-points')->assertSuccessful();

        Notification::assertSentTo($customer, LoyaltyPointsExpiringNotification::class);
        $this->assertSame(1_500, $account->fresh()->points_balance, 'Warning only; nothing expired yet.');
        $this->assertNotNull($account->fresh()->expiry_warned_at);
    }

    public function test_a_warning_is_not_repeated_on_every_run(): void
    {
        $customer = $this->customer();
        LoyaltyAccount::factory()
            ->withPoints(1_500)
            ->create([
                'user_id' => $customer->getKey(),
                'last_activity_at' => now()->subMonths(12)->addDays(10),
            ]);

        $this->artisan('loyalty:expire-points')->assertSuccessful();
        $this->artisan('loyalty:expire-points')->assertSuccessful();
        $this->artisan('loyalty:expire-points')->assertSuccessful();

        Notification::assertSentToTimes($customer, LoyaltyPointsExpiringNotification::class, 1);
    }

    public function test_expiring_twice_in_the_same_period_is_a_no_op(): void
    {
        $customer = $this->customer();
        LoyaltyAccount::factory()
            ->withPoints(2_000)
            ->inactiveSince(14)
            ->create(['user_id' => $customer->getKey()]);

        $this->artisan('loyalty:expire-points')->assertSuccessful();
        $this->artisan('loyalty:expire-points')->assertSuccessful();

        $this->assertSame(1, LoyaltyTransaction::query()
            ->where('type', LoyaltyTransactionType::Expired->value)
            ->count());
    }
}
