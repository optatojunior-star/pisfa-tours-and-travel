@php
    $editing = isset($package) && $package?->exists;
    $selectedCurrency = old('currency', $editing ? $package->currency : config('pisfa.currency.default', 'UGX'));
    $maximumDurationDays = (int) config('tours.maximum_duration_days', 90);
    $maximumBookingTravelers = (int) config('tours.maximum_booking_travelers', 50);
    $storedBasePrice = '';
    if ($editing) {
        $storedBasePrice = \App\Support\Money::forInput((int) $package->base_price_minor, $package->currency);
    }
    $basePrice = old('base_price', $storedBasePrice);
    $existingMedia = $editing ? $package->media->values() : collect();
    $existingItinerary = $editing ? $package->itineraryDays->values() : collect();
    $existingInclusions = $editing ? $package->inclusions->pluck('content')->values() : collect();
    $existingExclusions = $editing ? $package->exclusions->pluck('content')->values() : collect();

    $oldMedia = old('media');
    $oldItinerary = old('itinerary');
    $oldInclusions = old('inclusions');
    $oldExclusions = old('exclusions');
    $usingOldMedia = is_array($oldMedia);
    $usingOldItinerary = is_array($oldItinerary);
    $usingOldInclusions = is_array($oldInclusions);
    $usingOldExclusions = is_array($oldExclusions);

    $rowIndices = static function (mixed $oldRows, int $existingCount): array {
        if (is_array($oldRows)) {
            return $oldRows === [] ? [0] : array_keys($oldRows);
        }

        return range(0, max(1, $existingCount) - 1);
    };
    $nextIndex = static fn (array $indices): int => max(array_map(static fn (mixed $index): int => (int) $index, $indices)) + 1;

    $mediaIndices = $rowIndices($oldMedia, $existingMedia->count());
    $itineraryIndices = $rowIndices($oldItinerary, $existingItinerary->count());
    $inclusionIndices = $rowIndices($oldInclusions, $existingInclusions->count());
    $exclusionIndices = $rowIndices($oldExclusions, $existingExclusions->count());
@endphp

