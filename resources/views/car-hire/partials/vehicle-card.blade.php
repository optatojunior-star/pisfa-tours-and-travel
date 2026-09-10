@php
    $cover = $vehicle->coverMedia;
    $selectedMode = $mode ?? null;
    $rate = $vehicle->hireRates->first();
    $selectedMinor = $selectedMode === \App\Enums\HireMode::WithDriver
        ? $rate?->with_driver_daily_minor
        : ($selectedMode === \App\Enums\HireMode::SelfDrive ? $rate?->self_drive_daily_minor : null);
    $detailQuery = collect(request()->only(['pickup_at', 'return_at', 'hire_mode', 'currency']))->filter(fn ($value) => filled($value))->all();
@endphp

<article class="flex h-full flex-col overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-lg" aria-labelledby="vehicle-{{ $vehicle->id }}-title">
    <a href="{{ route('car-hire.show', ['vehicle' => $vehicle] + $detailQuery) }}" class="relative block aspect-[16/10] overflow-hidden bg-emerald-950 focus:outline-none focus-visible:ring-4 focus-visible:ring-amber-400 focus-visible:ring-inset" aria-label="View {{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }}">
        @if ($cover)
            <img src="{{ $cover->url }}" alt="{{ $cover->alt_text ?: $vehicle->make.' '.$vehicle->model }}" class="h-full w-full object-cover transition duration-500 hover:scale-105" loading="lazy">
        @else
            <div class="flex h-full items-center justify-center text-sm font-bold text-emerald-100">Image coming soon</div>
        @endif
        @if ($vehicle->is_featured)<span class="absolute left-4 top-4 rounded-full bg-amber-300 px-3 py-1 text-xs font-black text-emerald-950">Featured</span>@endif
    </a>
    <div class="flex flex-1 flex-col p-5">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">{{ \App\Support\VehicleSpecification::label(\App\Support\VehicleSpecification::bodyTypes(), $vehicle->vehicle_type) }}</p>
                <h2 id="vehicle-{{ $vehicle->id }}-title" class="mt-1 text-xl font-black text-emerald-950"><a href="{{ route('car-hire.show', ['vehicle' => $vehicle] + $detailQuery) }}" class="rounded focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">{{ $vehicle->year }} {{ $vehicle->make }} {{ $vehicle->model }}</a></h2>
            </div>
            <span class="whitespace-nowrap rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-800">Available</span>
        </div>
        <p class="mt-3 flex-1 text-sm leading-6 text-slate-600">{{ $vehicle->summary }}</p>

        {{--
            Five facts, not three.

            Engine and drive were missing, and drive is the one a customer going
            upcountry decides on — a 2WD saloon and a 4WD station wagon are not
            alternatives for the same trip, and the card gave no way to tell
            them apart without opening both.
        --}}
        @php($spec = \App\Support\VehicleSpecification::class)
        <dl class="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
            <div class="rounded-xl bg-slate-50 p-2"><dt class="text-slate-500">Seats</dt><dd class="mt-1 font-bold text-slate-900">{{ $vehicle->seating_capacity }}</dd></div>
            <div class="rounded-xl bg-slate-50 p-2"><dt class="text-slate-500">Gearbox</dt><dd class="mt-1 font-bold text-slate-900">{{ $spec::label($spec::transmissions(), $vehicle->transmission) }}</dd></div>
            <div class="rounded-xl bg-slate-50 p-2"><dt class="text-slate-500">Fuel</dt><dd class="mt-1 font-bold text-slate-900">{{ $spec::label($spec::fuelTypes(), $vehicle->fuel_type) }}</dd></div>
            @if ($vehicle->drive_type)
                <div class="rounded-xl bg-emerald-50 p-2"><dt class="text-emerald-700">Drive</dt><dd class="mt-1 font-bold text-emerald-900">{{ $spec::label($spec::driveTypes(), $vehicle->drive_type) }}</dd></div>
            @endif
            @if ($vehicle->engine_cc)
                <div class="rounded-xl bg-slate-50 p-2"><dt class="text-slate-500">Engine</dt><dd class="mt-1 font-bold text-slate-900">{{ number_format($vehicle->engine_cc) }} cc</dd></div>
            @endif
            <div class="rounded-xl bg-slate-50 p-2"><dt class="text-slate-500">Luggage</dt><dd class="mt-1 font-bold text-slate-900">{{ $vehicle->luggage_capacity }} bags</dd></div>
        </dl>
        <div class="mt-5 border-t border-slate-100 pt-4">
            @if ($selectedMinor !== null)
                <p class="text-xs text-slate-500">{{ $selectedMode->label() }} from</p>
                <p class="text-lg font-black text-emerald-950">{{ \App\Support\Money::format((int) $selectedMinor, $rate->currency) }} <span class="text-xs font-medium text-slate-500">/ day</span></p>
            @else
                <div class="space-y-1 text-sm">
                    @if ($rate?->self_drive_daily_minor)<p><span class="text-slate-500">Self-drive:</span> <strong>{{ \App\Support\Money::format((int) $rate->self_drive_daily_minor, $rate->currency) }}/day</strong></p>@endif
                    @if ($rate?->with_driver_daily_minor)<p><span class="text-slate-500">With driver:</span> <strong>{{ \App\Support\Money::format((int) $rate->with_driver_daily_minor, $rate->currency) }}/day</strong></p>@endif
                </div>
            @endif
            <a href="{{ route('car-hire.show', ['vehicle' => $vehicle] + $detailQuery) }}" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-800 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-400 focus-visible:ring-offset-2">View vehicle</a>

            {{-- The tick belongs to the compare form wrapping the whole grid.
                 It is a real checkbox with a real label, so the form submits and
                 the comparison works with JavaScript switched off. --}}
            <label class="mt-3 flex min-h-11 cursor-pointer items-center gap-2.5 rounded-xl border border-slate-200 px-3 text-sm font-semibold text-slate-700 transition has-[:checked]:border-emerald-600 has-[:checked]:bg-emerald-50 has-[:checked]:text-emerald-900">
                <input type="checkbox" name="vehicles[]" value="{{ $vehicle->slug }}"
                       x-model="picked"
                       class="size-5 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                Compare this one
            </label>
        </div>
    </div>
</article>
