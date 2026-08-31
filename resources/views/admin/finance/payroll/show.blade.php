@php
    use App\Enums\PayrollDeductionType;
    use App\Enums\PayrollRunStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canEdit = auth()->user()?->can('update', $run) ?? false;
    $canSettle = auth()->user()?->can('settle', $run) ?? false;
    $manualTypes = array_values(array_filter(
        PayrollDeductionType::cases(),
        static fn (PayrollDeductionType $type): bool => ! $type->isComputed(),
    ));
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Payroll</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $run->monthLabel() }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $run->reference }} · {{ $run->currency }}</p>
            </div>
            <a href="{{ route('admin.payroll.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All runs
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Run totals">
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">People</p>
                    <p class="mt-1 text-2xl font-black text-slate-900">{{ $run->employee_count }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Gross</p>
                    <p class="mt-1 text-xl font-black text-slate-900">{{ $run->formattedGross() }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Net to pay</p>
                    <p class="mt-1 text-xl font-black text-emerald-800">{{ $run->formattedNet() }}</p>
                </div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Cost to PISFA</p>
                    <p class="mt-1 text-xl font-black text-slate-900">{{ $run->formattedEmployerCost() }}</p>
                    <p class="mt-1 text-xs text-slate-500">Gross plus employer NSSF</p>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">On this run</h2>

                        @if ($run->lines->isEmpty())
                            <p class="mt-3 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                                Nobody yet. Add somebody below — an empty run cannot be approved.
                            </p>
                        @else
                            <div class="mt-5 space-y-4">
                                @foreach ($run->lines as $line)
                                    <div class="rounded-2xl border border-slate-200 p-5">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="font-bold text-slate-900">{{ $line->employee_name_snapshot }}</p>
                                                <p class="text-xs text-slate-500">{{ $line->role_snapshot->label() }}</p>
                                            </div>
                                            <div class="text-right">
                                                <p class="text-lg font-black text-emerald-800">{{ $line->formattedNet() }}</p>
                                                <p class="text-xs text-slate-500">net</p>
                                            </div>
                                        </div>

                                        <dl class="mt-4 grid gap-x-6 gap-y-1 sm:grid-cols-2">
                                            <div class="flex justify-between border-b border-slate-100 py-1.5">
                                                <dt class="text-sm text-slate-600">Earnings</dt>
                                                <dd class="text-sm font-bold text-slate-900">{{ $line->formattedEarnings() }}</dd>
                                            </div>
                                            <div class="flex justify-between border-b border-slate-100 py-1.5">
                                                <dt class="text-sm text-slate-600">Employer NSSF</dt>
                                                <dd class="text-sm font-bold text-slate-900">{{ $line->formattedEmployerNssf() }}</dd>
                                            </div>
                                        </dl>

                                        @if ($line->deductions->isNotEmpty())
                                            <ul class="mt-3 space-y-1">
                                                @foreach ($line->deductions as $deduction)
                                                    <li class="flex justify-between text-sm">
                                                        <span class="text-slate-600">
                                                            {{ $deduction->label }}
                                                            <span class="text-xs text-slate-400">on {{ $deduction->formattedBasis() }}</span>
                                                        </span>
                                                        <span class="font-semibold text-slate-900">{{ $deduction->formattedAmount() }}</span>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        @if ($canEdit)
                                            <form method="POST" action="{{ route('admin.payroll.lines.remove', [$run, $line]) }}" class="mt-4">
                                                @csrf
                                                @method('DELETE')
                                                <button type="submit" class="text-xs font-bold text-rose-700 hover:underline">
                                                    Take off the run
                                                </button>
                                            </form>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($canEdit)
                            <details class="mt-6">
                                <summary class="cursor-pointer text-sm font-bold text-emerald-800">Add somebody</summary>
                                <form method="POST" action="{{ route('admin.payroll.lines.save', $run) }}" class="mt-4 grid gap-4 sm:grid-cols-4">
                                    @csrf
                                    <div class="sm:col-span-2">
                                        <label for="user_id" class="block text-sm font-semibold">Who</label>
                                        <select id="user_id" name="user_id" required class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                            <option value="">Choose somebody</option>
                                            @foreach ($employees as $person)
                                                <option value="{{ $person->getKey() }}">{{ $person->name }} — {{ $person->role->label() }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label for="gross" class="block text-sm font-semibold">Basic salary</label>
                                        <input id="gross" name="gross" type="text" inputmode="numeric" required maxlength="24"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="allowances" class="block text-sm font-semibold">Allowances</label>
                                        <input id="allowances" name="allowances" type="text" inputmode="numeric" maxlength="24"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>

                                    <div class="sm:col-span-4">
                                        <p class="text-sm font-semibold">Other deductions</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            NSSF and PAYE are worked out automatically, in that order — PAYE is charged
                                            on what is left after NSSF. Only advances and other arrangements are
                                            entered here.
                                        </p>
                                        <div class="mt-2 grid gap-3 sm:grid-cols-3">
                                            <div>
                                                <label for="deduction-type" class="block text-xs font-semibold">Type</label>
                                                <select id="deduction-type" name="deductions[0][type]" class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    @foreach ($manualTypes as $type)
                                                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label for="deduction-label" class="block text-xs font-semibold">Description</label>
                                                <input id="deduction-label" name="deductions[0][label]" type="text" maxlength="120"
                                                       class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            </div>
                                            <div>
                                                <label for="deduction-amount" class="block text-xs font-semibold">Amount</label>
                                                <input id="deduction-amount" name="deductions[0][amount]" type="text" inputmode="numeric" maxlength="24" value="0"
                                                       class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                            </div>
                                        </div>
                                    </div>

                                    <div class="sm:col-span-4">
                                        <label for="notes" class="block text-sm font-semibold">Notes</label>
                                        <input id="notes" name="notes" type="text" maxlength="1000"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>

                                    <div class="sm:col-span-4">
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                            Save line
                                        </button>
                                    </div>
                                </form>
                            </details>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                            'bg-slate-100 text-slate-800' => $run->status->tone() === 'slate',
                            'bg-sky-100 text-sky-900' => $run->status->tone() === 'sky',
                            'bg-emerald-100 text-emerald-900' => $run->status->tone() === 'emerald',
                            'bg-rose-100 text-rose-900' => $run->status->tone() === 'rose',
                        ])>{{ $run->status->label() }}</span>

                        @if ($run->approvedBy)
                            <p class="mt-3 text-sm text-slate-600">
                                Approved by {{ $run->approvedBy->name }}
                                @if ($run->approved_at)
                                    on {{ $run->approved_at->timezone($timezone)->format('j M Y') }}
                                @endif
                            </p>
                        @endif

                        @if ($run->paid_at)
                            <p class="mt-2 text-sm text-emerald-800">
                                Paid {{ $run->paid_at->timezone($timezone)->format('j M Y') }} ·
                                reference {{ $run->payment_reference }}
                            </p>
                        @endif

                        @if ($run->closure_reason)
                            <p class="mt-3 rounded-xl bg-slate-50 p-3 text-sm text-slate-700">{{ $run->closure_reason }}</p>
                        @endif
                    </div>

                    @if ($canSettle && in_array(PayrollRunStatus::Approved, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.payroll.approve', $run) }}" class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Approve</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Files a payslip for everybody on the run and tells them it is ready. The figures stop
                                moving at this point.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Approve the run
                            </button>
                        </form>
                    @endif

                    @if ($canSettle && in_array(PayrollRunStatus::Paid, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.payroll.paid', $run) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Record payment</h2>
                            <div>
                                <label for="payment_reference" class="block text-sm font-semibold">Transfer reference</label>
                                <input id="payment_reference" name="payment_reference" type="text" required minlength="3" maxlength="120"
                                       placeholder="BANK-99887766"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Mark paid
                            </button>
                        </form>
                    @endif

                    @if ($canSettle && in_array(PayrollRunStatus::Draft, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.payroll.reopen', $run) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Reopen</h2>
                            <div>
                                <label for="reopen-reason" class="block text-sm font-semibold">Why</label>
                                <input id="reopen-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Send back for correction
                            </button>
                        </form>
                    @endif

                    @if ($canSettle && in_array(PayrollRunStatus::Cancelled, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.payroll.cancel', $run) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Cancel</h2>
                            <div>
                                <label for="cancel-reason" class="block text-sm font-semibold">Why</label>
                                <input id="cancel-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Cancel the run
                            </button>
                        </form>
                    @endif

                    @if ($nextStatuses === [])
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Closed</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                This run has been paid and is kept as a record. A correction is a new adjustment.
                            </p>
                        </div>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
