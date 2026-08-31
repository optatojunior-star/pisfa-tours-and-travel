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

<form method="POST" action="{{ $editing ? route('admin.tours.update', ['tourPackage' => $package]) : route('admin.tours.store') }}" class="space-y-7">
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

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="package-media-heading">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Step 3</p>
        <h2 id="package-media-heading" class="mt-1 text-xl font-black text-emerald-950">Public images</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">Add HTTPS image URLs and meaningful alternative text. Blank rows are ignored.</p>
        <div class="mt-6" data-repeater data-kind="image" data-max="20" data-next-index="{{ $nextIndex($mediaIndices) }}">
            <div id="package-media-rows" class="space-y-4" data-repeater-list>
                @foreach ($mediaIndices as $position => $index)
                    @php
                        $medium = $usingOldMedia ? null : $existingMedia->get($index);
                    @endphp
                    <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-4" data-repeater-row>
                        <legend class="px-2 text-sm font-bold text-slate-800" data-row-label>Image {{ $position + 1 }}</legend>
                        <div class="mb-3 flex justify-end">
                            <button type="button" class="hidden min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove image {{ $position + 1 }}">Remove image</button>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="sm:col-span-2"><label for="media-{{ $index }}-url" class="block text-xs font-semibold text-slate-700">Image URL or path</label><input id="media-{{ $index }}-url" name="media[{{ $index }}][url]" type="text" maxlength="2048" placeholder="https://… or /storage/…" value="{{ old("media.$index.url", $medium?->url) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="media-{{ $index }}-alt" class="block text-xs font-semibold text-slate-700">Alternative text</label><input id="media-{{ $index }}-alt" name="media[{{ $index }}][alt_text]" type="text" maxlength="180" value="{{ old("media.$index.alt_text", $medium?->alt_text) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="media-{{ $index }}-caption" class="block text-xs font-semibold text-slate-700">Caption <span class="font-normal">(optional)</span></label><input id="media-{{ $index }}-caption" name="media[{{ $index }}][caption]" type="text" maxlength="300" value="{{ old("media.$index.caption", $medium?->caption) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <label class="flex items-center gap-2 text-xs font-bold text-slate-700"><input type="hidden" name="media[{{ $index }}][is_cover]" value="0"><input type="checkbox" name="media[{{ $index }}][is_cover]" value="1" @checked(old("media.$index.is_cover", $medium?->is_cover)) class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">Use as cover image</label>
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="button" class="hidden min-h-11 rounded-xl border border-emerald-300 bg-white px-4 py-2.5 text-sm font-bold text-emerald-800 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" data-add-row aria-controls="package-media-rows">Add image</button>
                <span class="text-xs text-slate-500" data-row-count></span>
            </div>
            <p class="sr-only" aria-live="polite" data-repeater-status></p>
            <template data-row-template>
                <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-4" data-repeater-row>
                    <legend class="px-2 text-sm font-bold text-slate-800" data-row-label>Image __POSITION__</legend>
                    <div class="mb-3 flex justify-end"><button type="button" class="min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove image __POSITION__">Remove image</button></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="sm:col-span-2"><label for="media-__INDEX__-url" class="block text-xs font-semibold text-slate-700">Image URL or path</label><input id="media-__INDEX__-url" name="media[__INDEX__][url]" type="text" maxlength="2048" placeholder="https://… or /storage/…" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <div><label for="media-__INDEX__-alt" class="block text-xs font-semibold text-slate-700">Alternative text</label><input id="media-__INDEX__-alt" name="media[__INDEX__][alt_text]" type="text" maxlength="180" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <div><label for="media-__INDEX__-caption" class="block text-xs font-semibold text-slate-700">Caption <span class="font-normal">(optional)</span></label><input id="media-__INDEX__-caption" name="media[__INDEX__][caption]" type="text" maxlength="300" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <label class="flex items-center gap-2 text-xs font-bold text-slate-700"><input type="hidden" name="media[__INDEX__][is_cover]" value="0"><input type="checkbox" name="media[__INDEX__][is_cover]" value="1" class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">Use as cover image</label>
                    </div>
                </fieldset>
            </template>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="package-itinerary-heading">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Step 4</p>
        <h2 id="package-itinerary-heading" class="mt-1 text-xl font-black text-emerald-950">Itinerary</h2>
        <p class="mt-2 text-sm text-slate-600">Add one complete entry for every tour day, numbered from 1 through the selected duration. Up to 90 days are supported.</p>
        <div class="mt-6" data-repeater data-kind="itinerary day" data-max="{{ $maximumDurationDays }}" data-next-index="{{ $nextIndex($itineraryIndices) }}">
            <div id="package-itinerary-rows" class="space-y-4" data-repeater-list>
                @foreach ($itineraryIndices as $position => $index)
                    @php
                        $day = $usingOldItinerary ? null : $existingItinerary->get($index);
                    @endphp
                    <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5" data-repeater-row>
                        <legend class="px-2 text-sm font-bold text-slate-800" data-row-label>Day {{ $position + 1 }}</legend>
                        <div class="mb-3 flex justify-end">
                            <button type="button" class="hidden min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove itinerary day {{ $position + 1 }}">Remove day</button>
                        </div>
                        <div class="grid gap-4 sm:grid-cols-2">
                            <div><label for="day-{{ $index }}-number" class="block text-xs font-semibold text-slate-700">Day number</label><input id="day-{{ $index }}-number" name="itinerary[{{ $index }}][day_number]" type="number" min="1" max="{{ $maximumDurationDays }}" value="{{ old("itinerary.$index.day_number", $day?->day_number ?? $position + 1) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="day-{{ $index }}-title" class="block text-xs font-semibold text-slate-700">Title</label><input id="day-{{ $index }}-title" name="itinerary[{{ $index }}][title]" type="text" maxlength="180" value="{{ old("itinerary.$index.title", $day?->title) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div class="sm:col-span-2"><label for="day-{{ $index }}-description" class="block text-xs font-semibold text-slate-700">Description</label><textarea id="day-{{ $index }}-description" name="itinerary[{{ $index }}][description]" maxlength="3000" rows="3" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old("itinerary.$index.description", $day?->description) }}</textarea></div>
                            <div class="sm:col-span-2"><label for="day-{{ $index }}-activities" class="block text-xs font-semibold text-slate-700">Activities <span class="font-normal">(one per line)</span></label><textarea id="day-{{ $index }}-activities" name="itinerary[{{ $index }}][activities]" maxlength="1000" rows="3" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600">{{ old("itinerary.$index.activities", $day ? implode("\n", $day->activities ?? []) : '') }}</textarea></div>
                            <div><label for="day-{{ $index }}-meals" class="block text-xs font-semibold text-slate-700">Meals <span class="font-normal">(optional)</span></label><input id="day-{{ $index }}-meals" name="itinerary[{{ $index }}][meals]" type="text" maxlength="300" value="{{ old("itinerary.$index.meals", $day?->meals) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <div><label for="day-{{ $index }}-overnight" class="block text-xs font-semibold text-slate-700">Overnight location <span class="font-normal">(optional)</span></label><input id="day-{{ $index }}-overnight" name="itinerary[{{ $index }}][overnight_location]" type="text" maxlength="300" value="{{ old("itinerary.$index.overnight_location", $day?->overnight_location) }}" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        </div>
                    </fieldset>
                @endforeach
            </div>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="button" class="hidden min-h-11 rounded-xl border border-emerald-300 bg-white px-4 py-2.5 text-sm font-bold text-emerald-800 hover:bg-emerald-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" data-add-row aria-controls="package-itinerary-rows">Add itinerary day</button>
                <button type="button" class="hidden min-h-11 rounded-xl border border-slate-300 bg-white px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" data-fill-duration aria-controls="package-itinerary-rows">Add days to match duration</button>
                <span class="text-xs text-slate-500" data-row-count></span>
            </div>
            <p class="sr-only" aria-live="polite" data-repeater-status></p>
            <template data-row-template>
                <fieldset class="rounded-2xl border border-slate-200 bg-slate-50 p-4 sm:p-5" data-repeater-row>
                    <legend class="px-2 text-sm font-bold text-slate-800" data-row-label>Day __POSITION__</legend>
                    <div class="mb-3 flex justify-end"><button type="button" class="min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove itinerary day __POSITION__">Remove day</button></div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div><label for="day-__INDEX__-number" class="block text-xs font-semibold text-slate-700">Day number</label><input id="day-__INDEX__-number" name="itinerary[__INDEX__][day_number]" type="number" min="1" max="{{ $maximumDurationDays }}" value="__DAY_NUMBER__" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <div><label for="day-__INDEX__-title" class="block text-xs font-semibold text-slate-700">Title</label><input id="day-__INDEX__-title" name="itinerary[__INDEX__][title]" type="text" maxlength="180" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <div class="sm:col-span-2"><label for="day-__INDEX__-description" class="block text-xs font-semibold text-slate-700">Description</label><textarea id="day-__INDEX__-description" name="itinerary[__INDEX__][description]" maxlength="3000" rows="3" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></textarea></div>
                        <div class="sm:col-span-2"><label for="day-__INDEX__-activities" class="block text-xs font-semibold text-slate-700">Activities <span class="font-normal">(one per line)</span></label><textarea id="day-__INDEX__-activities" name="itinerary[__INDEX__][activities]" maxlength="1000" rows="3" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></textarea></div>
                        <div><label for="day-__INDEX__-meals" class="block text-xs font-semibold text-slate-700">Meals <span class="font-normal">(optional)</span></label><input id="day-__INDEX__-meals" name="itinerary[__INDEX__][meals]" type="text" maxlength="300" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <div><label for="day-__INDEX__-overnight" class="block text-xs font-semibold text-slate-700">Overnight location <span class="font-normal">(optional)</span></label><input id="day-__INDEX__-overnight" name="itinerary[__INDEX__][overnight_location]" type="text" maxlength="300" class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                    </div>
                </fieldset>
            </template>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" aria-labelledby="package-items-heading">
        <p class="text-xs font-bold uppercase tracking-[0.14em] text-emerald-700">Step 5</p>
        <h2 id="package-items-heading" class="mt-1 text-xl font-black text-emerald-950">Inclusions and exclusions</h2>
        <div class="mt-6 grid gap-6 md:grid-cols-2">
            <fieldset class="rounded-2xl border border-emerald-200 bg-emerald-50 p-4" data-repeater data-kind="inclusion" data-max="50" data-next-index="{{ $nextIndex($inclusionIndices) }}">
                <legend class="px-2 font-bold text-emerald-950">Included</legend>
                <p class="mb-4 text-xs text-emerald-800">At least one inclusion is required.</p>
                <div id="package-inclusion-rows" class="space-y-3" data-repeater-list>
                    @foreach ($inclusionIndices as $position => $index)
                        <div class="flex items-start gap-2" data-repeater-row>
                            <div class="flex-1"><label for="inclusion-{{ $index }}" class="sr-only">Inclusion {{ $position + 1 }}</label><input id="inclusion-{{ $index }}" name="inclusions[{{ $index }}]" type="text" maxlength="300" value="{{ old("inclusions.$index", $usingOldInclusions ? null : $existingInclusions->get($index)) }}" placeholder="Included item {{ $position + 1 }}" class="block w-full rounded-xl border-emerald-200 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                            <button type="button" class="hidden min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove inclusion {{ $position + 1 }}">Remove</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="button" class="hidden min-h-11 rounded-xl border border-emerald-300 bg-white px-4 py-2.5 text-sm font-bold text-emerald-800 hover:bg-emerald-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600" data-add-row aria-controls="package-inclusion-rows">Add inclusion</button>
                    <span class="text-xs text-emerald-800" data-row-count></span>
                </div>
                <p class="sr-only" aria-live="polite" data-repeater-status></p>
                <template data-row-template>
                    <div class="flex items-start gap-2" data-repeater-row>
                        <div class="flex-1"><label for="inclusion-__INDEX__" class="sr-only">Inclusion __POSITION__</label><input id="inclusion-__INDEX__" name="inclusions[__INDEX__]" type="text" maxlength="300" placeholder="Included item __POSITION__" class="block w-full rounded-xl border-emerald-200 text-sm shadow-sm focus:border-emerald-600 focus:ring-emerald-600"></div>
                        <button type="button" class="min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove inclusion __POSITION__">Remove</button>
                    </div>
                </template>
            </fieldset>

            <fieldset class="rounded-2xl border border-amber-200 bg-amber-50 p-4" data-repeater data-kind="exclusion" data-max="50" data-next-index="{{ $nextIndex($exclusionIndices) }}">
                <legend class="px-2 font-bold text-amber-950">Not included</legend>
                <p class="mb-4 text-xs text-amber-800">Use clear wording so customers can plan accurately.</p>
                <div id="package-exclusion-rows" class="space-y-3" data-repeater-list>
                    @foreach ($exclusionIndices as $position => $index)
                        <div class="flex items-start gap-2" data-repeater-row>
                            <div class="flex-1"><label for="exclusion-{{ $index }}" class="sr-only">Exclusion {{ $position + 1 }}</label><input id="exclusion-{{ $index }}" name="exclusions[{{ $index }}]" type="text" maxlength="300" value="{{ old("exclusions.$index", $usingOldExclusions ? null : $existingExclusions->get($index)) }}" placeholder="Excluded item {{ $position + 1 }}" class="block w-full rounded-xl border-amber-200 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500"></div>
                            <button type="button" class="hidden min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove exclusion {{ $position + 1 }}">Remove</button>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="button" class="hidden min-h-11 rounded-xl border border-amber-300 bg-white px-4 py-2.5 text-sm font-bold text-amber-900 hover:bg-amber-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-amber-500" data-add-row aria-controls="package-exclusion-rows">Add exclusion</button>
                    <span class="text-xs text-amber-800" data-row-count></span>
                </div>
                <p class="sr-only" aria-live="polite" data-repeater-status></p>
                <template data-row-template>
                    <div class="flex items-start gap-2" data-repeater-row>
                        <div class="flex-1"><label for="exclusion-__INDEX__" class="sr-only">Exclusion __POSITION__</label><input id="exclusion-__INDEX__" name="exclusions[__INDEX__]" type="text" maxlength="300" placeholder="Excluded item __POSITION__" class="block w-full rounded-xl border-amber-200 text-sm shadow-sm focus:border-amber-500 focus:ring-amber-500"></div>
                        <button type="button" class="min-h-10 rounded-lg border border-rose-200 bg-white px-3 py-2 text-xs font-bold text-rose-700 hover:bg-rose-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-500" data-remove-row aria-label="Remove exclusion __POSITION__">Remove</button>
                    </div>
                </template>
            </fieldset>
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
