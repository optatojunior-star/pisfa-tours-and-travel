<?php

namespace App\Actions\Finance;

use App\Enums\PayrollDeductionType;
use App\Enums\PayrollRunStatus;
use App\Models\PayrollDeduction;
use App\Models\PayrollLine;
use App\Models\PayrollRun;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\Payroll\TaxSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Opens a month's payroll and works out what each person takes home.
 *
 * The deduction order is the substance of this action: NSSF comes off gross,
 * and PAYE is charged on what is left. Doing it the other way round overstates
 * the tax every single month, in the employer's favour, which is exactly the
 * sort of error nobody notices until an audit.
 */
class BuildPayrollRun
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** Opens (or returns) the draft run for a month and currency. */
    public function open(User $actor, string $anyDateInMonth, string $currency): PayrollRun
    {
        $currency = strtoupper(trim($currency));

        Validator::make(
            ['currency' => $currency],
            ['currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))]],
        )->validate();

        $month = CarbonImmutable::parse($anyDateInMonth)->startOfMonth();

        return DB::transaction(function () use ($actor, $month, $currency): PayrollRun {
            $lockedActor = FinanceAccess::lockedPayrollOfficer($actor);

            // whereDate, not where: the date cast writes a midnight time
            // component, so an equality match on the bare date would find
            // nothing and open a second run for the same month.
            $existing = PayrollRun::query()
                ->whereDate('period_start', $month->toDateString())
                ->where('currency', $currency)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $run = new PayrollRun;
            $run->forceFill([
                'reference' => 'PR-'.Str::upper((string) Str::ulid()),
                'status' => PayrollRunStatus::Draft,
                'period_start' => $month->toDateString(),
                'period_end' => $month->endOfMonth()->toDateString(),
                'currency' => $currency,
                'created_by_user_id' => $lockedActor->getKey(),
            ])->save();

            $this->auditLogger->record(
                event: 'payroll_run.opened',
                auditable: $run,
                newValues: [
                    'reference' => $run->reference,
                    'period_start' => $run->period_start->toDateString(),
                    'currency' => $run->currency,
                ],
                user: $lockedActor,
            );

            return $run;
        }, 3);
    }

    /**
     * Adds or replaces one person's line, with the deductions worked out.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function setLine(User $actor, PayrollRun $run, User $employee, array $attributes): PayrollLine
    {
        $input = $this->validatedLine($attributes);

        return DB::transaction(function () use ($actor, $run, $employee, $input): PayrollLine {
            $lockedActor = FinanceAccess::lockedPayrollOfficer($actor);

            $lockedRun = PayrollRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRun->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'This run has been approved. Staff have their payslips, '
                        .'so a correction is a new adjustment rather than an edit.',
                ]);
            }

            $lockedEmployee = User::query()
                ->whereKey($employee->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $employeeRoles = (array) config('payroll.employee_roles', []);

            if (! in_array($lockedEmployee->role->value, $employeeRoles, true)) {
                throw ValidationException::withMessages([
                    'user_id' => 'A '.mb_strtolower($lockedEmployee->role->label()).' is not on the payroll.',
                ]);
            }

            $computed = $this->computeFor(
                $input['gross_minor'],
                $input['allowances_minor'],
                $lockedRun->currency,
                $input['manual_deductions'],
            );

            $line = PayrollLine::query()
                ->where('payroll_run_id', $lockedRun->getKey())
                ->where('user_id', $lockedEmployee->getKey())
                ->lockForUpdate()
                ->first() ?? new PayrollLine;

            $line->forceFill([
                'payroll_run_id' => $lockedRun->getKey(),
                'user_id' => $lockedEmployee->getKey(),
                'employee_name_snapshot' => $lockedEmployee->name,
                'role_snapshot' => $lockedEmployee->role,
                'gross_minor' => $input['gross_minor'],
                'allowances_minor' => $input['allowances_minor'],
                'deductions_minor' => $computed['deductions_total'],
                'net_minor' => $computed['net'],
                'employer_nssf_minor' => $computed['employer_nssf'],
                'currency' => $lockedRun->currency,
                'notes' => $input['notes'],
            ])->save();

            // Rewritten wholesale rather than merged: a recalculation that left
            // a stale line behind would deduct something twice.
            $line->deductions()->delete();

            foreach ($computed['deductions'] as $deduction) {
                $row = new PayrollDeduction;
                $row->forceFill(array_merge($deduction, [
                    'payroll_line_id' => $line->getKey(),
                    'currency' => $lockedRun->currency,
                ]))->save();
            }

            $this->refreshTotals($lockedRun);

            $this->auditLogger->record(
                event: 'payroll_line.saved',
                auditable: $line,
                newValues: [
                    'payroll_run_id' => $lockedRun->getKey(),
                    'user_id' => $lockedEmployee->getKey(),
                    'gross_minor' => $line->gross_minor,
                    'deductions_minor' => $line->deductions_minor,
                    'net_minor' => $line->net_minor,
                    'currency' => $line->currency,
                ],
                user: $lockedActor,
            );

            return $line->fresh(['deductions', 'employee']);
        }, 3);
    }

    /** Takes somebody off the run entirely. */
    public function removeLine(User $actor, PayrollRun $run, PayrollLine $line): void
    {
        DB::transaction(function () use ($actor, $run, $line): void {
            $lockedActor = FinanceAccess::lockedPayrollOfficer($actor);

            $lockedRun = PayrollRun::query()
                ->whereKey($run->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedRun->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'This run has been approved and can no longer be changed.',
                ]);
            }

            $locked = PayrollLine::query()
                ->whereKey($line->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $locked->payroll_run_id !== (int) $lockedRun->getKey()) {
                throw ValidationException::withMessages([
                    'line' => 'That line belongs to a different run.',
                ]);
            }

            $this->auditLogger->record(
                event: 'payroll_line.removed',
                auditable: $lockedRun,
                oldValues: [
                    'user_id' => $locked->user_id,
                    'net_minor' => $locked->net_minor,
                ],
                user: $lockedActor,
            );

            $locked->delete();

            $this->refreshTotals($lockedRun);
        }, 3);
    }

    /**
     * The deduction stack for one salary, in order.
     *
     * @param  list<array{type: PayrollDeductionType, label: string, amount_minor: int}>  $manual
     * @return array{deductions: list<array<string, mixed>>, deductions_total: int, net: int, employer_nssf: int}
     */
    private function computeFor(int $grossMinor, int $allowancesMinor, string $currency, array $manual): array
    {
        $earnings = $grossMinor + $allowancesMinor;
        $deductions = [];
        $sequence = 0;

        // 1. NSSF, off the whole of what was earned.
        $nssf = TaxSchedule::employeeNssf($earnings, $currency);

        if ($nssf > 0) {
            $deductions[] = [
                'type' => PayrollDeductionType::Nssf,
                'label' => PayrollDeductionType::Nssf->label(),
                'basis_minor' => $earnings,
                'amount_minor' => $nssf,
                'sequence' => $sequence += 10,
            ];
        }

        // 2. PAYE, on what is left after NSSF — not on the full gross.
        $chargeable = max(0, $earnings - $nssf);

        if (TaxSchedule::supportsPaye($currency)) {
            try {
                $paye = TaxSchedule::paye($chargeable, $currency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['currency' => $exception->getMessage()]);
            }

            if ($paye > 0) {
                $deductions[] = [
                    'type' => PayrollDeductionType::Paye,
                    'label' => PayrollDeductionType::Paye->label(),
                    'basis_minor' => $chargeable,
                    'amount_minor' => $paye,
                    'sequence' => $sequence += 10,
                ];
            }
        }

        // 3. Everything somebody typed in: advances, local service tax, other.
        foreach ($manual as $entry) {
            $deductions[] = [
                'type' => $entry['type'],
                'label' => $entry['label'],
                'basis_minor' => $earnings,
                'amount_minor' => $entry['amount_minor'],
                'sequence' => $sequence += 10,
            ];
        }

        $total = array_sum(array_column($deductions, 'amount_minor'));

        // Deductions cannot take somebody below zero. A shortfall is a debt to
        // settle, not a negative payslip.
        if ($total > $earnings) {
            throw ValidationException::withMessages([
                'deductions' => 'Those deductions come to '.Money::format($total, $currency)
                    .', which is more than '.Money::format($earnings, $currency).' earned. '
                    .'Carry the difference rather than issuing a negative payslip.',
            ]);
        }

        return [
            'deductions' => $deductions,
            'deductions_total' => $total,
            'net' => $earnings - $total,
            // Never deducted from the employee: a cost the business carries.
            'employer_nssf' => TaxSchedule::employerNssf($earnings, $currency),
        ];
    }

    /** Recomputes the run's roll-ups from its lines. */
    private function refreshTotals(PayrollRun $run): void
    {
        $lines = PayrollLine::query()->where('payroll_run_id', $run->getKey())->get();

        $gross = (int) $lines->sum(fn (PayrollLine $line): int => $line->totalEarningsMinor());
        $deductions = (int) $lines->sum('deductions_minor');
        $net = (int) $lines->sum('net_minor');
        $employerNssf = (int) $lines->sum('employer_nssf_minor');

        $run->forceFill([
            'gross_total_minor' => $gross,
            'deductions_total_minor' => $deductions,
            'net_total_minor' => $net,
            // What the month actually costs: the full gross plus the employer's
            // own contribution, not the net that lands in bank accounts.
            'employer_cost_minor' => $gross + $employerNssf,
            'employee_count' => $lines->count(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{gross_minor: int, allowances_minor: int, notes: string|null, manual_deductions: list<array{type: PayrollDeductionType, label: string, amount_minor: int}>}
     */
    private function validatedLine(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'gross' => ['required', 'string', 'max:24'],
            'allowances' => ['nullable', 'string', 'max:24'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'deductions' => ['nullable', 'array', 'max:20'],
            'deductions.*.type' => ['required', Rule::enum(PayrollDeductionType::class)],
            'deductions.*.label' => ['nullable', 'string', 'max:120'],
            'deductions.*.amount' => ['required', 'string', 'max:24'],
        ])->validate();

        $currency = strtoupper((string) $validated['currency']);

        $gross = $this->money($validated['gross'], $currency, 'gross');

        if ($gross < 1) {
            throw ValidationException::withMessages(['gross' => 'A salary of nothing is not a salary.']);
        }

        $allowances = filled($validated['allowances'] ?? null)
            ? $this->money($validated['allowances'], $currency, 'allowances')
            : 0;

        $manual = [];

        foreach ($validated['deductions'] ?? [] as $index => $entry) {
            $type = PayrollDeductionType::from((string) $entry['type']);

            // The statutory computed ones are arithmetic, not a text box: letting
            // somebody type a PAYE figure would put the tax rules in a form.
            if ($type->isComputed()) {
                throw ValidationException::withMessages([
                    "deductions.{$index}.type" => $type->label().' is worked out automatically and cannot be typed in.',
                ]);
            }

            $amount = $this->money($entry['amount'], $currency, "deductions.{$index}.amount");

            if ($amount < 1) {
                continue;
            }

            $manual[] = [
                'type' => $type,
                'label' => filled($entry['label'] ?? null) ? trim((string) $entry['label']) : $type->label(),
                'amount_minor' => $amount,
            ];
        }

        return [
            'gross_minor' => $gross,
            'allowances_minor' => $allowances,
            'notes' => filled($validated['notes'] ?? null) ? trim((string) $validated['notes']) : null,
            'manual_deductions' => $manual,
        ];
    }

    private function money(mixed $value, string $currency, string $field): int
    {
        try {
            return Money::parse(trim((string) $value), $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([$field => $exception->getMessage()]);
        }
    }
}