@if ($errors->any())
    <div id="package-form-errors" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800" role="alert" tabindex="-1">
        <p class="font-bold">The package was not saved. Review the highlighted information.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ $editing ? route('admin.tours.update', ['tourPackage' => $package]) : route('admin.tours.store') }}" enctype="multipart/form-data" class="space-y-7">
    @csrf
    @if ($editing) @method('PATCH') @endif

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="package-basics-heading">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Step 1</p>
            <h2 id="package-basics-heading" class="mt-1 text-xl font-black text-emerald-950">Package basics</h2>
        </div>
        <div class="mt-6 grid gap-5 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="package-name" class="block text-sm font-semibold text-slate-800">Package name</label>
                <input id="package-name" name="name" type="text" required maxlength="160" value="{{ old('name', $package->name ?? '') }}" placeholder="Bwindi gorilla trekking safari" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('name')" class="mt-2" />
            </div>
            <div>
                <label for="package-slug" class="block text-sm font-semibold text-slate-800">URL slug <span class="font-normal text-slate-500">(optional)</span></label>
                <input id="package-slug" name="slug" type="text" maxlength="180" pattern="[a-z0-9]+(?:-[a-z0-9]+)*" value="{{ old('slug', $package->slug ?? '') }}" placeholder="generated-from-name" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('slug')" class="mt-2" />
            </div>
            <div>
                <label for="package-category" class="block text-sm font-semibold text-slate-800">Category</label>
                <select id="package-category" name="category_id" required class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    <option value="">Select a category</option>
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" @selected((string) old('category_id', $package->tour_category_id ?? '') === (string) $category->id)>{{ $category->name }}{{ $category->is_active ? '' : ' (inactive)' }}</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('category_id')" class="mt-2" />
            </div>
            <div class="sm:col-span-2">
                <label for="package-destination" class="block text-sm font-semibold text-slate-800">Destination</label>
                <input id="package-destination" name="destination" type="text" required maxlength="160" value="{{ old('destination', $package->destination ?? '') }}" placeholder="Bwindi Impenetrable National Park, Uganda" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                <x-input-error :messages="$errors->get('destination')" class="mt-2" />
            </div>
            <div class="sm:col-span-2">
                <label for="package-summary" class="block text-sm font-semibold text-slate-800">Short summary</label>
                <textarea id="package-summary" name="summary" required maxlength="500" rows="3" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600" placeholder="A concise public overview for catalogue cards.">{{ old('summary', $package->summary ?? '') }}</textarea>
                <x-input-error :messages="$errors->get('summary')" class="mt-2" />
            </div>
            <div class="sm:col-span-2">
                <label for="package-description" class="block text-sm font-semibold text-slate-800">Full description</label>
                <textarea id="package-description" name="description" required maxlength="10000" rows="8" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old('description', $package->description ?? '') }}</textarea>
                <x-input-error :messages="$errors->get('description')" class="mt-2" />
            </div>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="package-pricing-heading">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Step 2</p>
        <h2 id="package-pricing-heading" class="mt-1 text-xl font-black text-emerald-950">Duration, pricing and group rules</h2>
        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="duration-days" class="block text-sm font-semibold text-slate-800">Duration in days</label>
                <input id="duration-days" name="duration_days" type="number" min="1" max="{{ $maximumDurationDays }}" required value="{{ old('duration_days', $package->duration_days ?? 1) }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="base-price" class="block text-sm font-semibold text-slate-800">Base price per traveler</label>
                <input id="base-price" name="base_price" type="text" inputmode="decimal" required maxlength="30" value="{{ $basePrice }}" placeholder="No commas" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600" aria-describedby="base-price-help">
                <p id="base-price-help" class="mt-2 text-xs text-slate-500">UGX uses whole units; USD accepts two decimal places. Do not enter separators.</p>
            </div>
            <div>
                <label for="package-currency" class="block text-sm font-semibold text-slate-800">Currency</label>
                <select id="package-currency" name="currency" required class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (config('tours.currencies', ['UGX', 'USD']) as $currency)<option value="{{ $currency }}" @selected($selectedCurrency === $currency)>{{ $currency }}</option>@endforeach
                </select>
            </div>
            <div>
                <label for="minimum-travelers" class="block text-sm font-semibold text-slate-800">Minimum travelers</label>
                <input id="minimum-travelers" name="min_travelers" type="number" min="1" max="{{ $maximumBookingTravelers }}" required value="{{ old('min_travelers', $package->min_travelers ?? 1) }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="maximum-travelers" class="block text-sm font-semibold text-slate-800">Maximum travelers per booking</label>
                <input id="maximum-travelers" name="max_travelers" type="number" min="1" max="{{ $maximumBookingTravelers }}" required value="{{ old('max_travelers', $package->max_travelers ?? 12) }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="cancellation-hours" class="block text-sm font-semibold text-slate-800">Cancellation cutoff hours</label>
                <input id="cancellation-hours" name="cancellation_cutoff_hours" type="number" min="1" max="2160" required value="{{ old('cancellation_cutoff_hours', $package->cancellation_cutoff_hours ?? 24) }}" class="mt-1 block w-full rounded-xl border-slate-300 shadow-sm focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </div>
        <label class="mt-6 flex items-start gap-3 rounded-2xl bg-amber-50 p-4 text-sm leading-6 text-amber-950">
            <input type="hidden" name="is_featured" value="0">
            <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $package->is_featured ?? false)) class="mt-1 rounded border-amber-400 text-emerald-700 focus:ring-emerald-600">
            <span><strong>Feature this package.</strong> Featured status changes catalogue ordering but does not publish a draft or make a departure bookable.</span>
        </label>
    </section>
    <section class="rounded-card border border-ink-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="package-media-heading">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-brand-700">Step 3</p>
        <h2 id="package-media-heading" class="mt-1 text-xl font-black text-brand-950">Photographs</h2>
        <p class="mt-2 text-sm leading-6 text-ink-600">
            The first photograph becomes the cover shown on the tours page.
        </p>

        <div class="mt-6">
            {{--
                A real file input, not a URL box. The previous version asked for
                "HTTPS image URLs", which only works if the picture is already
                hosted somewhere — and leaves the site depending on somebody
                else's server staying up.
            --}}
            <x-image-upload
                name="images"
                label="Upload from your computer"
                help="Landscape photographs work best. You can add more later."
                :existing="$package?->media"
                :delete-route="null" />
        </div>
    </section>


    <div class="sticky bottom-3 z-20 flex flex-col gap-3 rounded-2xl border border-slate-200 bg-white/95 p-4 shadow-xl backdrop-blur sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-slate-600">Saving keeps the package in its current publication state. Publishing is a separate audited action.</p>
        <div class="flex gap-3">
            <a href="{{ $editing ? route('admin.tours.show', ['tourPackage' => $package]) : route('admin.tours.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">Cancel</a>
            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-bold text-white hover:bg-emerald-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2">{{ $editing ? 'Save package changes' : 'Create draft package' }}</button>
        </div>
    </div>
</form>

