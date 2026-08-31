<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\RecoverExpensesOnPayout;
use App\Actions\Finance\SaveExpense;
use App\Actions\Finance\TransitionExpense;
use App\Enums\AccountStatus;
use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Enums\LeasePayoutStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleLease;
use App\Models\VehicleLeasePayout;
use App\Notifications\Finance\ExpenseDecisionNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ExpensesTest extends TestCase
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

    private function driver(): User
    {
        return $this->user(UserRole::Driver, ['two_factor_required' => false]);
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    private function manager(): User
    {
        return $this->user(UserRole::Manager, [
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => ExpenseCategory::Fuel->value,
            'spent_on' => '2026-08-18',
            'amount' => '150000',
            'currency' => 'UGX',
            'description' => 'Diesel for the Kampala run',
            'supplier' => 'Shell Ntinda',
        ], $overrides);
    }

    // ---- Claiming ----------------------------------------------------------

    public function test_a_driver_can_claim_what_they_spent(): void
    {
        $driver = $this->driver();

        $expense = app(SaveExpense::class)->create($driver, $this->payload());

        $this->assertSame(ExpenseStatus::Draft, $expense->status);
        $this->assertSame($driver->getKey(), $expense->incurred_by_user_id);
        $this->assertSame(150_000, $expense->amount_minor);
        $this->assertDatabaseHas('audit_logs', ['event' => 'expense.created']);
    }

    public function test_a_customer_cannot_claim(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SaveExpense::class)->create($this->user(UserRole::Customer), $this->payload());
    }

    public function test_a_claim_for_nothing_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveExpense::class)->create($this->driver(), $this->payload(['amount' => '0']));
    }

    public function test_money_cannot_have_been_spent_in_the_future(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveExpense::class)->create($this->driver(), $this->payload(['spent_on' => '2026-09-30']));
    }

    public function test_office_spending_cannot_be_charged_to_a_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->expectException(ValidationException::class);

        app(SaveExpense::class)->create(
            $this->staff(),
            $this->payload(['category' => ExpenseCategory::Office->value]),
            $vehicle,
        );
    }

    public function test_fuel_can_be_charged_to_a_vehicle(): void
    {
        $vehicle = Vehicle::factory()->create();

        $expense = app(SaveExpense::class)->create($this->driver(), $this->payload(), $vehicle);

        $this->assertSame($vehicle->getKey(), $expense->vehicle_id);
    }

    public function test_an_approved_claim_can_no_longer_be_edited(): void
    {
        $expense = Expense::factory()->by($this->driver())
            ->status(ExpenseStatus::Approved)->create();

        $this->expectException(ValidationException::class);

        app(SaveExpense::class)->update($this->manager(), $expense, $this->payload(['amount' => '999999']));
    }

    public function test_somebody_elses_claim_cannot_be_edited_by_a_colleague(): void
    {
        $expense = Expense::factory()->by($this->driver())->create();

        $this->expectException(ValidationException::class);

        app(SaveExpense::class)->update($this->driver(), $expense, $this->payload());
    }

    // ---- Approval ------------------------------------------------------------

    public function test_every_expense_status_is_reachable(): void
    {
        $driver = $this->driver();
        $manager = $this->manager();
        $action = app(TransitionExpense::class);

        $expense = Expense::factory()->by($driver)->create();
        $this->assertSame(ExpenseStatus::Draft, $expense->status);

        $submitted = $action->submit($driver, $expense);
        $this->assertSame(ExpenseStatus::Submitted, $submitted->status);

        $returned = $action->returnToDraft($manager, $submitted, 'The receipt is unreadable.');
        $this->assertSame(ExpenseStatus::Draft, $returned->status);

        $resubmitted = $action->submit($driver, $returned);
        $approved = $action->approve($manager, $resubmitted);
        $this->assertSame(ExpenseStatus::Approved, $approved->status);

        $reimbursed = $action->reimburse($manager, $approved, 'MM-55443322');
        $this->assertSame(ExpenseStatus::Reimbursed, $reimbursed->status);
        $this->assertSame('MM-55443322', $reimbursed->reimbursement_reference);

        $rejected = $action->reject(
            $manager,
            $action->submit($driver, Expense::factory()->by($driver)->create()),
            'Not a business expense.',
        );
        $this->assertSame(ExpenseStatus::Rejected, $rejected->status);
    }

    public function test_nobody_can_approve_their_own_claim(): void
    {
        $manager = $this->manager();
        $expense = Expense::factory()->by($manager)->status(ExpenseStatus::Submitted)->create();

        $this->expectException(ValidationException::class);

        app(TransitionExpense::class)->approve($manager, $expense);
    }

    public function test_staff_cannot_approve_a_claim(): void
    {
        $expense = Expense::factory()->by($this->driver())->status(ExpenseStatus::Submitted)->create();

        $this->expectException(AuthorizationException::class);

        app(TransitionExpense::class)->approve($this->staff(), $expense);
    }

    public function test_reimbursing_requires_a_transfer_reference(): void
    {
        $expense = Expense::factory()->by($this->driver())->status(ExpenseStatus::Approved)->create();

        $this->expectException(ValidationException::class);

        app(TransitionExpense::class)->reimburse($this->manager(), $expense, '  ');
    }

    public function test_a_reimbursed_claim_is_final(): void
    {
        $expense = Expense::factory()->by($this->driver())
            ->status(ExpenseStatus::Reimbursed)->create();

        $this->assertSame([], $expense->status->allowedTransitions());

        $this->expectException(ValidationException::class);

        app(TransitionExpense::class)->reject($this->manager(), $expense, 'Changed our mind.');
    }

    public function test_only_vehicle_spending_of_a_recoverable_kind_can_be_charged_to_an_owner(): void
    {
        $vehicle = Vehicle::factory()->create();

        // Fuel is PISFA's cost: it earns the hire income, so it buys the fuel.
        $fuel = Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->category(ExpenseCategory::Fuel)
            ->status(ExpenseStatus::Submitted)->create();

        $this->expectException(ValidationException::class);

        app(TransitionExpense::class)->approve($this->manager(), $fuel, recoverable: true);
    }

    public function test_maintenance_on_a_vehicle_can_be_marked_recoverable(): void
    {
        $vehicle = Vehicle::factory()->create();

        $repair = Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->category(ExpenseCategory::Maintenance)
            ->status(ExpenseStatus::Submitted)->create();

        $approved = app(TransitionExpense::class)->approve($this->manager(), $repair, recoverable: true);

        $this->assertTrue($approved->is_recoverable);
    }

    public function test_rejecting_clears_the_recoverable_marker(): void
    {
        $vehicle = Vehicle::factory()->create();

        $repair = Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->create(['status' => ExpenseStatus::Approved]);

        $rejected = app(TransitionExpense::class)
            ->reject($this->manager(), $repair, 'Charged to the wrong vehicle.');

        $this->assertFalse($rejected->is_recoverable);
    }

    public function test_a_decision_notifies_the_claimant(): void
    {
        $driver = $this->driver();
        $expense = Expense::factory()->by($driver)->status(ExpenseStatus::Submitted)->create();

        app(TransitionExpense::class)->approve($this->manager(), $expense);

        Notification::assertSentTo($driver, ExpenseDecisionNotification::class);
    }

    // ---- Recovery against a lease ------------------------------------------------

    /** @return array{0: VehicleLease, 1: Vehicle, 2: VehicleLeasePayout} */
    private function leaseWithPayout(int $earnedMinor = 900_000, string $currency = 'UGX'): array
    {
        $vehicle = Vehicle::factory()->create();
        $lease = VehicleLease::factory()->forVehicle($vehicle)->active()->create(['currency' => $currency]);

        $payout = VehicleLeasePayout::factory()->forLease($lease)->month('2026-07-01')->create([
            'earned_minor' => $earnedMinor,
            'net_payable_minor' => $earnedMinor,
            'currency' => $currency,
        ]);

        return [$lease, $vehicle, $payout];
    }

    public function test_approved_vehicle_spending_is_charged_to_the_statement(): void
    {
        [, $vehicle, $payout] = $this->leaseWithPayout();

        Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->amount(200_000)->spentOn('2026-07-14')->create();

        $updated = app(RecoverExpensesOnPayout::class)->execute($this->manager(), $payout);

        $this->assertSame(200_000, $updated->deductions_minor);
        $this->assertSame(700_000, $updated->net_payable_minor);
    }

    public function test_the_same_repair_cannot_be_recovered_twice(): void
    {
        [$lease, $vehicle, $payout] = $this->leaseWithPayout();
        $manager = $this->manager();

        Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->amount(200_000)->spentOn('2026-07-14')->create();

        app(RecoverExpensesOnPayout::class)->execute($manager, $payout);

        $second = VehicleLeasePayout::factory()->forLease($lease)->month('2026-08-01')->create([
            'earned_minor' => 900_000,
            'net_payable_minor' => 900_000,
        ]);

        $this->expectException(ValidationException::class);

        app(RecoverExpensesOnPayout::class)->execute($manager, $second);
    }

    public function test_spending_outside_the_period_is_left_alone(): void
    {
        [, $vehicle, $payout] = $this->leaseWithPayout();

        Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->amount(200_000)->spentOn('2026-06-14')->create();

        $this->expectException(ValidationException::class);

        app(RecoverExpensesOnPayout::class)->execute($this->manager(), $payout);
    }

    public function test_spending_in_another_currency_is_not_netted_off(): void
    {
        [, $vehicle, $payout] = $this->leaseWithPayout(currency: 'UGX');

        Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->amount(50_00, 'USD')->spentOn('2026-07-14')->create();

        $this->expectException(ValidationException::class);

        app(RecoverExpensesOnPayout::class)->execute($this->manager(), $payout);
    }

    public function test_spending_larger_than_the_earnings_is_refused(): void
    {
        [, $vehicle, $payout] = $this->leaseWithPayout(earnedMinor: 100_000);

        Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->amount(400_000)->spentOn('2026-07-14')->create();

        $this->expectException(ValidationException::class);

        app(RecoverExpensesOnPayout::class)->execute($this->manager(), $payout);
    }

    public function test_an_approved_statement_cannot_take_more_spending(): void
    {
        [$lease, $vehicle] = $this->leaseWithPayout();

        $payout = VehicleLeasePayout::factory()->forLease($lease)->month('2026-06-01')
            ->status(LeasePayoutStatus::Approved)->create(['earned_minor' => 900_000]);

        Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->amount(100_000)->spentOn('2026-06-14')->create();

        $this->expectException(ValidationException::class);

        app(RecoverExpensesOnPayout::class)->execute($this->manager(), $payout);
    }

    public function test_releasing_a_statement_frees_the_spending_again(): void
    {
        [, $vehicle, $payout] = $this->leaseWithPayout();
        $manager = $this->manager();
        $action = app(RecoverExpensesOnPayout::class);

        $expense = Expense::factory()->by($this->driver())->forVehicle($vehicle)
            ->recoverable()->amount(200_000)->spentOn('2026-07-14')->create();

        $action->execute($manager, $payout);
        $this->assertNotNull($expense->fresh()->recovered_on_payout_id);

        $released = $action->release($manager, $payout);

        $this->assertSame(1, $released);
        $this->assertNull($expense->fresh()->recovered_on_payout_id);
    }

    // ---- Surfaces --------------------------------------------------------------------

    public function test_a_driver_sees_only_their_own_claims(): void
    {
        $driver = $this->driver();
        $mine = Expense::factory()->by($driver)->create(['description' => 'My own diesel']);
        Expense::factory()->by($this->driver())->create(['description' => 'Somebody elses diesel']);

        $this->actingAs($driver)
            ->get(route('portal.expenses.index'))
            ->assertOk()
            ->assertSee($mine->description)
            ->assertDontSee('Somebody elses diesel');
    }

    public function test_a_driver_cannot_open_the_review_queue(): void
    {
        $this->actingAs($this->driver())
            ->get(route('admin.expenses.index'))
            ->assertForbidden();
    }

    public function test_a_manager_works_the_review_queue(): void
    {
        $manager = $this->manager();
        $expense = Expense::factory()->by($this->driver())->status(ExpenseStatus::Submitted)->create();

        $this->actingAs($manager)
            ->withSession([
                EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->timestamp,
                EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $manager->id,
            ])
            ->get(route('admin.expenses.index'))
            ->assertOk()
            ->assertSee($expense->reference);
    }

    public function test_a_customer_cannot_reach_the_claims_page(): void
    {
        $this->actingAs($this->user(UserRole::Customer))
            ->get(route('portal.expenses.index'))
            ->assertForbidden();
    }

    public function test_internal_notes_are_never_serialised(): void
    {
        $expense = Expense::factory()->create(['internal_notes' => 'Driver has claimed this twice before']);

        $this->assertArrayNotHasKey('internal_notes', $expense->toArray());
    }
}
