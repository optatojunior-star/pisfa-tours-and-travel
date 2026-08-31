<?php

namespace App\Actions\Finance;

use App\Enums\DocumentCategory;
use App\Enums\PayrollRunStatus;
use App\Models\PayrollRun;
use App\Models\User;
use App\Notifications\Finance\PayslipAvailableNotification;
use App\Services\AuditLogger;
use App\Services\Documents\PdfRenderer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Approves, pays, and closes a payroll run.
 *
 * Approval is the point where the figures stop moving and payslips are filed —
 * private documents, so they are stored with the run's own visibility rather
 * than anywhere a link could leak them.
 */
class TransitionPayrollRun
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * Signs the run off and files every payslip.
     *
     * The PDFs are written after the transaction commits, and a run with no
     * lines is refused: approving an empty payroll would tell nobody anything
     * while looking like the month was done.
     */
    public function approve(User $actor, PayrollRun $run): PayrollRun
    {
        $approved = false;

        $result = DB::transaction(function () use ($actor, $run, &$approved): PayrollRun {
            $lockedActor = FinanceAccess::lockedPayrollOfficer($actor);

            $locked = $this->locked($run);

            if ($locked->status === PayrollRunStatus::Approved) {
                return $locked;
            }

            $this->assertTransition($locked, PayrollRunStatus::Approved);

            if ($locked->employee_count < 1) {
                throw ValidationException::withMessages([
                    'status' => 'There is nobody on this run. Add at least one line before approving it.',
                ]);
            }

            $this->apply($locked, PayrollRunStatus::Approved, [
                'approved_at' => now(),
                'approved_by_user_id' => $lockedActor->getKey(),
            ], 'payroll_run.approved', $lockedActor);

            $approved = true;

            return $locked;
        }, 3);

        // Guarded by the flag rather than by the status: a second call finds the
        // run already approved and returns early, and must not file a second set
        // of payslips or send the notifications again.
        if ($approved) {
            $this->filePayslips($actor, $result);
        }

        return $result->fresh('lines');
    }

    /** Sends it back for correction, before any money has moved. */
    public function reopen(User $actor, PayrollRun $run, string $reason): PayrollRun
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $run, $reason): PayrollRun {
            $lockedActor = FinanceAccess::lockedPayrollOfficer($actor);

            $locked = $this->locked($run);

            if ($locked->status === PayrollRunStatus::Draft) {
                return $locked;
            }

            $this->assertTransition($locked, PayrollRunStatus::Draft);

            $this->apply($locked, PayrollRunStatus::Draft, [
                'approved_at' => null,
                'approved_by_user_id' => null,
                'closure_reason' => $reason,
            ], 'payroll_run.reopened', $lockedActor);

            return $locked->fresh('lines');
        }, 3);
    }

    /**
     * Records that the salaries actually went out.
     *
     * The reference is required, for the same reason a lease payout needs one:
     * a run marked paid with nothing to trace it to is indistinguishable from
     * one somebody forgot to send.
     */
    public function markPaid(User $actor, PayrollRun $run, string $paymentReference): PayrollRun
    {
        $paymentReference = trim($paymentReference);

        Validator::make(
            ['payment_reference' => $paymentReference],
            ['payment_reference' => ['required', 'string', 'min:3', 'max:120']],
            ['payment_reference.required' => 'Record the transfer reference so the payment can be traced.'],
        )->validate();

        return DB::transaction(function () use ($actor, $run, $paymentReference): PayrollRun {
            $lockedActor = FinanceAccess::lockedPayrollOfficer($actor);

            $locked = $this->locked($run);

            if ($locked->status === PayrollRunStatus::Paid) {
                return $locked;
            }

            $this->assertTransition($locked, PayrollRunStatus::Paid);

            $this->apply($locked, PayrollRunStatus::Paid, [
                'paid_at' => now(),
                'payment_reference' => $paymentReference,
            ], 'payroll_run.paid', $lockedActor);

            return $locked->fresh('lines');
        }, 3);
    }

    public function cancel(User $actor, PayrollRun $run, string $reason): PayrollRun
    {
        $reason = $this->validatedReason($reason);

        return DB::transaction(function () use ($actor, $run, $reason): PayrollRun {
            $lockedActor = FinanceAccess::lockedPayrollOfficer($actor);

            $locked = $this->locked($run);

            if ($locked->status === PayrollRunStatus::Cancelled) {
                return $locked;
            }

            $this->assertTransition($locked, PayrollRunStatus::Cancelled);

            $this->apply($locked, PayrollRunStatus::Cancelled, [
                'closure_reason' => $reason,
            ], 'payroll_run.cancelled', $lockedActor);

            return $locked->fresh('lines');
        }, 3);
    }

    /** Renders and files one private payslip per line, then tells each person. */
    private function filePayslips(User $actor, PayrollRun $run): void
    {
        $run->loadMissing(['lines.deductions', 'lines.employee']);

        foreach ($run->lines as $line) {
            $this->pdf->store(
                actor: $actor,
                owner: $line,
                category: DocumentCategory::Payslip,
                view: 'pdf.payslip',
                data: ['line' => $line, 'run' => $run],
                metadata: [
                    'period' => $run->monthLabel(),
                    'net_minor' => $line->net_minor,
                    'currency' => $line->currency,
                ],
            );

            $line->employee?->notify(new PayslipAvailableNotification(
                period: $run->monthLabel(),
                net: $line->formattedNet(),
                runReference: $run->reference,
            ));
        }
    }

    /** @param array<string, mixed> $extra */
    private function apply(
        PayrollRun $run,
        PayrollRunStatus $next,
        array $extra,
        string $event,
        User $actor,
    ): void {
        $previous = $run->status;

        $run->forceFill(array_merge($extra, ['status' => $next]))->save();

        $this->auditLogger->record(
            event: $event,
            auditable: $run,
            oldValues: ['status' => $previous->value],
            newValues: [
                'status' => $next->value,
                // Totals, never individual salaries: the audit trail is read by
                // more people than the payroll is.
                'net_total_minor' => $run->net_total_minor,
                'employee_count' => $run->employee_count,
                'currency' => $run->currency,
            ],
            user: $actor,
        );
    }

    private function locked(PayrollRun $run): PayrollRun
    {
        return PayrollRun::query()
            ->whereKey($run->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertTransition(PayrollRun $run, PayrollRunStatus $next): void
    {
        if (! $run->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$run->status->label()} run cannot become {$next->label()}.",
            ]);
        }
    }

    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $reason;
    }
}
