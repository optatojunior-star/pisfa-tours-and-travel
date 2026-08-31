<?php

namespace App\Http\Controllers\Finance;

use App\Enums\PayrollRunStatus;
use App\Http\Controllers\Controller;
use App\Models\PayrollLine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * An employee's own payslips.
 *
 * Only lines from an approved or paid run: a draft is working that may still
 * change, and telling somebody a figure that later moves is worse than telling
 * them nothing yet.
 */
class PayslipController extends Controller
{
    public function index(Request $request): View
    {
        $lines = PayrollLine::query()
            ->forEmployee($request->user())
            ->whereHas('run', fn (Builder $run): Builder => $run->whereIn(
                'status',
                [PayrollRunStatus::Approved->value, PayrollRunStatus::Paid->value],
            ))
            ->with(['run', 'deductions'])
            ->get()
            ->sortByDesc(fn (PayrollLine $line) => $line->run?->period_start)
            ->values();

        return view('portal.pay.index', ['lines' => $lines]);
    }

    public function show(PayrollLine $payslip): View
    {
        $this->authorize('view', $payslip);

        return view('portal.pay.show', [
            'line' => $payslip->load(['run', 'deductions']),
        ]);
    }
}
