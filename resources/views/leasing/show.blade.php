@extends('layouts.public')

@php
    use App\Enums\LeaseApplicationStatus;

    $title = 'Your leasing offer';
    $description = 'Progress on the vehicle you offered to PISFA Tours and Travels.';
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');

    $steps = [
        LeaseApplicationStatus::Submitted,
        LeaseApplicationStatus::UnderReview,
        LeaseApplicationStatus::InspectionArranged,
        LeaseApplicationStatus::Inspected,
        LeaseApplicationStatus::Approved,
    ];
    $currentIndex = array_search($application->status, $steps, true);
@endphp

@section('content')
    <section class="bg-emerald-950 px-4 py-14 text-white sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl">
            <p class="text-sm font-black uppercase tracking-[0.22em] text-amber-300">Vehicle leasing</p>
            <h1 class="mt-4 text-3xl font-black sm:text-4xl">{{ $application->vehicleLabel() }}</h1>
            <p class="mt-3 text-emerald-100">Reference {{ $application->reference }}</p>
        </div>
    </section>

    <section class="px-4 py-12 sm:px-6 lg:px-8">
        <div class="mx-auto max-w-4xl space-y-6">
            @if (session('success'))
                <div class="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-sm font-semibold text-emerald-900" role="status">
                    {{ session('success') }}
                </div>
            @endif

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center gap-3">
                    <span @class([
                        'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                        'bg-amber-100 text-amber-900' => $application->status->tone() === 'amber',
                        'bg-sky-100 text-sky-900' => $application->status->tone() === 'sky',
                        'bg-emerald-100 text-emerald-900' => $application->status->tone() === 'emerald',
                        'bg-rose-100 text-rose-900' => $application->status->tone() === 'rose',
                    ])>{{ $application->status->label() }}</span>
                </div>

                @if ($currentIndex !== false)
                    <ol class="mt-6 space-y-3" aria-label="Progress">
                        @foreach ($steps as $index => $step)
                            <li class="flex items-center gap-3">
                                <span @class([
                                    'grid size-7 shrink-0 place-items-center rounded-full text-xs font-black',
                                    'bg-emerald-700 text-white' => $index <= $currentIndex,
                                    'bg-slate-200 text-slate-500' => $index > $currentIndex,
                                ])>{{ $index + 1 }}</span>
                                <span @class([
                                    'text-sm',
                                    'font-bold text-slate-900' => $index <= $currentIndex,
                                    'text-slate-500' => $index > $currentIndex,
                                ])>{{ $step->label() }}</span>
                            </li>
                        @endforeach
                    </ol>
                @endif

                @if ($application->inspection_at)
                    <p class="mt-6 rounded-xl bg-sky-50 p-4 text-sm text-sky-900">
                        <span class="font-bold">Inspection:</span>
                        {{ $application->inspection_at->timezone($timezone)->format('j F Y \a\t H:i') }}
                        @if ($application->inspection_location)
                            at {{ $application->inspection_location }}
                        @endif
                    </p>
                @endif

                @if ($application->closure_reason)
                    <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">
                        <span class="font-bold">Reason:</span> {{ $application->closure_reason }}
                    </p>
                @endif

                @if ($lease)
                    <p class="mt-4 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">
                        A lease agreement has been drawn up for this vehicle.
                        @auth
                            <a href="{{ route('portal.leases.show', ['ownerLease' => $lease->reference]) }}" class="font-bold underline">
                                View your lease
                            </a>
                        @else
                            <a href="{{ route('login') }}" class="font-bold underline">Sign in</a> to see it.
                        @endauth
                    </p>
                @endif
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-emerald-950">What you told us</h2>
                <dl class="mt-4 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Vehicle</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $application->vehicleLabel() }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Mileage</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            {{ $application->mileage_km ? number_format($application->mileage_km).' km' : 'Not stated' }}
                        </dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Preferred arrangement</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            {{ $application->preferred_payout_model?->label() ?? 'No preference' }}
                        </dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Hoped-for monthly</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            {{ $application->formattedExpectation() ?? 'Not stated' }}
                        </dd>
                    </div>
                </dl>

                <p class="mt-5 text-sm text-slate-600">
                    Something wrong here?
                    <a href="{{ route('contact') }}" class="font-bold text-emerald-800 underline">Tell us</a>
                    and quote {{ $application->reference }}.
                </p>
            </div>
        </div>
    </section>
@endsection
