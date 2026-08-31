<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Payslip</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $line->run?->monthLabel() }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $line->run?->reference }}</p>
            </div>
            <a href="{{ route('portal.payslips.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All payslips
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Earnings</h2>
                <dl class="mt-4 space-y-2">
                    <div class="flex justify-between border-b border-slate-100 py-2">
                        <dt class="text-sm text-slate-600">Basic salary</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $line->formattedGross() }}</dd>
                    </div>
                    @if ($line->allowances_minor > 0)
                        <div class="flex justify-between border-b border-slate-100 py-2">
                            <dt class="text-sm text-slate-600">Allowances</dt>
                            <dd class="text-sm font-bold text-slate-900">{{ $line->formattedAllowances() }}</dd>
                        </div>
                    @endif
                    <div class="flex justify-between border-b-2 border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-700">Total earnings</dt>
                        <dd class="text-sm font-black text-slate-900">{{ $line->formattedEarnings() }}</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Deductions</h2>

                @if ($line->deductions->isEmpty())
                    <p class="mt-3 text-sm text-slate-600">Nothing was deducted this period.</p>
                @else
                    <dl class="mt-4 space-y-2">
                        @foreach ($line->deductions as $deduction)
                            <div class="flex justify-between border-b border-slate-100 py-2">
                                <dt class="text-sm text-slate-600">
                                    {{ $deduction->label }}
                                    <span class="block text-xs text-slate-400">
                                        charged on {{ $deduction->formattedBasis() }}
                                    </span>
                                </dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $deduction->formattedAmount() }}</dd>
                            </div>
                        @endforeach
                        <div class="flex justify-between border-b-2 border-slate-200 py-2">
                            <dt class="text-sm font-semibold text-slate-700">Total deductions</dt>
                            <dd class="text-sm font-black text-slate-900">{{ $line->formattedDeductions() }}</dd>
                        </div>
                    </dl>
                @endif
            </div>

            <div class="rounded-3xl border border-emerald-200 bg-emerald-50 p-6">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-black text-emerald-950">Net pay</h2>
                    <p class="text-2xl font-black text-emerald-800">{{ $line->formattedNet() }}</p>
                </div>
                @if ($line->run?->paid_at)
                    <p class="mt-2 text-sm text-emerald-900">
                        Paid {{ $line->run->paid_at->timezone(config('pisfa.business_timezone', 'Africa/Kampala'))->format('j M Y') }}.
                    </p>
                @endif
            </div>

            @if ($line->employer_nssf_minor > 0)
                <p class="rounded-2xl bg-slate-50 p-4 text-sm text-slate-600">
                    PISFA also contributes {{ $line->formattedEmployerNssf() }} to NSSF on your behalf. That is paid
                    by the company and is <span class="font-bold">not</span> deducted from your salary.
                </p>
            @endif

            @if ($line->notes)
                <div class="rounded-2xl bg-slate-50 p-4">
                    <h2 class="text-sm font-bold text-slate-900">Notes</h2>
                    <p class="mt-1 text-sm text-slate-700">{{ $line->notes }}</p>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
