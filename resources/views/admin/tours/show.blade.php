<x-app-layout>
    @php
        $packageStatus = $package->status->value;
    @endphp
    <x-slot name="header">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div><a href="{{ route('admin.tours.index') }}" class="text-sm font-bold text-emerald-800 underline decoration-emerald-300 underline-offset-4">Back to packages</a><div class="mt-4 flex flex-wrap items-center gap-2"><p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">{{ $package->category?->name }}</p><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-slate-100 text-slate-700' => $packageStatus === 'draft', 'bg-emerald-50 text-emerald-800' => $packageStatus === 'published', 'bg-amber-50 text-amber-900' => $packageStatus === 'archived'])>{{ $package->status->label() }}</span></div><h1 class="mt-2 text-2xl font-bold tracking-tight text-slate-950">{{ $package->name }}</h1><p class="mt-1 text-sm text-slate-500">{{ $package->destination }} · {{ $package->duration_days }} {{ str('day')->plural($package->duration_days) }}</p></div>
            <div class="flex flex-wrap gap-2">
                @if ($packageStatus === 'published')<a href="{{ route('tours.show', ['tourPackage' => $package]) }}" target="_blank" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-emerald-200 px-4 py-2.5 text-sm font-bold text-emerald-800 hover:bg-emerald-50">View public page</a>@endif
                <a href="{{ route('admin.tours.edit', ['tourPackage' => $package]) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Edit package</a>
                @if ($packageStatus === 'draft')
                    <form method="POST" action="{{ route('admin.tours.publish', ['tourPackage' => $package]) }}">@csrf @method('PATCH')<button type="submit" class="min-h-11 rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">Publish</button></form>
                @elseif ($packageStatus === 'published')
                    <form method="POST" action="{{ route('admin.tours.archive', ['tourPackage' => $package]) }}">@csrf @method('PATCH')<button type="submit" class="min-h-11 rounded-xl bg-amber-100 px-4 py-2.5 text-sm font-bold text-amber-950 hover:bg-amber-200">Archive</button></form>
                @elseif ($packageStatus === 'archived')
                    <form method="POST" action="{{ route('admin.tours.restore', ['tourPackage' => $package]) }}">@csrf @method('PATCH')<button type="submit" class="min-h-11 rounded-xl bg-slate-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-slate-700">Restore to draft</button></form>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8 sm:py-10">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success') || session('status'))<div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-950" role="status" aria-live="polite">{{ session('success') ?? session('status') }}</div>@endif
            @if ($errors->any())<div id="tour-management-errors" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert" tabindex="-1"><p class="font-bold">The tour change was not saved.</p><ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif

            <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Package summary">
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs text-slate-500">Base price</p><p class="mt-1 text-lg font-black text-amber-700">{{ \App\Support\Money::format((int) $package->base_price_minor, $package->currency) }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs text-slate-500">Booking group</p><p class="mt-1 text-lg font-black text-slate-950">{{ $package->min_travelers }}–{{ $package->max_travelers }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs text-slate-500">Departures</p><p class="mt-1 text-lg font-black text-slate-950">{{ $package->departures->count() }}</p></div>
                <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><p class="text-xs text-slate-500">Bookings</p><p class="mt-1 text-lg font-black text-slate-950">{{ $package->bookings_count ?? $package->bookings->count() }}</p></div>
            </section>

            <div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_22rem] xl:items-start">
                <div class="space-y-6">
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="departure-list-heading">
                        <div><p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Availability</p><h2 id="departure-list-heading" class="mt-1 text-xl font-black text-emerald-950">Scheduled departures</h2><p class="mt-2 text-sm leading-6 text-slate-600">Capacity includes every pending, confirmed or in-progress traveler. Status changes are separate from ordinary edits.</p></div>
                        @if ($package->departures->isEmpty())
                            <div class="mt-6 rounded-2xl border border-dashed border-slate-300 px-5 py-10 text-center"><h3 class="font-bold text-slate-900">No departures yet</h3><p class="mt-1 text-sm text-slate-600">Use the form on this page to create the first bookable date.</p></div>
                        @else
                            <div class="mt-6 space-y-4">
                                @foreach ($package->departures->sortByDesc('starts_at') as $departure)
                                    @php
                                        $departureStatus = $departure->status->value;
                                        $reserved = (int) ($departure->reserved_seats ?? 0);
                                        $departureCurrency = $departure->price_override_minor !== null ? ($departure->currency ?? $package->currency) : $package->currency;
                                        $departurePrice = $departure->price_override_minor !== null
                                            ? \App\Support\Money::forInput((int) $departure->price_override_minor, $departureCurrency)
                                            : '';
                                    @endphp
                                    <article class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5" aria-labelledby="departure-admin-{{ $departure->id }}">
                                        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                                            <div><div class="flex flex-wrap items-center gap-2"><h3 id="departure-admin-{{ $departure->id }}" class="font-black text-slate-950"><time datetime="{{ $departure->starts_at->toIso8601String() }}">{{ $departure->starts_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</time></h3><span @class(['rounded-full px-2.5 py-1 text-xs font-bold', 'bg-emerald-50 text-emerald-800' => $departureStatus === 'scheduled', 'bg-amber-50 text-amber-800' => $departureStatus === 'closed', 'bg-rose-50 text-rose-800' => $departureStatus === 'cancelled', 'bg-sky-50 text-sky-800' => $departureStatus === 'completed'])>{{ $departure->status->label() }}</span></div><p class="mt-1 text-xs text-slate-500">Ends {{ $departure->ends_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}</p><p class="mt-2 text-sm text-slate-700"><strong>{{ $reserved }}</strong> reserved of {{ $departure->capacity }} places · {{ $departure->price_override_minor !== null ? \App\Support\Money::format((int) $departure->price_override_minor, $departureCurrency) : 'Uses base price' }}</p></div>
                                            <div class="flex flex-wrap gap-2">
                                                @if ($departureStatus === 'scheduled')
                                                    <form method="POST" action="{{ route('admin.tour-departures.status', ['tourPackage' => $package, 'tourDeparture' => $departure]) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="status" value="closed">
                                                        <button type="submit" class="min-h-10 rounded-xl border border-amber-200 px-3 py-2 text-xs font-bold text-amber-900 hover:bg-amber-50">Close booking</button>
                                                    </form>
                                                @elseif ($departureStatus === 'closed')
                                                    <form method="POST" action="{{ route('admin.tour-departures.status', ['tourPackage' => $package, 'tourDeparture' => $departure]) }}">
                                                        @csrf
                                                        @method('PATCH')
                                                        <input type="hidden" name="status" value="scheduled">
                                                        <button type="submit" class="min-h-10 rounded-xl border border-emerald-200 px-3 py-2 text-xs font-bold text-emerald-800 hover:bg-emerald-50">Reopen</button>
                                                    </form>
                                                @endif
                                                @if (in_array($departureStatus, ['scheduled', 'closed'], true))
                                                    @if ($departure->ends_at->isPast())
                                                        <form method="POST" action="{{ route('admin.tour-departures.status', ['tourPackage' => $package, 'tourDeparture' => $departure]) }}">
                                                            @csrf
                                                            @method('PATCH')
                                                            <input type="hidden" name="status" value="completed">
                                                            <button type="submit" class="min-h-10 rounded-xl border border-sky-200 px-3 py-2 text-xs font-bold text-sky-800 hover:bg-sky-50">Mark completed</button>
                                                        </form>
                                                    @else
                                                        <div class="max-w-44 text-right"><button type="button" disabled class="min-h-10 cursor-not-allowed rounded-xl border border-slate-200 bg-slate-100 px-3 py-2 text-xs font-bold text-slate-400">Mark completed</button><p class="mt-1 text-xs leading-4 text-slate-500">Available after {{ $departure->ends_at->timezone(config('pisfa.business_timezone'))->format('j M Y, g:i A') }}.</p></div>
                                                    @endif
                                                @endif
                                            </div>
                                        </div>

                                        <div class="mt-4 grid gap-3 md:grid-cols-2">
                                            <details class="rounded-xl border border-slate-200 bg-white p-4"><summary class="cursor-pointer rounded font-bold text-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">Edit departure</summary>
                                                <form method="POST" action="{{ route('admin.tour-departures.update', ['tourPackage' => $package, 'tourDeparture' => $departure]) }}" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf @method('PATCH')
                                                    <div><label for="departure-{{ $departure->id }}-starts" class="block text-xs font-semibold text-slate-700">Starts</label><input id="departure-{{ $departure->id }}-starts" name="starts_at" type="datetime-local" required value="{{ $departure->starts_at->timezone(config('pisfa.business_timezone'))->format('Y-m-d\TH:i') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></div>
                                                    <div><label for="departure-{{ $departure->id }}-ends" class="block text-xs font-semibold text-slate-700">Ends</label><input id="departure-{{ $departure->id }}-ends" name="ends_at" type="datetime-local" required value="{{ $departure->ends_at->timezone(config('pisfa.business_timezone'))->format('Y-m-d\TH:i') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></div>
                                                    <div><label for="departure-{{ $departure->id }}-cutoff" class="block text-xs font-semibold text-slate-700">Booking cutoff</label><input id="departure-{{ $departure->id }}-cutoff" name="cancellation_cutoff_at" type="datetime-local" required value="{{ $departure->cancellation_cutoff_at->timezone(config('pisfa.business_timezone'))->format('Y-m-d\TH:i') }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></div>
                                                    <div><label for="departure-{{ $departure->id }}-capacity" class="block text-xs font-semibold text-slate-700">Capacity</label><input id="departure-{{ $departure->id }}-capacity" name="capacity" type="number" min="1" max="{{ config('tours.maximum_departure_capacity', 500) }}" required value="{{ $departure->capacity }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></div>
                                                    <div><label for="departure-{{ $departure->id }}-price" class="block text-xs font-semibold text-slate-700">Price override</label><input id="departure-{{ $departure->id }}-price" name="price_override" type="text" inputmode="decimal" value="{{ $departurePrice }}" placeholder="Blank uses base price" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></div>
                                                    <div><label for="departure-{{ $departure->id }}-currency" class="block text-xs font-semibold text-slate-700">Currency</label><select id="departure-{{ $departure->id }}-currency" name="currency" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">@foreach (config('tours.currencies', ['UGX','USD']) as $code)<option value="{{ $code }}" @selected($departureCurrency === $code)>{{ $code }}</option>@endforeach</select></div>
                                                    <div class="sm:col-span-2"><label for="departure-{{ $departure->id }}-meeting" class="block text-xs font-semibold text-slate-700">Meeting point</label><input id="departure-{{ $departure->id }}-meeting" name="meeting_point" type="text" maxlength="300" value="{{ $departure->meeting_point }}" class="mt-1 block w-full rounded-lg border-slate-300 text-sm"></div>
                                                    <div><label for="departure-{{ $departure->id }}-customer-notes" class="block text-xs font-semibold text-slate-700">Customer notes</label><textarea id="departure-{{ $departure->id }}-customer-notes" name="customer_notes" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $departure->customer_notes }}</textarea></div>
                                                    <div><label for="departure-{{ $departure->id }}-internal-notes" class="block text-xs font-semibold text-slate-700">Internal notes</label><textarea id="departure-{{ $departure->id }}-internal-notes" name="internal_notes" rows="3" maxlength="2000" class="mt-1 block w-full rounded-lg border-slate-300 text-sm">{{ $departure->getRawOriginal('internal_notes') }}</textarea></div>
                                                    <div class="sm:col-span-2"><button type="submit" class="min-h-10 rounded-xl bg-slate-900 px-4 py-2 text-sm font-bold text-white hover:bg-slate-700">Save departure</button></div>
                                                </form>
                                            </details>
                                            @if (in_array($departureStatus, ['scheduled', 'closed'], true))
                                                <details class="rounded-xl border border-rose-200 bg-white p-4"><summary class="cursor-pointer rounded font-bold text-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500">Cancel departure</summary><p class="mt-3 text-xs leading-5 text-slate-600">This action is audited and can affect active bookings. A reason is required.</p><form method="POST" action="{{ route('admin.tour-departures.status', ['tourPackage' => $package, 'tourDeparture' => $departure]) }}" class="mt-3 space-y-3">@csrf @method('PATCH')<input type="hidden" name="status" value="cancelled"><div><label for="departure-{{ $departure->id }}-cancel-reason" class="block text-xs font-semibold text-rose-900">Cancellation reason</label><textarea id="departure-{{ $departure->id }}-cancel-reason" name="reason" required maxlength="500" rows="3" class="mt-1 block w-full rounded-lg border-rose-300 text-sm focus:border-rose-500 focus:ring-rose-500"></textarea></div><button type="submit" class="min-h-10 rounded-xl bg-rose-700 px-4 py-2 text-sm font-bold text-white hover:bg-rose-800">Cancel departure</button></form></details>
                                            @endif
                                        </div>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>
                </div>

                <aside class="xl:sticky xl:top-24">
                    @if ($packageStatus === 'archived')
                        <section class="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm" aria-labelledby="archived-departure-heading">
                            <h2 id="archived-departure-heading" class="text-lg font-black text-amber-950">Package archived</h2>
                            <p class="mt-2 text-sm leading-6 text-amber-950">Restore this package to draft before adding another departure. Existing departures and bookings remain available above for operational follow-up.</p>
                        </section>
                    @else
                    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="new-departure-heading">
                        <h2 id="new-departure-heading" class="text-xl font-black text-emerald-950">Add departure</h2><p class="mt-2 text-sm leading-6 text-slate-600">Times are entered in {{ config('pisfa.business_timezone') }} and stored securely in UTC.</p>
                        <form method="POST" action="{{ route('admin.tour-departures.store', ['tourPackage' => $package]) }}" class="mt-5 space-y-4">@csrf
                            <div><label for="new-departure-starts" class="block text-sm font-semibold text-slate-800">Starts</label><input id="new-departure-starts" name="starts_at" type="datetime-local" required value="{{ old('starts_at') }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="new-departure-ends" class="block text-sm font-semibold text-slate-800">Ends</label><input id="new-departure-ends" name="ends_at" type="datetime-local" required value="{{ old('ends_at') }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="new-departure-cutoff" class="block text-sm font-semibold text-slate-800">Booking cutoff</label><input id="new-departure-cutoff" name="cancellation_cutoff_at" type="datetime-local" required value="{{ old('cancellation_cutoff_at') }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="new-departure-capacity" class="block text-sm font-semibold text-slate-800">Capacity</label><input id="new-departure-capacity" name="capacity" type="number" min="1" max="{{ config('tours.maximum_departure_capacity', 500) }}" required value="{{ old('capacity', $package->max_travelers) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="new-departure-price" class="block text-sm font-semibold text-slate-800">Price override <span class="font-normal text-slate-500">(optional)</span></label><input id="new-departure-price" name="price_override" type="text" inputmode="decimal" maxlength="30" value="{{ old('price_override') }}" placeholder="Blank uses base price" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="new-departure-currency" class="block text-sm font-semibold text-slate-800">Currency</label><select id="new-departure-currency" name="currency" required class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">@foreach (config('tours.currencies', ['UGX','USD']) as $code)<option value="{{ $code }}" @selected(old('currency', $package->currency) === $code)>{{ $code }}</option>@endforeach</select></div>
                            <div><label for="new-departure-meeting" class="block text-sm font-semibold text-slate-800">Meeting point <span class="font-normal text-slate-500">(optional)</span></label><input id="new-departure-meeting" name="meeting_point" type="text" maxlength="300" value="{{ old('meeting_point') }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="new-departure-customer-notes" class="block text-sm font-semibold text-slate-800">Customer notes <span class="font-normal text-slate-500">(optional)</span></label><textarea id="new-departure-customer-notes" name="customer_notes" rows="3" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('customer_notes') }}</textarea></div>
                            <div><label for="new-departure-internal-notes" class="block text-sm font-semibold text-slate-800">Internal notes <span class="font-normal text-slate-500">(optional)</span></label><textarea id="new-departure-internal-notes" name="internal_notes" rows="3" maxlength="2000" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('internal_notes') }}</textarea></div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">Add departure</button>
                        </form>
                    </section>
                    @endif
                </aside>
            </div>
        </div>
    </div>
    @if ($errors->any())@push('scripts')<script>document.getElementById('tour-management-errors')?.focus();</script>@endpush @endif
</x-app-layout>
