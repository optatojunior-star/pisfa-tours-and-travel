@extends('layouts.public')

@section('content')
<section class="bg-emerald-950 px-4 py-16 text-white sm:px-6 sm:py-20 lg:px-8">
    <div class="mx-auto max-w-7xl"><p class="text-sm font-black uppercase tracking-[0.2em] text-amber-300">Car hire</p><h1 class="mt-3 max-w-4xl text-4xl font-black tracking-tight sm:text-5xl">Find a vehicle for the road ahead</h1><p class="mt-5 max-w-2xl text-lg leading-8 text-emerald-100">Compare available self-drive and chauffeured vehicles. A request reserves a temporary hold for review; no payment is taken online.</p></div>
</section>

<div class="mx-auto max-w-7xl px-4 py-10 sm:px-6 lg:px-8">
    @if ($errors->any())<div class="mb-6 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert"><p class="font-bold">Check the search details.</p><ul class="mt-2 list-disc pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6" aria-labelledby="hire-filters-heading">
        <div class="flex items-end justify-between gap-4"><div><p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Live catalogue</p><h2 id="hire-filters-heading" class="mt-1 text-xl font-black text-emerald-950">Search vehicles</h2></div><a href="{{ route('car-hire.index') }}" class="text-sm font-bold text-emerald-800 underline decoration-amber-400 decoration-2 underline-offset-4">Clear filters</a></div>
        <form method="GET" action="{{ route('car-hire.index') }}" class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="sm:col-span-2"><label for="hire-q" class="block text-sm font-semibold text-slate-800">Search</label><input id="hire-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Make, model or vehicle type" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600"></div>
            <div><label for="pickup-at" class="block text-sm font-semibold text-slate-800">Pickup (Uganda time)</label><input id="pickup-at" name="pickup_at" type="datetime-local" value="{{ $filters['pickup_at'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600"></div>
            <div><label for="return-at" class="block text-sm font-semibold text-slate-800">Return (Uganda time)</label><input id="return-at" name="return_at" type="datetime-local" value="{{ $filters['return_at'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600"></div>
            <div><label for="hire-mode" class="block text-sm font-semibold text-slate-800">Hire mode</label><select id="hire-mode" name="hire_mode" class="mt-1 block w-full rounded-xl border-slate-300"><option value="">Either mode</option>@foreach (\App\Enums\HireMode::cases() as $case)<option value="{{ $case->value }}" @selected(($filters['hire_mode'] ?? '') === $case->value)>{{ $case->label() }}</option>@endforeach</select></div>
            <div><label for="vehicle-type" class="block text-sm font-semibold text-slate-800">Vehicle type</label><select id="vehicle-type" name="vehicle_type" class="mt-1 block w-full rounded-xl border-slate-300"><option value="">All types</option>@foreach ($vehicleTypes as $value => $label)<option value="{{ $value }}" @selected(($filters['vehicle_type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div><label for="transmission" class="block text-sm font-semibold text-slate-800">Transmission</label><select id="transmission" name="transmission" class="mt-1 block w-full rounded-xl border-slate-300"><option value="">Any transmission</option>@foreach ($transmissions as $value => $label)<option value="{{ $value }}" @selected(($filters['transmission'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></div>
            {{-- The filter a customer heading upcountry reaches for first. --}}
            <div>
                <label for="drive-type" class="block text-sm font-semibold text-slate-800">Drive</label>
                <select id="drive-type" name="drive_type" class="mt-1 block w-full rounded-xl border-slate-300" aria-describedby="drive-type-help">
                    <option value="">Any drive</option>
                    @foreach ($driveTypes as $value => $label)
                        <option value="{{ $value }}" @selected(($filters['drive_type'] ?? '') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                <p id="drive-type-help" class="mt-1 text-xs text-slate-500">Choose 4WD for the parks and murram roads.</p>
            </div>
            <div><label for="min-seats" class="block text-sm font-semibold text-slate-800">Minimum seats</label><input id="min-seats" name="min_seats" type="number" min="1" max="100" value="{{ $filters['min_seats'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300"></div>
            <div><label for="hire-currency" class="block text-sm font-semibold text-slate-800">Price currency</label><select id="hire-currency" name="currency" aria-describedby="hire-price-help" class="mt-1 block w-full rounded-xl border-slate-300"><option value="">All currencies</option>@foreach (config('car_hire.currencies', ['UGX','USD']) as $code)<option value="{{ $code }}" @selected(($filters['currency'] ?? '') === $code)>{{ $code }}</option>@endforeach</select></div>
            <div class="grid grid-cols-2 gap-2"><div><label for="min-price" class="block text-sm font-semibold text-slate-800">Min/day</label><input id="min-price" name="min_price" inputmode="decimal" value="{{ $filters['min_price'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300"></div><div><label for="max-price" class="block text-sm font-semibold text-slate-800">Max/day</label><input id="max-price" name="max_price" inputmode="decimal" value="{{ $filters['max_price'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300"></div></div>
            <div><label for="hire-sort" class="block text-sm font-semibold text-slate-800">Sort by</label><select id="hire-sort" name="sort" class="mt-1 block w-full rounded-xl border-slate-300"><option value="recommended">Recommended</option><option value="price_asc" @selected(($filters['sort'] ?? '') === 'price_asc')>Lowest daily rate</option><option value="price_desc" @selected(($filters['sort'] ?? '') === 'price_desc')>Highest daily rate</option><option value="newest" @selected(($filters['sort'] ?? '') === 'newest')>Newest</option><option value="seats_desc" @selected(($filters['sort'] ?? '') === 'seats_desc')>Most seats</option></select></div>
            <div class="flex items-end"><button type="submit" class="min-h-11 w-full rounded-xl bg-emerald-800 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-900">Search availability</button></div>
            <p id="hire-price-help" class="text-xs leading-5 text-slate-500 sm:col-span-2 lg:col-span-4">Choose a hire mode and currency when filtering or sorting by price. Dates and times are interpreted in Africa/Kampala.</p>
        </form>
    </section>

    <section class="mt-10" aria-labelledby="vehicle-results-heading" aria-live="polite">
        <div class="flex items-end justify-between"><div><h2 id="vehicle-results-heading" class="text-2xl font-black text-emerald-950">Available vehicles</h2><p class="mt-1 text-sm text-slate-600">{{ $vehicles->total() }} {{ str('vehicle')->plural($vehicles->total()) }} found</p></div></div>
        @if ($vehicles->isEmpty())
            <div class="mt-6 rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center"><h3 class="text-lg font-bold text-slate-900">No vehicles match this search</h3><p class="mt-2 text-sm text-slate-600">Try a different interval, mode, or vehicle type.</p><a href="{{ route('car-hire.index') }}" class="mt-5 inline-flex rounded-xl bg-emerald-800 px-5 py-3 text-sm font-bold text-white">Clear filters</a></div>
        @else
            {{--
                The grid is a form.

                Choosing between a Prado and a Hiace is a question about the
                differences, and the catalogue could only answer "what is this
                one" — you had to open two tabs and scroll between them. Ticking
                two to four cards and submitting produces a side-by-side table
                whose address carries the selection, so it can be sent to
                whoever is actually paying for the trip.

                A plain GET form with real checkboxes: it works with JavaScript
                off, and the Alpine below only adds the running count and
                disables a submit that would fail validation anyway.
            --}}
            <form method="GET" action="{{ route('car-hire.compare') }}"
                  x-data="{ picked: [], max: {{ \App\Http\Requests\CarHire\CompareVehiclesRequest::maximum() }} }">
                @foreach (['pickup_at', 'return_at', 'hire_mode', 'currency'] as $carry)
                    @if (filled($filters[$carry] ?? null))
                        <input type="hidden" name="{{ $carry }}" value="{{ $filters[$carry] }}">
                    @endif
                @endforeach

                <div class="mt-6 grid gap-6 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach ($vehicles as $vehicle)
                        @include('car-hire.partials.vehicle-card', ['vehicle' => $vehicle, 'mode' => $mode])
                    @endforeach
                </div>

                @if ($vehicles->count() > 1)
                    <div x-show="picked.length > 0" x-cloak
                         class="sticky bottom-4 z-30 mt-6 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-emerald-700 bg-emerald-950 p-4 text-white shadow-xl">
                        <p class="text-sm font-semibold">
                            <span x-text="picked.length">0</span> selected
                            <span class="text-emerald-200" x-show="picked.length < 2">— tick one more to compare</span>
                            <span class="text-amber-300" x-show="picked.length > max">— that is more than <span x-text="max"></span></span>
                        </p>
                        <div class="flex items-center gap-2">
                            <button type="button" @click="picked = []; $el.closest('form').querySelectorAll('input[name=&quot;vehicles[]&quot;]').forEach(box => box.checked = false)"
                                    class="min-h-11 rounded-xl border border-white/30 px-4 text-sm font-bold text-white hover:bg-white/10">
                                Clear
                            </button>
                            <button type="submit"
                                    :disabled="picked.length < 2 || picked.length > max"
                                    class="min-h-11 rounded-xl bg-amber-400 px-5 text-sm font-black text-emerald-950 transition hover:bg-amber-300 disabled:cursor-not-allowed disabled:opacity-50">
                                Compare side by side
                            </button>
                        </div>
                    </div>
                @endif
            </form>

            <div class="mt-8">{{ $vehicles->links() }}</div>
        @endif
    </section>
</div>
@endsection
