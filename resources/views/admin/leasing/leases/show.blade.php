@php
    use App\Enums\LeasePayoutStatus;
    use App\Enums\LeaseStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canCommit = auth()->user()?->can('commit', $lease) ?? false;
    $canSuspend = auth()->user()?->can('suspend', $lease) ?? false;
    $canEdit = auth()->user()?->can('update', $lease) ?? false;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Lease agreement</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $lease->owner?->name ?? 'Agreement' }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $lease->reference }} · {{ $lease->termsSummary() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($canEdit)
                    <a href="{{ route('admin.leasing.leases.edit', $lease) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
                        Edit terms
                    </a>
                @endif
                <a href="{{ route('admin.leasing.leases.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                    All agreements
                </a>
            </div>
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

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                            'bg-slate-100 text-slate-800' => $lease->status->tone() === 'slate',
                            'bg-emerald-100 text-emerald-900' => $lease->status->tone() === 'emerald',
                            'bg-amber-100 text-amber-900' => $lease->status->tone() === 'amber',
                            'bg-rose-100 text-rose-900' => $lease->status->tone() === 'rose',
                        ])>{{ $lease->status->label() }}</span>

                        @if ($lease->status === LeaseStatus::Suspended && $lease->suspension_reason)
                            <p class="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                <span class="font-bold">Suspended:</span> {{ $lease->suspension_reason }}
                            </p>
                        @endif

                        @if ($lease->termination_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Ended:</span> {{ $lease->termination_reason }}
                            </p>
                        @endif

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Owner</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $lease->owner?->email ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Arrangement</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $lease->payout_model->label() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Vehicle</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    @if ($lease->vehicle)
                                        <a href="{{ route('admin.fleet.show', $lease->vehicle) }}" class="text-emerald-800 hover:underline">
                                            {{ $lease->vehicle->registration_plate }}
                                        </a>
                                    @else
                                        Not yet in the fleet
                                    @endif
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Runs</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $lease->starts_on->format('j M Y') }} —
                                    {{ $lease->ends_on?->format('j M Y') ?? 'open-ended' }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Notice</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $lease->notice_period_days }} days</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">From offer</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    @if ($lease->application)
                                        <a href="{{ route('admin.leasing.applications.show', $lease->application) }}" class="text-emerald-800 hover:underline">
                                            {{ $lease->application->reference }}
                                        </a>
                                    @else
                                        Created directly
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        @if ($lease->internal_notes)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h2 class="text-sm font-bold text-slate-900">Internal notes</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $lease->internal_notes }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Monthly statements</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            The share is taken from hire income only — a refundable deposit is the customer's money,
                            not revenue. Hires in another currency are counted separately and never converted.
                        </p>

                        <form method="POST" action="{{ route('admin.leasing.payouts.calculate', $lease) }}" class="mt-5 flex flex-wrap items-end gap-3">
                            @csrf
                            <div>
                                <label for="payout-month" class="block text-sm font-semibold">Draw up a month</label>
                                <input id="payout-month" name="month" type="date" required
                                       value="{{ now()->subMonth()->startOfMonth()->toDateString() }}"
                                       class="mt-1 block rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Calculate
                            </button>
                        </form>

                        @if ($payouts->isEmpty())
                            <p class="mt-5 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                                No statements yet.
                            </p>
                        @else
                            <div class="mt-6 space-y-5">
                                @foreach ($payouts as $payout)
                                    <div class="rounded-2xl border border-slate-200 p-5">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="font-bold text-slate-900">{{ $payout->monthLabel() }}</p>
                                                <p class="text-xs text-slate-500">{{ $payout->reference }}</p>
                                            </div>
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-slate-100 text-slate-800' => $payout->status->tone() === 'slate',
                                                'bg-sky-100 text-sky-900' => $payout->status->tone() === 'sky',
                                                'bg-emerald-100 text-emerald-900' => $payout->status->tone() === 'emerald',
                                                'bg-rose-100 text-rose-900' => $payout->status->tone() === 'rose',
                                            ])>{{ $payout->status->label() }}</span>
                                        </div>

                                        <dl class="mt-4 grid gap-x-6 gap-y-2 sm:grid-cols-2">
                                            <div class="flex justify-between border-b border-slate-100 py-1.5">
                                                <dt class="text-sm text-slate-600">Vehicle earned</dt>
                                                <dd class="text-sm font-bold text-slate-900">
                                                    {{ $payout->formattedGrossRevenue() }}
                                                    <span class="font-normal text-slate-500">({{ $payout->hire_count }} {{ Str::plural('hire', $payout->hire_count) }})</span>
                                                </dd>
                                            </div>
                                            <div class="flex justify-between border-b border-slate-100 py-1.5">
                                                <dt class="text-sm text-slate-600">Owner earned</dt>
                                                <dd class="text-sm font-bold text-slate-900">{{ $payout->formattedEarned() }}</dd>
                                            </div>
                                            <div class="flex justify-between border-b border-slate-100 py-1.5">
                                                <dt class="text-sm text-slate-600">Deducted</dt>
                                                <dd class="text-sm font-bold text-slate-900">
                                                    {{ $payout->deductions_minor > 0 ? $payout->formattedDeductions() : '—' }}
                                                </dd>
                                            </div>
                                            <div class="flex justify-between border-b border-slate-100 py-1.5">
                                                <dt class="text-sm text-slate-600">Net payable</dt>
                                                <dd class="text-sm font-black text-emerald-800">{{ $payout->formattedNet() }}</dd>
                                            </div>
                                        </dl>

                                        @if ($payout->excluded_hire_count > 0)
                                            <p class="mt-3 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                                {{ $payout->excluded_hire_count }}
                                                {{ Str::plural('hire', $payout->excluded_hire_count) }}
                                                in another currency {{ $payout->excluded_hire_count === 1 ? 'was' : 'were' }}
                                                left out of this figure. Money is never summed across currencies —
                                                settle {{ $payout->excluded_hire_count === 1 ? 'it' : 'them' }} separately.
                                            </p>
                                        @endif

                                        @if ($payout->deductions_note)
                                            <p class="mt-3 text-sm text-slate-600">
                                                <span class="font-semibold">Deduction:</span> {{ $payout->deductions_note }}
                                            </p>
                                        @endif

                                        @if ($payout->paid_at)
                                            <p class="mt-3 text-sm text-emerald-800">
                                                Paid {{ $payout->paid_at->timezone($timezone)->format('j M Y') }} ·
                                                reference {{ $payout->payment_reference }}
                                            </p>
                                        @endif

                                        @can('draft', $payout)
                                            <details class="mt-4">
                                                <summary class="cursor-pointer text-sm font-bold text-emerald-800">Record a deduction</summary>
                                                <form method="POST" action="{{ route('admin.leasing.payouts.deductions', [$lease, $payout]) }}" class="mt-3 grid gap-3 sm:grid-cols-3">
                                                    @csrf
                                                    <div>
                                                        <label for="ded-{{ $payout->getKey() }}" class="block text-xs font-semibold">Amount ({{ $payout->currency }})</label>
                                                        <input id="ded-{{ $payout->getKey() }}" name="deductions" type="text" inputmode="numeric" required maxlength="24"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div class="sm:col-span-2">
                                                        <label for="dednote-{{ $payout->getKey() }}" class="block text-xs font-semibold">What for (the owner sees this)</label>
                                                        <input id="dednote-{{ $payout->getKey() }}" name="deductions_note" type="text" maxlength="255"
                                                               placeholder="New tyres fitted in July"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div class="sm:col-span-3">
                                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                                            Save deduction
                                                        </button>
                                                    </div>
                                                </form>
                                            </details>
                                        @endcan

                                        @can('settle', $payout)
                                            <div class="mt-4 flex flex-wrap gap-2">
                                                @if ($payout->canTransitionTo(LeasePayoutStatus::Approved))
                                                    <form method="POST" action="{{ route('admin.leasing.payouts.approve', [$lease, $payout]) }}">
                                                        @csrf
                                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                                            Approve
                                                        </button>
                                                    </form>
                                                @endif

                                                @if ($payout->canTransitionTo(LeasePayoutStatus::Paid))
                                                    <form method="POST" action="{{ route('admin.leasing.payouts.paid', [$lease, $payout]) }}" class="flex flex-wrap items-end gap-2">
                                                        @csrf
                                                        <div>
                                                            <label for="ref-{{ $payout->getKey() }}" class="block text-xs font-semibold">Transfer reference</label>
                                                            <input id="ref-{{ $payout->getKey() }}" name="payment_reference" type="text" required minlength="3" maxlength="120"
                                                                   placeholder="MM-12345678"
                                                                   class="mt-1 block rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                        </div>
                                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                                            Mark paid
                                                        </button>
                                                    </form>
                                                @endif

                                                @if ($payout->canTransitionTo(LeasePayoutStatus::Draft))
                                                    <form method="POST" action="{{ route('admin.leasing.payouts.reopen', [$lease, $payout]) }}" class="flex flex-wrap items-end gap-2">
                                                        @csrf
                                                        <div>
                                                            <label for="reopen-{{ $payout->getKey() }}" class="block text-xs font-semibold">Why reopen</label>
                                                            <input id="reopen-{{ $payout->getKey() }}" name="reason" type="text" required minlength="5" maxlength="255"
                                                                   class="mt-1 block rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                        </div>
                                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                                            Reopen
                                                        </button>
                                                    </form>
                                                @endif
                                            </div>
                                        @endcan
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    @if ($canCommit && in_array(LeaseStatus::Active, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.leases.activate', $lease) }}" class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">
                                {{ $lease->status === LeaseStatus::Suspended ? 'Put it back on hire' : 'Activate' }}
                            </h2>
                            <p class="mt-2 text-sm text-slate-600">
                                {{ $lease->status === LeaseStatus::Suspended
                                    ? 'The vehicle becomes bookable again under the same terms.'
                                    : 'Brings the agreement into force and adds the vehicle to the fleet, as a draft so it can be photographed and priced first.' }}
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                {{ $lease->status === LeaseStatus::Suspended ? 'Resume' : 'Activate' }}
                            </button>
                        </form>
                    @endif

                    @if ($canSuspend && in_array(LeaseStatus::Suspended, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.leases.suspend', $lease) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Suspend</h2>
                            <p class="text-sm text-slate-600">
                                Takes the vehicle off hire without ending the agreement. The terms stand.
                            </p>
                            <div>
                                <label for="suspend-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="suspend-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="Insurance certificate has expired"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-amber-300 px-5 text-sm font-bold text-amber-800 hover:bg-amber-50">
                                Suspend
                            </button>
                        </form>
                    @endif

                    @if ($canCommit && in_array(LeaseStatus::Ended, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.leases.end', $lease) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">End the agreement</h2>
                            <p class="text-sm text-slate-600">
                                Retires the vehicle from the fleet. Refused while hires are still to run — a customer
                                is holding a booking for it.
                            </p>
                            <div>
                                <label for="end-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="end-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                End the lease
                            </button>
                        </form>
                    @endif

                    @if ($nextStatuses === [])
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Closed</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                This agreement has ended and is kept as a record. A vehicle coming back is a new
                                agreement.
                            </p>
                        </div>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
