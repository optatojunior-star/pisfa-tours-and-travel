<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Driver</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Hello, {{ str($driver->name)->before(' ') }}</h1>
            </div>
            <p class="text-sm text-slate-500">{{ $now->format('l, j F Y') }}</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-4xl space-y-8 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($profile === null)
                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-6" role="status">
                    <h2 class="text-lg font-black text-slate-950">Your driver profile is not set up</h2>
                    <p class="mt-2 text-sm text-slate-700">
                        The office has not recorded your licence details yet. You can still see and run your
                        assigned work, but ask them to complete your profile.
                    </p>
                </div>
            @elseif ($profile->licenceHasExpired())
                <div class="rounded-3xl border border-rose-300 bg-rose-50 p-6" role="alert">
                    <h2 class="text-lg font-black text-rose-900">Your licence has expired</h2>
                    <p class="mt-2 text-sm text-rose-800">
                        It expired on {{ $profile->licence_expires_at?->format('j F Y') }}. Speak to the office
                        before driving.
                    </p>
                </div>
            @elseif ($profile->licenceExpiresWithin(30))
                <div class="rounded-3xl border border-amber-200 bg-amber-50 p-6" role="status">
                    <h2 class="text-lg font-black text-slate-950">Your licence expires soon</h2>
                    <p class="mt-2 text-sm text-slate-700">
                        It expires on {{ $profile->licence_expires_at?->format('j F Y') }}. Renew it in good time.
                    </p>
                </div>
            @endif

            @if ($openTrips->isNotEmpty())
                <section aria-labelledby="on-road-heading">
                    <h2 id="on-road-heading" class="text-lg font-black text-slate-950">You are on the road</h2>
                    <ul class="mt-4 space-y-3">
                        @foreach ($openTrips as $trip)
                            <li class="rounded-2xl border border-amber-300 bg-amber-50 p-5">
                                <p class="font-mono text-xs text-slate-600">{{ $trip->reference }}</p>
                                <p class="mt-1 font-bold text-slate-900">
                                    {{ $trip->vehicle ? $trip->vehicle->make.' '.$trip->vehicle->model : 'No fleet vehicle' }}
                                </p>
                                @if ($trip->start_odometer_km !== null)
                                    <p class="mt-1 text-xs text-slate-600">Out at {{ number_format($trip->start_odometer_km) }} km</p>
                                @endif
                                <p class="mt-3 text-sm text-slate-700">
                                    Close this trip from its job page when you hand the vehicle back.
                                </p>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <section aria-labelledby="today-heading">
                <h2 id="today-heading" class="text-lg font-black text-slate-950">Today</h2>

                @if ($today->isEmpty())
                    <div class="mt-4 rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                        <p class="text-sm text-slate-600">Nothing is scheduled for you today.</p>
                    </div>
                @else
                    <div class="mt-4 space-y-4">
                        @foreach ($today as $row)
                            @include('drivers.partials.job-card', ['row' => $row])
                        @endforeach
                    </div>
                @endif
            </section>

            <section aria-labelledby="upcoming-heading">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="upcoming-heading" class="text-lg font-black text-slate-950">Coming up</h2>
                    <a href="{{ route('drivers.history') }}" class="text-sm font-bold text-emerald-800 underline">Trip history</a>
                </div>

                @if ($upcoming->isEmpty())
                    <div class="mt-4 rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                        <p class="text-sm text-slate-600">You have no upcoming assignments.</p>
                    </div>
                @else
                    <div class="mt-4 space-y-4">
                        @foreach ($upcoming as $row)
                            @include('drivers.partials.job-card', ['row' => $row])
                        @endforeach
                    </div>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
