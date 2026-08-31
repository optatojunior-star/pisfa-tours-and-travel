<?php

namespace Tests\Feature\Finance;

use App\Actions\Finance\BuildPayrollRun;
use App\Actions\Finance\TransitionPayrollRun;
use App\Enums\AccountStatus;
use App\Enums\DocumentCategory;
use App\Enums\PayrollDeductionType;
use App\Enums\PayrollRunStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\Finance\PayslipAvailableNotification;
use App\Support\Payroll\TaxSchedule;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PayrollTest extends TestCase
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

    /** Payroll is super-administrator only, and that role always needs 2FA. */
    private function officer(): User
    {
        return $this->user(UserRole::SuperAdmin, [
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function verifiedSession(User $officer): array
    {
        return [
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->timestamp,
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $officer->id,
        ];
    }

    private function employee(UserRole $role = UserRole::Staff): User
    {
        return $this->user($role, ['two_factor_required' => false]);
    }

    /** @return array<string, mixed> */
    private function linePayload(array $overrides = []): array
    {
        return array_merge([
            'gross' => '1200000',
            'currency' => 'UGX',
        ], $overrides);
    }

    // ---- The tax arithmetic --------------------------------------------------

    public function test_nssf_is_five_percent_of_earnings(): void
    {
        $this->assertSame(60_000, TaxSchedule::employeeNssf(1_200_000, 'UGX'));
    }

    public function test_the_employer_contributes_ten_percent_on_top(): void
    {
        $this->assertSame(120_000, TaxSchedule::employerNssf(1_200_000, 'UGX'));
    }

    public function test_paye_is_nothing_below_the_threshold(): void
    {
        $this->assertSame(0, TaxSchedule::paye(200_000, 'UGX'));
    }

    public function test_paye_is_charged_band_by_band(): void
    {
        // 300,000: nothing on the first 235,000, then 10% of the next 65,000.
        $this->assertSame(6_500, TaxSchedule::paye(300_000, 'UGX'));

        // 400,000: 10% of 100,000, then 20% of 65,000.
        $this->assertSame(10_000 + 13_000, TaxSchedule::paye(400_000, 'UGX'));
    }

    public function test_the_top_band_carries_its_surcharge(): void
    {
        $under = TaxSchedule::paye(10_000_000, 'UGX');
        $over = TaxSchedule::paye(11_000_000, 'UGX');

        // The extra million attracts the 30% marginal rate plus the 10% levy.
        $this->assertSame($under + 300_000 + 100_000, $over);
    }

    public function test_paye_cannot_be_charged_in_a_currency_the_bands_are_not_written_in(): void
    {
        $this->assertFalse(TaxSchedule::supportsPaye('USD'));

        $this->expectException(\InvalidArgumentException::class);

        TaxSchedule::paye(500_000, 'USD');
    }

    public function test_a_rate_rounds_half_up_rather_than_truncating(): void
    {
        // 5% of 1,000,005 is 50,000.25 → 50,000; of 1,000,090 is 50,004.5 → 50,005.
        $this->assertSame(50_000, TaxSchedule::applyRate(1_000_005, 500));
        $this->assertSame(50_005, TaxSchedule::applyRate(1_000_090, 500));
    }

    // ---- The run --------------------------------------------------------------

    public function test_a_run_opens_once_per_month_and_currency(): void
    {
        $officer = $this->officer();
        $action = app(BuildPayrollRun::class);

        $first = $action->open($officer, '2026-07-10', 'UGX');
        $second = $action->open($officer, '2026-07-28', 'UGX');

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PayrollRun::query()->count());
    }

    public function test_a_second_currency_is_a_separate_run(): void
    {
        $officer = $this->officer();
        $action = app(BuildPayrollRun::class);

        $action->open($officer, '2026-07-10', 'UGX');
        $action->open($officer, '2026-07-10', 'USD');

        $this->assertSame(2, PayrollRun::query()->count());
    }

    public function test_the_database_refuses_two_runs_for_one_period_and_currency(): void
    {
        PayrollRun::factory()->month('2026-07-01')->currency('UGX')->create();

        $this->expectException(QueryException::class);

        PayrollRun::factory()->month('2026-07-15')->currency('UGX')->create();
    }

    public function test_only_a_super_administrator_may_run_payroll(): void
    {
        $manager = $this->user(UserRole::Manager, ['two_factor_required' => false]);

        $this->expectException(AuthorizationException::class);

        app(BuildPayrollRun::class)->open($manager, '2026-07-10', 'UGX');
    }

    public function test_paye_is_charged_after_nssf_not_on_the_full_gross(): void
    {
        $officer = $this->officer();
        $employee = $this->employee();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $line = $build->setLine($officer, $run, $employee, $this->linePayload(['gross' => '1200000']));

        $nssf = TaxSchedule::employeeNssf(1_200_000, 'UGX');
        $expectedPaye = TaxSchedule::paye(1_200_000 - $nssf, 'UGX');

        $paye = $line->deductions->firstWhere('type', PayrollDeductionType::Paye);

        $this->assertNotNull($paye);
        $this->assertSame($expectedPaye, $paye->amount_minor);
        // The basis is kept so the payslip explains itself.
        $this->assertSame(1_200_000 - $nssf, $paye->basis_minor);

        // And it is genuinely less than charging PAYE on the whole gross.
        $this->assertLessThan(TaxSchedule::paye(1_200_000, 'UGX'), $paye->amount_minor);
    }

    public function test_the_net_is_earnings_less_every_deduction(): void
    {
        $officer = $this->officer();
        $employee = $this->employee();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $line = $build->setLine($officer, $run, $employee, $this->linePayload([
            'gross' => '1200000',
            'allowances' => '100000',
        ]));

        $this->assertSame(1_300_000, $line->totalEarningsMinor());
        $this->assertSame(
            $line->totalEarningsMinor() - $line->deductions_minor,
            $line->net_minor,
        );
        $this->assertSame((int) $line->deductions->sum('amount_minor'), $line->deductions_minor);
    }

    public function test_the_employer_contribution_is_not_deducted_from_the_employee(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $line = $build->setLine($officer, $run, $this->employee(), $this->linePayload());

        $this->assertSame(120_000, $line->employer_nssf_minor);
        $this->assertNull($line->deductions->firstWhere('amount_minor', 120_000));
    }

    public function test_a_computed_deduction_cannot_be_typed_in(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);
        $run = $build->open($officer, '2026-07-10', 'UGX');

        $this->expectException(ValidationException::class);

        $build->setLine($officer, $run, $this->employee(), $this->linePayload([
            'deductions' => [['type' => PayrollDeductionType::Paye->value, 'amount' => '1']],
        ]));
    }

    public function test_a_salary_advance_can_be_typed_in(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);
        $run = $build->open($officer, '2026-07-10', 'UGX');

        $line = $build->setLine($officer, $run, $this->employee(), $this->linePayload([
            'deductions' => [[
                'type' => PayrollDeductionType::SalaryAdvance->value,
                'amount' => '200000',
                'label' => 'Advance taken in June',
            ]],
        ]));

        $advance = $line->deductions->firstWhere('type', PayrollDeductionType::SalaryAdvance);

        $this->assertNotNull($advance);
        $this->assertSame(200_000, $advance->amount_minor);
    }

    public function test_deductions_cannot_exceed_what_was_earned(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);
        $run = $build->open($officer, '2026-07-10', 'UGX');

        $this->expectException(ValidationException::class);

        $build->setLine($officer, $run, $this->employee(), $this->linePayload([
            'gross' => '500000',
            'deductions' => [[
                'type' => PayrollDeductionType::SalaryAdvance->value,
                'amount' => '600000',
            ]],
        ]));
    }

    public function test_a_customer_is_not_on_the_payroll(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);
        $run = $build->open($officer, '2026-07-10', 'UGX');

        $this->expectException(ValidationException::class);

        $build->setLine($officer, $run, $this->user(UserRole::Customer), $this->linePayload());
    }

    public function test_recomputing_a_line_replaces_its_deductions_rather_than_adding_to_them(): void
    {
        $officer = $this->officer();
        $employee = $this->employee();
        $build = app(BuildPayrollRun::class);
        $run = $build->open($officer, '2026-07-10', 'UGX');

        $build->setLine($officer, $run, $employee, $this->linePayload());
        $again = $build->setLine($officer, $run, $employee, $this->linePayload(['gross' => '1500000']));

        $this->assertSame(1, PayrollLine::query()->count());
        $this->assertSame(
            (int) $again->deductions->sum('amount_minor'),
            $again->deductions_minor,
        );
    }

    public function test_the_database_refuses_two_lines_for_one_person(): void
    {
        $run = PayrollRun::factory()->create();
        $employee = $this->employee();

        PayrollLine::factory()->forRun($run)->forEmployee($employee)->create();

        $this->expectException(QueryException::class);

        PayrollLine::factory()->forRun($run)->forEmployee($employee)->create();
    }

    public function test_the_run_totals_follow_its_lines(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);
        $run = $build->open($officer, '2026-07-10', 'UGX');

        $build->setLine($officer, $run, $this->employee(), $this->linePayload(['gross' => '1200000']));
        $build->setLine($officer, $run, $this->employee(UserRole::Driver), $this->linePayload(['gross' => '800000']));

        $run->refresh();

        $this->assertSame(2, $run->employee_count);
        $this->assertSame(2_000_000, $run->gross_total_minor);
        $this->assertSame(
            $run->gross_total_minor - $run->deductions_total_minor,
            $run->net_total_minor,
        );
        // What the month costs: the full gross plus the employer's own share.
        $this->assertGreaterThan($run->gross_total_minor, $run->employer_cost_minor);
    }

    public function test_removing_a_line_updates_the_totals(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);
        $run = $build->open($officer, '2026-07-10', 'UGX');

        $line = $build->setLine($officer, $run, $this->employee(), $this->linePayload());
        $build->removeLine($officer, $run, $line);

        $run->refresh();

        $this->assertSame(0, $run->employee_count);
        $this->assertSame(0, $run->net_total_minor);
    }

    // ---- Approval and payslips ---------------------------------------------------

    public function test_an_empty_run_cannot_be_approved(): void
    {
        $officer = $this->officer();
        $run = app(BuildPayrollRun::class)->open($officer, '2026-07-10', 'UGX');

        $this->expectException(ValidationException::class);

        app(TransitionPayrollRun::class)->approve($officer, $run);
    }

    public function test_approving_files_a_payslip_and_tells_each_person(): void
    {
        $officer = $this->officer();
        $employee = $this->employee();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $build->setLine($officer, $run, $employee, $this->linePayload());

        app(TransitionPayrollRun::class)->approve($officer, $run->fresh());

        $this->assertSame(1, Document::query()
            ->where('category', DocumentCategory::Payslip->value)
            ->count());

        Notification::assertSentTo($employee, PayslipAvailableNotification::class);
    }

    public function test_approving_twice_does_not_file_a_second_payslip(): void
    {
        $officer = $this->officer();
        $employee = $this->employee();
        $build = app(BuildPayrollRun::class);
        $transition = app(TransitionPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $build->setLine($officer, $run, $employee, $this->linePayload());

        $approved = $transition->approve($officer, $run->fresh());
        $transition->approve($officer, $approved);

        Notification::assertSentToTimes($employee, PayslipAvailableNotification::class, 1);
    }

    public function test_an_approved_run_cannot_take_new_lines(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $build->setLine($officer, $run, $this->employee(), $this->linePayload());
        app(TransitionPayrollRun::class)->approve($officer, $run->fresh());

        $this->expectException(ValidationException::class);

        $build->setLine($officer, $run->fresh(), $this->employee(), $this->linePayload());
    }

    public function test_every_run_status_is_reachable(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);
        $transition = app(TransitionPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $build->setLine($officer, $run, $this->employee(), $this->linePayload());

        $approved = $transition->approve($officer, $run->fresh());
        $this->assertSame(PayrollRunStatus::Approved, $approved->status);

        $reopened = $transition->reopen($officer, $approved, 'An allowance was missed.');
        $this->assertSame(PayrollRunStatus::Draft, $reopened->status);

        $again = $transition->approve($officer, $reopened);
        $paid = $transition->markPaid($officer, $again, 'BANK-99887766');
        $this->assertSame(PayrollRunStatus::Paid, $paid->status);

        $cancelled = $transition->cancel(
            $officer,
            $build->open($officer, '2026-06-10', 'UGX'),
            'Raised in error.',
        );
        $this->assertSame(PayrollRunStatus::Cancelled, $cancelled->status);
    }

    public function test_a_paid_run_is_final(): void
    {
        $officer = $this->officer();
        $run = PayrollRun::factory()->status(PayrollRunStatus::Paid)->withEmployees()->create();

        $this->assertSame([], $run->status->allowedTransitions());

        $this->expectException(ValidationException::class);

        app(TransitionPayrollRun::class)->reopen($officer, $run, 'We got it wrong.');
    }

    public function test_marking_paid_requires_a_transfer_reference(): void
    {
        $officer = $this->officer();
        $run = PayrollRun::factory()->status(PayrollRunStatus::Approved)->withEmployees()->create();

        $this->expectException(ValidationException::class);

        app(TransitionPayrollRun::class)->markPaid($officer, $run, ' ');
    }

    public function test_the_audit_trail_records_totals_not_individual_salaries(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $build->setLine($officer, $run, $this->employee(), $this->linePayload(['gross' => '1200000']));
        app(TransitionPayrollRun::class)->approve($officer, $run->fresh());

        $entry = AuditLog::query()->where('event', 'payroll_run.approved')->firstOrFail();
        $values = (array) $entry->new_values;

        $this->assertArrayHasKey('net_total_minor', $values);
        $this->assertArrayNotHasKey('employee_name_snapshot', $values);
    }

    // ---- Who can see what ------------------------------------------------------------

    public function test_an_employee_sees_their_own_payslip_only_once_the_run_is_approved(): void
    {
        $officer = $this->officer();
        $employee = $this->employee();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $line = $build->setLine($officer, $run, $employee, $this->linePayload());

        $this->assertFalse($employee->can('view', $line));

        app(TransitionPayrollRun::class)->approve($officer, $run->fresh());

        $this->assertTrue($employee->can('view', $line->fresh()));
    }

    public function test_an_employee_cannot_read_a_colleagues_payslip(): void
    {
        $officer = $this->officer();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $theirs = $build->setLine($officer, $run, $this->employee(), $this->linePayload());
        app(TransitionPayrollRun::class)->approve($officer, $run->fresh());

        $this->assertFalse($this->employee()->can('view', $theirs->fresh()));
    }

    public function test_a_manager_cannot_reach_payroll(): void
    {
        $manager = $this->user(UserRole::Manager, [
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($manager)
            ->withSession($this->verifiedSession($manager))
            ->get(route('admin.payroll.index'))
            ->assertForbidden();
    }

    public function test_a_super_administrator_works_the_payroll_console(): void
    {
        $officer = $this->officer();
        $run = PayrollRun::factory()->create();

        $this->actingAs($officer)
            ->withSession($this->verifiedSession($officer))
            ->get(route('admin.payroll.index'))
            ->assertOk();

        $this->actingAs($officer)
            ->withSession($this->verifiedSession($officer))
            ->get(route('admin.payroll.show', $run))
            ->assertOk();
    }

    public function test_an_employee_reads_their_payslips_in_the_portal(): void
    {
        $officer = $this->officer();
        $employee = $this->employee();
        $build = app(BuildPayrollRun::class);

        $run = $build->open($officer, '2026-07-10', 'UGX');
        $build->setLine($officer, $run, $employee, $this->linePayload());
        app(TransitionPayrollRun::class)->approve($officer, $run->fresh());

        $this->actingAs($employee)
            ->get(route('portal.payslips.index'))
            ->assertOk()
            ->assertSee($run->monthLabel());
    }
}
