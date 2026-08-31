@php
    use App\Enums\DriverTripStatus;
    use App\Enums\InspectionPhase;
    use App\Support\Fleet\InspectionChecklist;

    $vehicle = $trip?->vehicle;
    $preTrip = $trip?->inspectionFor(InspectionPhase::PreTrip);
    $postTrip = $trip?->inspectionFor(InspectionPhase::PostTrip);
    $status = $trip?->status ?? DriverTripStatus::Scheduled;
    // Server-derived so no control is offered that the action would refuse.
    $canStart = $status === DriverTripStatus::Scheduled;
    $canClose = $status === DriverTripStatus::InProgress;
    $needsVehicleChecks = $trip !== null && $trip->tracksOdometer();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ $source->label() }}</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $booking?->reference }}</h1>
            </div>
            <a href="{{ route('drivers.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to my jobs</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert">
                    <ul class="list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="job-heading">
                <h2 id="job-heading" class="text-lg font-black text-slate-950">The job</h2>
                <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Starts</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $assignment->starts_at?->timezone($timezone)->format('j M Y, H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Ends</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $assignment->ends_at?->timezone($timezone)->format('j M Y, H:i') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Customer</dt>
                        <dd class="mt-0.5 text-slate-800">{{ $booking?->contact_name }}</dd>
                        @if ($booking?->contact_phone)
                            <dd><a href="tel:{{ $booking->contact_phone }}" class="text-xs font-semibold text-emerald-800 underline">{{ $booking->contact_phone }}</a></dd>
                        @endif
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Vehicle</dt>
                        <dd class="mt-0.5 text-slate-800">
                            {{ $vehicle ? $vehicle->make.' '.$vehicle->model : 'Not a fleet vehicle' }}
                        </dd>
                        @if ($vehicle)
                            <dd class="font-mono text-xs text-slate-500">{{ $vehicle->registration_plate }}</dd>
                            <dd class="text-xs text-slate-500">Odometer {{ $vehicle->formattedOdometer() }}</dd>
                        @endif
                    </div>
                </dl>

                @if ($booking?->special_requests)
                    <div class="mt-5 rounded-2xl bg-stone-100 p-4">
                        <h3 class="text-xs font-black uppercase tracking-wide text-slate-700">Customer notes</h3>
                        <p class="mt-1 text-sm text-slate-800">{{ $booking->special_requests }}</p>
                    </div>
                @endif
            </section>

            @if ($needsVehicleChecks || $trip === null)
                <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="checks-heading">
                    <h2 id="checks-heading" class="text-lg font-black text-slate-950">Vehicle checks</h2>
                    <p class="mt-1 text-sm text-slate-600">
                        A pre-trip check has to pass before the vehicle leaves. Critical items left unchecked count
                        as a failure — "I did not look" is not the same as "it is fine".
                    </p>

                    {{-- Pairs rather than an enum-keyed map: an enum cannot be
                         an array key in PHP. --}}
                    @foreach ([[InspectionPhase::PreTrip, $preTrip], [InspectionPhase::PostTrip, $postTrip]] as [$phase, $existing])
                        <div class="mt-5 rounded-2xl border border-slate-200 p-5">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <h3 class="font-bold text-slate-900">{{ $phase->label() }}</h3>
                                @if ($existing)
                                    <span @class([
                                        'rounded-full px-3 py-1 text-xs font-bold',
                                        'bg-emerald-50 text-emerald-800' => $existing->passed,
                                        'bg-rose-50 text-rose-800' => ! $existing->passed,
                                    ])>{{ $existing->passed ? 'Passed' : 'Failed' }}</span>
                                @endif
                            </div>

                            @if ($existing)
                                <p class="mt-2 text-xs text-slate-500">
                                    Recorded at {{ number_format($existing->odometer_km) }} km on
                                    {{ $existing->created_at->timezone($timezone)->format('j M Y, H:i') }}
                                </p>
                                @if ($existing->has_defects)
                                    <div class="mt-3 rounded-xl border border-rose-200 bg-rose-50 p-4">
                                        <p class="text-xs font-black uppercase tracking-wide text-rose-800">Defects reported</p>
                                        <ul class="mt-1 list-disc pl-5 text-sm text-slate-800">
                                            @foreach ($existing->defectLabels() as $label)<li>{{ $label }}</li>@endforeach
                                        </ul>
                                        <p class="mt-2 text-sm text-slate-700">{{ $existing->defect_notes }}</p>
                                        @if ($existing->maintenanceRecord)
                                            <p class="mt-2 text-xs text-slate-600">
                                                Repair {{ $existing->maintenanceRecord->reference }} was raised for the workshop.
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            @elseif ($phase === InspectionPhase::PostTrip && ! $canClose)
                                <p class="mt-2 text-sm text-slate-500">Record this when you hand the vehicle back.</p>
                            @else
                                <form method="POST" action="{{ route('drivers.jobs.inspection', [$source->value, $assignment->id]) }}" class="mt-4 space-y-4">
                                    @csrf
                                    <input type="hidden" name="phase" value="{{ $phase->value }}">

                                    <div>
                                        <label for="odo-{{ $phase->value }}" class="block text-sm font-semibold text-slate-800">Odometer reading (km)</label>
                                        <input id="odo-{{ $phase->value }}" name="odometer_km" type="number" inputmode="numeric" required
                                               min="{{ $vehicle?->current_odometer_km ?? 0 }}"
                                               value="{{ old('odometer_km', $vehicle?->current_odometer_km) }}"
                                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>

                                    <fieldset>
                                        <legend class="text-sm font-semibold text-slate-800">Check each item</legend>
                                        <div class="mt-2 space-y-2">
                                            @foreach (InspectionChecklist::ITEMS as $key => $item)
                                                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-slate-200 px-3 py-2">
                                                    <span class="text-sm text-slate-800">
                                                        {{ $item['label'] }}
                                                        @if ($item['critical'])
                                                            <span class="ml-1 text-xs font-bold text-rose-700">critical</span>
                                                        @endif
                                                    </span>
                                                    <span class="flex gap-3 text-xs">
                                                        @foreach (['ok' => 'OK', 'defect' => 'Defect', 'not_applicable' => 'N/A'] as $value => $label)
                                                            <label class="flex cursor-pointer items-center gap-1">
                                                                <input type="radio" name="answers[{{ $key }}]" value="{{ $value }}"
                                                                       @checked(old('answers.'.$key) === $value)
                                                                       class="size-4 border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                                                <span>{{ $label }}</span>
                                                            </label>
                                                        @endforeach
                                                    </span>
                                                </div>
                                            @endforeach
                                        </div>
                                    </fieldset>

                                    <div>
                                        <label for="notes-{{ $phase->value }}" class="block text-sm font-semibold text-slate-800">Defect notes</label>
                                        <textarea id="notes-{{ $phase->value }}" name="defect_notes" rows="3" maxlength="2000"
                                                  placeholder="Required if you report any defect."
                                                  class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('defect_notes') }}</textarea>
                                    </div>

                                    <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                        Record {{ mb_strtolower($phase->label()) }}
                                    </button>
                                </form>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="trip-heading">
                <h2 id="trip-heading" class="text-lg font-black text-slate-950">The trip</h2>

                @if ($trip)
                    <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Status</dt>
                            <dd class="mt-0.5 font-semibold text-slate-900">{{ $trip->status->label() }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Out at</dt>
                            <dd class="mt-0.5 text-slate-800">{{ $trip->start_odometer_km !== null ? number_format($trip->start_odometer_km).' km' : '—' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Distance</dt>
                            <dd class="mt-0.5 text-slate-800">{{ $trip->formattedDistance() }}</dd>
                        </div>
                    </dl>
                @endif

                @if ($canStart)
                    <form method="POST" action="{{ route('drivers.jobs.start', [$source->value, $assignment->id]) }}" class="mt-5 space-y-3">
                        @csrf
                        @if ($needsVehicleChecks || $trip === null)
                            <p class="rounded-xl bg-stone-100 p-3 text-xs text-slate-600">
                                The pre-trip check must be recorded and passed first.
                            </p>
                        @endif
                        <div>
                            <label for="start-odo" class="block text-sm font-semibold text-slate-800">Odometer on departure (km)</label>
                            <input id="start-odo" name="odometer_km" type="number" inputmode="numeric"
                                   min="{{ $vehicle?->current_odometer_km ?? 0 }}"
                                   value="{{ old('odometer_km', $preTrip?->odometer_km ?? $vehicle?->current_odometer_km) }}"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Start the trip</button>
                    </form>
                @elseif ($canClose)
                    <form method="POST" action="{{ route('drivers.trips.complete', $trip) }}" class="mt-5 space-y-3">
                        @csrf
                        <div>
                            <label for="end-odo" class="block text-sm font-semibold text-slate-800">Odometer on return (km)</label>
                            <input id="end-odo" name="odometer_km" type="number" inputmode="numeric"
                                   min="{{ $trip->start_odometer_km ?? 0 }}"
                                   value="{{ old('odometer_km', $postTrip?->odometer_km) }}"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <div>
                            <label for="trip-notes" class="block text-sm font-semibold text-slate-800">Anything the office should know</label>
                            <textarea id="trip-notes" name="driver_notes" rows="3" maxlength="2000"
                                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('driver_notes', $trip->driver_notes) }}</textarea>
                        </div>
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Close the trip</button>
                    </form>

                    <form method="POST" action="{{ route('drivers.trips.abandon', $trip) }}" class="mt-4 space-y-2 border-t border-slate-200 pt-4">
                        @csrf
                        <label for="abandon-reason" class="block text-sm font-semibold text-slate-800">Could not complete it?</label>
                        <input id="abandon-reason" name="reason" type="text" minlength="5" maxlength="255" required
                               placeholder="What stopped the job?"
                               class="block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        <p class="text-xs text-slate-500">An abandoned job records no distance.</p>
                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-700 hover:bg-rose-50">Mark as abandoned</button>
                    </form>
                @elseif ($trip)
                    <p class="mt-4 text-sm text-slate-600">
                        This trip is {{ mb_strtolower($trip->status->label()) }}.
                        @if ($trip->closure_reason) {{ $trip->closure_reason }} @endif
                    </p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
