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

            {{--
                The driver's own headshot.

                Kept at the bottom because it is a set-once thing, not daily
                work. It matters at the other end: the customer standing in
                arrivals at Entebbe who currently has a name and nothing else to
                go on, and has to let a stranger start the conversation before
                they can tell whether he is the right stranger.
            --}}
            @if ($profile !== null)
                <section aria-labelledby="driver-photo-heading" class="rounded-3xl border border-ink-200 bg-white p-6">
                    <h2 id="driver-photo-heading" class="text-lg font-black text-slate-950">Your photograph</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Customers see this on their confirmation so they can recognise you at the airport.
                        Face the camera in good light, no sunglasses, no hat.
                    </p>

                    @php($photograph = $profile->photographUrl())

                    <div class="mt-5 flex flex-col gap-5 sm:flex-row sm:items-start">
                        <div class="shrink-0">
                            @if ($photograph)
                                <img src="{{ $photograph }}" alt="Your current photograph"
                                     class="size-28 rounded-2xl border border-ink-200 object-cover">
                            @else
                                <div class="grid size-28 place-items-center rounded-2xl border-2 border-dashed border-ink-300 bg-ink-50 text-xs font-semibold text-ink-500">
                                    No photograph
                                </div>
                            @endif
                        </div>

                        <div class="min-w-0 flex-1 space-y-4">
                            <form method="POST" action="{{ route('drivers.photograph.store') }}" enctype="multipart/form-data">
                                @csrf
                                <x-image-upload
                                    name="images"
                                    input-id="driver-photograph"
                                    :multiple="false"
                                    :max-edge="800"
                                    :label="$photograph ? 'Replace your photograph' : 'Add your photograph'"
                                    help="Just your head and shoulders. It is never shown publicly — only to a customer whose transfer you have been assigned to." />

                                <button type="submit" class="mt-3 min-h-11 rounded-control bg-brand-700 px-5 text-sm font-bold text-white hover:bg-brand-800">
                                    Save photograph
                                </button>
                            </form>

                            @if ($photograph)
                                <form method="POST" action="{{ route('drivers.photograph.destroy') }}"
                                      onsubmit="return confirm('Remove your photograph? Customers will see your name only.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="text-sm font-bold text-rose-700 underline">Remove my photograph</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </section>
            @endif
        </div>
    </div>
</x-app-layout>
