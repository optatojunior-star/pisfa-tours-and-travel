@php
    use App\Enums\LeasePayoutStatus;
    use App\Enums\LeaseStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Your lease</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">
                    {{ $lease->vehicle?->make }} {{ $lease->vehicle?->model }}
                    @unless ($lease->vehicle)
                        {{ $lease->application?->vehicleLabel() ?? 'Vehicle lease' }}
                    @endunless
                </h1>
                <p class="mt-1 text-sm text-slate-600">{{ $lease->reference }}</p>
            </div>
            <a href="{{ route('portal.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                Back to My PISFA
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <span @class([
                    'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                    'bg-slate-100 text-slate-800' => $lease->status->tone() === 'slate',
                    'bg-emerald-100 text-emerald-900' => $lease->status->tone() === 'emerald',
                    'bg-amber-100 text-amber-900' => $lease->status->tone() === 'amber',
                    'bg-rose-100 text-rose-900' => $lease->status->tone() === 'rose',
                ])>{{ $lease->status->label() }}</span>

                @if ($lease->status === LeaseStatus::Suspended && $lease->suspension_reason)
                    <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                        <span class="font-bold">Your vehicle is off hire:</span> {{ $lease->suspension_reason }}
                        The agreement and its terms are unchanged.
                    </p>
                @endif

                @if ($lease->status === LeaseStatus::Ended && $lease->termination_reason)
                    <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">
                        <span class="font-bold">This lease has ended:</span> {{ $lease->termination_reason }}
                    </p>
                @endif

                <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">You are paid</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $lease->termsSummary() }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Arrangement</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $lease->payout_model->label() }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Started</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $lease->starts_on->format('j M Y') }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Ends</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            {{ $lease->ends_on?->format('j M Y') ?? 'Open-ended' }}
                        </dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Notice period</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $lease->notice_period_days }} days</dd>
                    </div>
                    @if ($lease->vehicle)
                        <div class="flex justify-between border-b border-slate-200 py-2">
                            <dt class="text-sm font-semibold text-slate-600">Registration</dt>
                            <dd class="font-mono text-sm font-bold text-slate-900">{{ $lease->vehicle->registration_plate }}</dd>
                        </div>
                    @endif
                </dl>

                @if ($lease->terms)
                    <div class="mt-5 rounded-xl bg-slate-50 p-4">
                        <h2 class="text-sm font-bold text-slate-900">Terms</h2>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $lease->terms }}</p>
                    </div>
                @endif
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Your statements</h2>
                <p class="mt-1 text-sm text-slate-600">
                    What the vehicle earned each month, and what was due to you.
                </p>

                @if ($payouts->isEmpty())
                    <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                        No statements yet. They are drawn up in the first few days of the following month.
                    </p>
                @else
                    <div class="mt-5 overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Monthly statements</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Month</th>
                                    <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Vehicle earned</th>
                                    <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Your share</th>
                                    <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Deducted</th>
                                    <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Paid to you</th>
                                    <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($payouts as $payout)
                                    <tr>
                                        <td class="px-4 py-4">
                                            <p class="font-bold text-slate-900">{{ $payout->monthLabel() }}</p>
                                            <p class="text-xs text-slate-500">{{ $payout->reference }}</p>
                                        </td>
                                        <td class="px-4 py-4 text-slate-700">
                                            {{ $payout->formattedGrossRevenue() }}
                                            <span class="block text-xs text-slate-500">
                                                {{ $payout->hire_count }} {{ Str::plural('hire', $payout->hire_count) }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-4 text-slate-700">{{ $payout->formattedEarned() }}</td>
                                        <td class="px-4 py-4 text-slate-700">
                                            {{ $payout->deductions_minor > 0 ? $payout->formattedDeductions() : '—' }}
                                            @if ($payout->deductions_note)
                                                <span class="block text-xs text-slate-500">{{ $payout->deductions_note }}</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-4 font-bold text-slate-900">{{ $payout->formattedNet() }}</td>
                                        <td class="px-4 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-sky-100 text-sky-900' => $payout->status === LeasePayoutStatus::Approved,
                                                'bg-emerald-100 text-emerald-900' => $payout->status === LeasePayoutStatus::Paid,
                                                'bg-rose-100 text-rose-900' => $payout->status === LeasePayoutStatus::Cancelled,
                                            ])>{{ $payout->status->label() }}</span>
                                            @if ($payout->paid_at)
                                                <span class="mt-1 block text-xs text-slate-500">
                                                    {{ $payout->paid_at->timezone($timezone)->format('j M Y') }}
                                                </span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
