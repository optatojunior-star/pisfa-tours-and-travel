<?php

namespace Tests\Feature\Loyalty;

use App\Actions\Loyalty\RecordLoyaltyMovement;
use App\Actions\Loyalty\RedeemLoyaltyPoints;
use App\Enums\AccountStatus;
use App\Enums\LoyaltyTier;
use App\Enums\LoyaltyTransactionType;
use App\Enums\UserRole;
use App\Models\LoyaltyAccount;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LoyaltyPointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function customer(): User
    {
        return User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    // ---- Tier thresholds and discounts (fixed by the brief) --------------

    public function test_tier_thresholds_match_the_specification(): void
    {
        $this->assertSame(0, LoyaltyTier::Bronze->threshold());
        $this->assertSame(1_000, LoyaltyTier::Silver->threshold());
        $this->assertSame(5_000, LoyaltyTier::Gold->threshold());
        $this->assertSame(20_000, LoyaltyTier::Platinum->threshold());
    }

    public function test_tier_discounts_match_the_specification(): void
    {
        $this->assertSame(0, LoyaltyTier::Bronze->discountPercent());
        $this->assertSame(5, LoyaltyTier::Silver->discountPercent());
        $this->assertSame(10, LoyaltyTier::Gold->discountPercent());
        $this->assertSame(15, LoyaltyTier::Platinum->discountPercent());
    }

    /** @dataProvider tierBoundaries */
    public function test_lifetime_points_resolve_to_the_right_tier(int $points, LoyaltyTier $expected): void
    {
        $this->assertSame($expected, LoyaltyTier::forLifetimePoints($points));
    }

    /** @return array<string, array{0: int, 1: LoyaltyTier}> */
    public static function tierBoundaries(): array
    {
        return [
            'zero' => [0, LoyaltyTier::Bronze],
            'just below silver' => [999, LoyaltyTier::Bronze],
            'exactly silver' => [1_000, LoyaltyTier::Silver],
            'just below gold' => [4_999, LoyaltyTier::Silver],
            'exactly gold' => [5_000, LoyaltyTier::Gold],
            'just below platinum' => [19_999, LoyaltyTier::Gold],
            'exactly platinum' => [20_000, LoyaltyTier::Platinum],
            'far above platinum' => [500_000, LoyaltyTier::Platinum],
        ];
    }

    public function test_the_discount_uses_integer_arithmetic_and_favours_the_customer(): void
    {
        // 5% of 1999 is 99.95; flooring gives the customer the whole shilling.
        $this->assertSame(99, LoyaltyTier::Silver->applyDiscount(1_999));
        $this->assertSame(0, LoyaltyTier::Bronze->applyDiscount(1_000_000));
        $this->assertSame(150_000, LoyaltyTier::Platinum->applyDiscount(1_000_000));
    }

    // ---- Ledger integrity -----------------------------------------------

    public function test_earning_points_raises_balance_lifetime_and_tier(): void
    {
        $account = LoyaltyAccount::forUser($this->customer());

        app(RecordLoyaltyMovement::class)->execute(
            account: $account,
            type: LoyaltyTransactionType::Earned,
            points: 1_200,
            description: 'Test award.',
            idempotencyKey: 'test-1',
        );

        $account->refresh();
        $this->assertSame(1_200, $account->points_balance);
        $this->assertSame(1_200, $account->lifetime_points);
        $this->assertSame(LoyaltyTier::Silver, $account->tier);
        $this->assertDatabaseHas('audit_logs', ['event' => 'loyalty.earned']);
    }

    public function test_redeeming_never_lowers_the_tier(): void
    {
        $account = LoyaltyAccount::forUser($this->customer());
        $ledger = app(RecordLoyaltyMovement::class);

        $ledger->execute($account, LoyaltyTransactionType::Earned, 5_500, 'Earned.', 'e1');
        $this->assertSame(LoyaltyTier::Gold, $account->fresh()->tier);

        $ledger->execute($account->fresh(), LoyaltyTransactionType::Redeemed, 5_000, 'Redeemed.', 'r1');

        $account->refresh();
        $this->assertSame(500, $account->points_balance);
        // Lifetime standing is earned, not spendable.
        $this->assertSame(5_500, $account->lifetime_points);
        $this->assertSame(LoyaltyTier::Gold, $account->tier);
    }

    public function test_a_repeated_idempotency_key_awards_only_once(): void
    {
        $account = LoyaltyAccount::forUser($this->customer());
        $ledger = app(RecordLoyaltyMovement::class);

        $first = $ledger->execute($account, LoyaltyTransactionType::Earned, 300, 'Award.', 'same-key');
        $second = $ledger->execute($account->fresh(), LoyaltyTransactionType::Earned, 300, 'Award.', 'same-key');

        $this->assertNotNull($first);
        $this->assertNull($second, 'A replayed movement returns null rather than double-crediting.');
        $this->assertSame(300, $account->fresh()->points_balance);
        $this->assertDatabaseCount('loyalty_transactions', 1);
    }

    public function test_the_balance_can_never_go_negative(): void
    {
        $account = LoyaltyAccount::forUser($this->customer());
        app(RecordLoyaltyMovement::class)->execute($account, LoyaltyTransactionType::Earned, 100, 'Award.', 'e1');

        $this->expectException(ValidationException::class);

        app(RecordLoyaltyMovement::class)
            ->execute($account->fresh(), LoyaltyTransactionType::Redeemed, 500, 'Too much.', 'r1');
    }

    public function test_the_running_balance_is_recorded_on_every_row(): void
    {
        $account = LoyaltyAccount::forUser($this->customer());
        $ledger = app(RecordLoyaltyMovement::class);

        $a = $ledger->execute($account, LoyaltyTransactionType::Earned, 1_000, 'One.', 'k1');
        $b = $ledger->execute($account->fresh(), LoyaltyTransactionType::Earned, 500, 'Two.', 'k2');
        $c = $ledger->execute($account->fresh(), LoyaltyTransactionType::Redeemed, 600, 'Three.', 'k3');

        $this->assertSame(1_000, $a->balance_after);
        $this->assertSame(1_500, $b->balance_after);
        $this->assertSame(900, $c->balance_after);
        $this->assertSame(-600, $c->points, 'A debit is stored signed.');
    }

    // ---- Redemption rules -----------------------------------------------

    public function test_redemption_honours_the_five_hundred_point_minimum(): void
    {
        $customer = $this->customer();
        LoyaltyAccount::factory()->withPoints(600)->create(['user_id' => $customer->getKey()]);

        $this->expectException(ValidationException::class);

        app(RedeemLoyaltyPoints::class)->execute($customer, 499, (string) Str::uuid());
    }

    public function test_redemption_values_points_at_one_hundred_each(): void
    {
        $customer = $this->customer();
        LoyaltyAccount::factory()->withPoints(1_000)->create(['user_id' => $customer->getKey()]);

        $transaction = app(RedeemLoyaltyPoints::class)
            ->execute($customer, 500, (string) Str::uuid());

        // 500 points x UGX 100 = UGX 50,000.
        $this->assertSame(50_000, $transaction->payload['value_minor']);
        $this->assertSame(50_000, $transaction->valueMinor());
        $this->assertSame(
            500,
            LoyaltyAccount::query()->where('user_id', $customer->getKey())->sole()->points_balance,
        );
    }

    public function test_redemption_cannot_exceed_the_balance(): void
    {
        $customer = $this->customer();
        LoyaltyAccount::factory()->withPoints(700)->create(['user_id' => $customer->getKey()]);

        $this->expectException(ValidationException::class);

        app(RedeemLoyaltyPoints::class)->execute($customer, 800, (string) Str::uuid());
    }

    public function test_repeating_a_redemption_key_does_not_redeem_twice(): void
    {
        $customer = $this->customer();
        LoyaltyAccount::factory()->withPoints(2_000)->create(['user_id' => $customer->getKey()]);
        $key = (string) Str::uuid();
        $action = app(RedeemLoyaltyPoints::class);

        $first = $action->execute($customer, 500, $key);
        $second = $action->execute($customer, 500, $key);

        $this->assertTrue($first->is($second));
        $this->assertSame(1_500, LoyaltyAccount::query()->where('user_id', $customer->getKey())->sole()->points_balance);
    }

    public function test_a_non_customer_cannot_redeem(): void
    {
        $staff = User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
        LoyaltyAccount::factory()->withPoints(2_000)->create(['user_id' => $staff->getKey()]);

        $this->expectException(AuthorizationException::class);

        app(RedeemLoyaltyPoints::class)->execute($staff, 500, (string) Str::uuid());
    }

    public function test_a_suspended_customer_cannot_redeem(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['status' => AccountStatus::Suspended])->save();
        LoyaltyAccount::factory()->withPoints(2_000)->create(['user_id' => $customer->getKey()]);

        $this->expectException(AuthorizationException::class);

        app(RedeemLoyaltyPoints::class)->execute($customer->fresh(), 500, (string) Str::uuid());
    }

    // ---- Referral codes --------------------------------------------------

    public function test_referral_codes_are_unique_and_readable(): void
    {
        $codes = [];

        for ($i = 0; $i < 25; $i++) {
            $code = LoyaltyAccount::generateReferralCode();
            $this->assertMatchesRegularExpression('/\APISFA[A-HJ-NP-Z2-9]{5}\z/', $code);
            $codes[] = $code;
        }

        // No 0/O or 1/I confusion, and no duplicates in a small sample.
        $this->assertSame(count($codes), count(array_unique($codes)));
    }

    public function test_an_account_is_created_on_demand_with_a_code(): void
    {
        $customer = $this->customer();

        $account = LoyaltyAccount::forUser($customer);
        $again = LoyaltyAccount::forUser($customer);

        $this->assertTrue($account->is($again), 'forUser is idempotent.');
        $this->assertNotEmpty($account->referral_code);
        $this->assertSame(LoyaltyTier::Bronze, $account->tier);
        $this->assertDatabaseCount('loyalty_accounts', 1);
    }
}