@push('scripts')
    <script>
        (() => {
            const durationInput = document.getElementById('duration-days');

            document.querySelectorAll('[data-repeater]').forEach((repeater) => {
                const list = repeater.querySelector('[data-repeater-list]');
                const template = repeater.querySelector('[data-row-template]');
                const addButton = repeater.querySelector('[data-add-row]');
                const fillDurationButton = repeater.querySelector('[data-fill-duration]');
                const countLabel = repeater.querySelector('[data-row-count]');
                const status = repeater.querySelector('[data-repeater-status]');
                const kind = repeater.dataset.kind;
                const maximum = Number.parseInt(repeater.dataset.max, 10);

                if (! list || ! template || ! addButton || ! Number.isInteger(maximum)) {
                    return;
                }

                const rows = () => Array.from(list.children).filter((element) => element.hasAttribute('data-repeater-row'));
                const plural = (count) => count === 1 ? kind : `${kind}s`;
                const announce = (message) => {
                    if (status) {
                        status.textContent = '';
                        window.requestAnimationFrame(() => { status.textContent = message; });
                    }
                };
                const nextDayNumber = () => {
                    const used = new Set(rows().map((row) => Number.parseInt(row.querySelector('[name$="[day_number]"]')?.value ?? '', 10)).filter(Number.isInteger));

                    for (let day = 1; day <= maximum; day += 1) {
                        if (! used.has(day)) {
                            return day;
                        }
                    }

                    return maximum;
                };
                const sync = () => {
                    const currentRows = rows();
                    const atMaximum = currentRows.length >= maximum;

                    currentRows.forEach((row, position) => {
                        const displayPosition = position + 1;
                        const heading = row.querySelector('[data-row-label]');
                        const removeButton = row.querySelector('[data-remove-row]');
                        const screenReaderLabel = row.querySelector('label.sr-only');

                        if (heading) {
                            heading.textContent = kind === 'image' ? `Image ${displayPosition}` : `Day ${displayPosition}`;
                        }
                        if (screenReaderLabel) {
                            screenReaderLabel.textContent = `${kind.charAt(0).toUpperCase()}${kind.slice(1)} ${displayPosition}`;
                        }
                        if (removeButton) {
                            removeButton.classList.toggle('hidden', currentRows.length === 1);
                            removeButton.setAttribute('aria-label', `Remove ${kind} ${displayPosition}`);
                        }
                    });

                    addButton.disabled = atMaximum;
                    addButton.setAttribute('aria-disabled', atMaximum ? 'true' : 'false');
                    if (countLabel) {
                        countLabel.textContent = `${currentRows.length} of ${maximum} ${plural(maximum)}`;
                    }
                };
                const addRow = (focusNewRow = true) => {
                    if (rows().length >= maximum) {
                        announce(`The maximum of ${maximum} ${plural(maximum)} has been reached.`);
                        return null;
                    }

                    const index = Number.parseInt(repeater.dataset.nextIndex, 10);
                    const position = rows().length + 1;
                    const dayNumber = kind === 'itinerary day' ? nextDayNumber() : position;
                    const html = template.innerHTML
                        .split('__INDEX__').join(String(index))
                        .split('__POSITION__').join(String(position))
                        .split('__DAY_NUMBER__').join(String(dayNumber));

                    repeater.dataset.nextIndex = String(index + 1);
                    list.insertAdjacentHTML('beforeend', html);
                    const newRow = rows().at(-1);
                    sync();
                    announce(`${kind.charAt(0).toUpperCase()}${kind.slice(1)} ${position} added.`);

                    if (focusNewRow) {
                        newRow?.querySelector('input:not([type="hidden"]), textarea, select')?.focus();
                    }

                    return newRow;
                };

                addButton.classList.remove('hidden');
                addButton.addEventListener('click', () => addRow());

                list.addEventListener('click', (event) => {
                    const removeButton = event.target.closest('[data-remove-row]');
                    if (! removeButton || ! list.contains(removeButton)) {
                        return;
                    }

                    const currentRows = rows();
                    if (currentRows.length === 1) {
                        return;
                    }

                    const row = removeButton.closest('[data-repeater-row]');
                    const position = currentRows.indexOf(row);
                    row.remove();
                    sync();
                    announce(`${kind.charAt(0).toUpperCase()}${kind.slice(1)} ${position + 1} removed.`);
                    (rows()[Math.min(position, rows().length - 1)]?.querySelector('input:not([type="hidden"]), textarea, select') ?? addButton).focus();
                });

                if (fillDurationButton && durationInput) {
                    fillDurationButton.classList.remove('hidden');
                    fillDurationButton.addEventListener('click', () => {
                        const requestedDuration = Number.parseInt(durationInput.value, 10);
                        const desiredRows = Math.min(maximum, Math.max(1, Number.isInteger(requestedDuration) ? requestedDuration : 1));
                        const firstNewRow = rows().length < desiredRows ? addRow(false) : null;

                        while (rows().length < desiredRows) {
                            addRow(false);
                        }

                        if (firstNewRow) {
                            firstNewRow.querySelector('input:not([type="hidden"]), textarea, select')?.focus();
                            announce(`Itinerary expanded to ${desiredRows} days. Complete each new day before saving.`);
                        } else {
                            announce(`The itinerary already has ${rows().length} rows for a ${desiredRows}-day duration.`);
                        }
                    });
                }

                sync();
            });
        })();
    </script>
    @if ($errors->any())
        <script>document.getElementById('package-form-errors')?.focus();</script>
    @endif
@endpush
