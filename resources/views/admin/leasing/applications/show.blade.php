@php
    use App\Enums\LeaseApplicationStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canManage = auth()->user()?->can('manage', $application) ?? false;
    $canApprove = auth()->user()?->can('approve', $application) ?? false;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Leasing offer</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $application->vehicleLabel() }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $application->reference }}</p>
            </div>
            <a href="{{ route('admin.leasing.applications.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All offers
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

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center gap-3">
                            <span @class([
                                'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                                'bg-amber-100 text-amber-900' => $application->status->tone() === 'amber',
                                'bg-sky-100 text-sky-900' => $application->status->tone() === 'sky',
                                'bg-emerald-100 text-emerald-900' => $application->status->tone() === 'emerald',
                                'bg-rose-100 text-rose-900' => $application->status->tone() === 'rose',
                            ])>{{ $application->status->label() }}</span>
                            @if ($application->isGuest())
                                <span class="inline-flex rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-800">Guest — no account</span>
                            @endif
                        </div>

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Owner</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $application->contact_name }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Email</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    <a href="mailto:{{ $application->contact_email }}" class="text-emerald-800 hover:underline">{{ $application->contact_email }}</a>
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Phone</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    <a href="tel:{{ $application->contact_phone }}" class="text-emerald-800 hover:underline">{{ $application->contact_phone }}</a>
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Registration</dt>
                                <dd class="font-mono text-sm font-bold text-slate-900">{{ $application->registration_plate }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Mileage</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $application->mileage_km ? number_format($application->mileage_km).' km' : 'Not stated' }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Seats / fuel / gearbox</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $application->seating_capacity ?? '—' }} ·
                                    {{ $application->fuel_type ?? '—' }} ·
                                    {{ $application->transmission ?? '—' }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Wants</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $application->preferred_payout_model?->label() ?? 'No preference' }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Hoping for</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $application->formattedExpectation() ?? 'Not stated' }}</dd>
                            </div>
                        </dl>

                        @if ($application->notes)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h2 class="text-sm font-bold text-slate-900">What the owner said</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $application->notes }}</p>
                            </div>
                        @endif

                        @if ($application->inspection_at)
                            <p class="mt-4 rounded-xl bg-sky-50 p-3 text-sm text-sky-900">
                                <span class="font-bold">Inspection:</span>
                                {{ $application->inspection_at->timezone($timezone)->format('j M Y, H:i') }}
                                @if ($application->inspection_location)
                                    at {{ $application->inspection_location }}
                                @endif
                            </p>
                        @endif

                        @if ($application->inspection_findings)
                            <div class="mt-4 rounded-xl bg-emerald-50 p-4">
                                <h2 class="text-sm font-bold text-emerald-900">Inspection findings</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-emerald-900">{{ $application->inspection_findings }}</p>
                            </div>
                        @endif

                        @if ($application->closure_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Closed:</span> {{ $application->closure_reason }}
                            </p>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    @if ($application->lease)
                        <div class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Agreement</h2>
                            <p class="mt-2 text-sm text-slate-600">This offer became {{ $application->lease->reference }}.</p>
                            <a href="{{ route('admin.leasing.leases.show', $application->lease) }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Open the lease
                            </a>
                        </div>
                    @elseif ($application->status === LeaseApplicationStatus::Approved && $canApprove)
                        <div class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Draw up the lease</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Approved and ready for terms. The owner needs a customer account before the
                                agreement can be created.
                            </p>
                            <a href="{{ route('admin.leasing.leases.create', ['application' => $application->reference]) }}"
                               class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                New agreement
                            </a>
                        </div>
                    @endif

                    @if ($canManage && in_array(LeaseApplicationStatus::UnderReview, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.applications.review', $application) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Start reviewing</h2>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Move to review
                            </button>
                        </form>
                    @endif

                    @if ($canManage && in_array(LeaseApplicationStatus::InspectionArranged, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.applications.inspection', $application) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Arrange an inspection</h2>
                            <p class="text-sm text-slate-600">The owner is told the time and place.</p>
                            <div>
                                <label for="inspection_at" class="block text-sm font-semibold">When</label>
                                <input id="inspection_at" name="inspection_at" type="datetime-local" required
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <div>
                                <label for="inspection_location" class="block text-sm font-semibold">Where</label>
                                <input id="inspection_location" name="inspection_location" type="text" required minlength="3" maxlength="255"
                                       placeholder="PISFA yard, Ntinda"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Arrange it
                            </button>
                        </form>
                    @endif

                    @if ($canManage && in_array(LeaseApplicationStatus::Inspected, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.applications.findings', $application) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Record the inspection</h2>
                            <p class="text-sm text-slate-600">
                                This is the basis for the terms offered, so write down what was actually found.
                            </p>
                            <div>
                                <label for="findings" class="block text-sm font-semibold">Findings</label>
                                <textarea id="findings" name="findings" rows="4" required minlength="10" maxlength="5000"
                                          class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600"></textarea>
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Record findings
                            </button>
                        </form>
                    @endif

                    @if (in_array(LeaseApplicationStatus::Approved, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.applications.approve', $application) }}" class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Approve</h2>
                            @if ($canApprove)
                                <p class="mt-2 text-sm text-slate-600">
                                    Commits PISFA to taking this vehicle on, once terms are agreed.
                                </p>
                                <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                    Approve the offer
                                </button>
                            @else
                                <p class="mt-2 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                                    A manager has to approve an offer.
                                </p>
                            @endif
                        </form>
                    @endif

                    @if ($canManage && in_array(LeaseApplicationStatus::Declined, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.applications.decline', $application) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Decline</h2>
                            <p class="text-sm text-slate-600">The owner is told the reason, so make it useful.</p>
                            <div>
                                <label for="decline-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="decline-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Decline
                            </button>
                        </form>
                    @endif

                    @if ($canManage && in_array(LeaseApplicationStatus::Withdrawn, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.leasing.applications.withdraw', $application) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Owner withdrew</h2>
                            <div>
                                <label for="withdraw-reason" class="block text-sm font-semibold">What they said</label>
                                <input id="withdraw-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Record withdrawal
                            </button>
                        </form>
                    @endif

                    @if ($canManage)
                        <form method="POST" action="{{ route('admin.leasing.applications.assign', $application) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Who is on it</h2>
                            <div>
                                <label for="assigned_to_user_id" class="block text-sm font-semibold">Assigned to</label>
                                <select id="assigned_to_user_id" name="assigned_to_user_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    <option value="">Nobody</option>
                                    @foreach ($assignees as $person)
                                        <option value="{{ $person->getKey() }}" @selected($application->assigned_to_user_id === $person->getKey())>{{ $person->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Save
                            </button>
                        </form>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
