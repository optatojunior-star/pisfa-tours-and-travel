@php
    use App\Enums\VehicleCatalogueStatus;
    use App\Enums\VehicleOperationalStatus;
    use App\Support\VehicleSpecification;

    $editing = $vehicle->exists;
    $currentYear = (int) now()->year;
@endphp

{{--
    Adding a vehicle used to mean typing its specification into free-text boxes
    under a hint reading "Lowercase key, for example suv or safari_van". That
    asked whoever was publishing to know a vocabulary nobody had written down,
    and the guesses disagreed — the showroom filled with "Diesel" while the hire
    fleet filled with "diesel", and no filter could match both.

    Every closed-set field is now a dropdown fed from App\Enums, so there is one
    spelling of each answer and no way to invent a new one by accident.
--}}
<form method="POST"
      action="{{ $editing ? route('admin.vehicles.update', $vehicle) : route('admin.vehicles.store') }}"
      enctype="multipart/form-data" class="space-y-6">
    @csrf
    @if ($editing) @method('PATCH') @endif

    @if ($errors->any())
        <div class="rounded-xl border border-rose-300 bg-rose-50 p-4 text-sm text-rose-900" role="alert" tabindex="-1">
            <p class="font-bold">The vehicle was not saved. {{ $errors->count() }} {{ Str::plural('thing', $errors->count()) }} to fix:</p>
            <ul class="mt-2 list-disc space-y-1 pl-5">
                @foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach
            </ul>
        </div>
    @endif

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="vehicle-identity-heading">
        <h2 id="vehicle-identity-heading" class="text-xl font-black text-emerald-950">Identity</h2>
        <p class="mt-1 text-sm text-slate-600">What the vehicle is and how it is registered.</p>

        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="vehicle-make" class="block text-sm font-semibold text-ink-800">Make</label>
                <input id="vehicle-make" name="make" required maxlength="100" list="vehicle-makes"
                       value="{{ old('make', $vehicle->make) }}" placeholder="Toyota"
                       @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('make'), 'border-rose-400 bg-rose-50' => $errors->has('make')])>
                <datalist id="vehicle-makes">
                    @foreach (['Toyota', 'Nissan', 'Mitsubishi', 'Isuzu', 'Subaru', 'Honda', 'Mazda', 'Suzuki', 'Land Rover', 'Mercedes-Benz', 'BMW', 'Volkswagen', 'Hyundai', 'Kia', 'Ford'] as $make)
                        <option value="{{ $make }}"></option>
                    @endforeach
                </datalist>
                <x-input-error :messages="$errors->get('make')" class="mt-1" />
            </div>

            <div>
                <label for="vehicle-model" class="block text-sm font-semibold text-ink-800">Model</label>
                <input id="vehicle-model" name="model" required maxlength="100"
                       value="{{ old('model', $vehicle->model) }}" placeholder="Land Cruiser Prado"
                       @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('model'), 'border-rose-400 bg-rose-50' => $errors->has('model')])>
                <x-input-error :messages="$errors->get('model')" class="mt-1" />
            </div>

            <div>
                <label for="vehicle-year" class="block text-sm font-semibold text-ink-800">Year of manufacture</label>
                <select id="vehicle-year" name="year" required
                        @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('year'), 'border-rose-400 bg-rose-50' => $errors->has('year')])>
                    <option value="">Select year…</option>
                    @for ($year = $currentYear + 1; $year >= 1980; $year--)
                        <option value="{{ $year }}" @selected((int) old('year', $vehicle->year) === $year)>{{ $year }}</option>
                    @endfor
                </select>
                <x-input-error :messages="$errors->get('year')" class="mt-1" />
            </div>

            <div>
                <label for="vehicle-plate" class="block text-sm font-semibold text-ink-800">Registration plate</label>
                <input id="vehicle-plate" name="registration_plate" required maxlength="32" autocomplete="off"
                       value="{{ old('registration_plate', $vehicle->registration_plate) }}" placeholder="UAX 123B"
                       @class(['mt-1 block w-full rounded-xl uppercase', 'border-slate-300' => ! $errors->has('registration_plate'), 'border-rose-400 bg-rose-50' => $errors->has('registration_plate')])>
                <x-input-error :messages="$errors->get('registration_plate')" class="mt-1" />
            </div>

            <div>
                <label for="vehicle-color" class="block text-sm font-semibold text-ink-800">Colour</label>
                <input id="vehicle-color" name="color" required maxlength="60" list="vehicle-colours"
                       value="{{ old('color', $vehicle->color) }}" placeholder="Silver"
                       @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('color'), 'border-rose-400 bg-rose-50' => $errors->has('color')])>
                <datalist id="vehicle-colours">
                    @foreach (['White', 'Silver', 'Grey', 'Black', 'Blue', 'Red', 'Green', 'Beige', 'Gold', 'Maroon', 'Brown', 'Pearl white'] as $colour)
                        <option value="{{ $colour }}"></option>
                    @endforeach
                </datalist>
                <x-input-error :messages="$errors->get('color')" class="mt-1" />
            </div>

            <x-spec-select
                id="vehicle-condition" name="condition" label="Condition" required
                :options="VehicleSpecification::conditions()"
                :current="$vehicle->condition"
                placeholder="Select condition…" />
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="vehicle-spec-heading">
        <h2 id="vehicle-spec-heading" class="text-xl font-black text-emerald-950">Specification</h2>
        <p class="mt-1 text-sm text-slate-600">These are the details customers filter and compare on, so pick from the lists rather than describing them in the summary.</p>

        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <x-spec-select
                id="vehicle-type" name="vehicle_type" label="Body type" required
                :options="VehicleSpecification::bodyTypes()"
                :current="$vehicle->vehicle_type"
                placeholder="Select body type…" />

            <x-spec-select
                id="vehicle-fuel" name="fuel_type" label="Fuel" required
                :options="VehicleSpecification::fuelTypes()"
                :current="$vehicle->fuel_type"
                placeholder="Select fuel…" />

            <x-spec-select
                id="vehicle-engine" name="engine_cc" label="Engine size"
                :options="VehicleSpecification::engineCapacities()"
                :current="$vehicle->engine_cc"
                placeholder="Not stated"
                help="Leave as Not stated for an electric vehicle." />

            <x-spec-select
                id="vehicle-transmission" name="transmission" label="Transmission" required
                :options="VehicleSpecification::transmissions()"
                :current="$vehicle->transmission"
                placeholder="Select transmission…" />

            <x-spec-select
                id="vehicle-drive" name="drive_type" label="Drive"
                :options="VehicleSpecification::driveTypes()"
                :current="$vehicle->drive_type"
                placeholder="Not stated"
                help="Worth filling in: 2WD or 4WD decides whether a customer can use it upcountry in the wet season." />

            <div>
                <label for="vehicle-seats" class="block text-sm font-semibold text-ink-800">Seats</label>
                <select id="vehicle-seats" name="seating_capacity" required
                        @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('seating_capacity'), 'border-rose-400 bg-rose-50' => $errors->has('seating_capacity')])>
                    <option value="">Select seats…</option>
                    @foreach ([2, 4, 5, 7, 8, 9, 11, 14, 15, 18, 22, 26, 29, 33, 45, 51, 62] as $seats)
                        <option value="{{ $seats }}" @selected((int) old('seating_capacity', $vehicle->seating_capacity) === $seats)>{{ $seats }} seats</option>
                    @endforeach
                </select>
                <x-input-error :messages="$errors->get('seating_capacity')" class="mt-1" />
            </div>

            <div>
                <label for="vehicle-luggage" class="block text-sm font-semibold text-ink-800">Luggage capacity</label>
                <input id="vehicle-luggage" name="luggage_capacity" type="number" required min="0" max="100"
                       value="{{ old('luggage_capacity', $vehicle->luggage_capacity ?? 0) }}"
                       @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('luggage_capacity'), 'border-rose-400 bg-rose-50' => $errors->has('luggage_capacity')])>
                <p class="mt-1 text-xs text-slate-500">Large suitcases it will take.</p>
                <x-input-error :messages="$errors->get('luggage_capacity')" class="mt-1" />
            </div>

            <div>
                <label for="vehicle-slug" class="block text-sm font-semibold text-ink-800">
                    Public web address <span class="font-normal text-slate-500">(optional)</span>
                </label>
                <input id="vehicle-slug" name="slug" maxlength="200" pattern="[a-z0-9]+(?:-[a-z0-9]+)*"
                       value="{{ old('slug', $vehicle->slug) }}"
                       @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('slug'), 'border-rose-400 bg-rose-50' => $errors->has('slug')])>
                <p class="mt-1 text-xs text-slate-500">Leave blank and we build it from the make, model and year.</p>
                <x-input-error :messages="$errors->get('slug')" class="mt-1" />
            </div>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="vehicle-status-heading">
        <h2 id="vehicle-status-heading" class="text-xl font-black text-emerald-950">Availability</h2>

        <div class="mt-6 grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
            <div>
                <label for="catalogue-status" class="block text-sm font-semibold text-ink-800">Catalogue status</label>
                @if ($editing)
                    <select id="catalogue-status" name="catalogue_status" required
                            @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('catalogue_status'), 'border-rose-400 bg-rose-50' => $errors->has('catalogue_status')])>
                        @foreach (VehicleCatalogueStatus::cases() as $case)
                            <option value="{{ $case->value }}" @selected(old('catalogue_status', $vehicle->catalogue_status?->value) === $case->value)>{{ $case->label() }}</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Publishing needs a photograph and a current price — the vehicle page lists what is still outstanding.</p>
                @else
                    <input type="hidden" name="catalogue_status" value="draft">
                    <p class="mt-1 rounded-xl bg-slate-50 px-3 py-2.5 text-sm font-semibold text-slate-700">Draft</p>
                    <p class="mt-1 text-xs text-slate-500">New vehicles always start as drafts. You add the price next, then publish.</p>
                @endif
                <x-input-error :messages="$errors->get('catalogue_status')" class="mt-1" />
            </div>

            <div>
                <label for="operational-status" class="block text-sm font-semibold text-ink-800">Operational status</label>
                <select id="operational-status" name="operational_status" required
                        class="mt-1 block w-full rounded-xl border-slate-300">
                    @foreach (VehicleOperationalStatus::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('operational_status', $vehicle->operational_status?->value ?? 'available') === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-slate-500">Anything but Available hides it from public booking.</p>
                <x-input-error :messages="$errors->get('operational_status')" class="mt-1" />
            </div>

            <label class="flex items-center gap-3 self-end rounded-xl bg-slate-50 p-3 text-sm font-semibold">
                <input type="hidden" name="is_featured" value="0">
                <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $vehicle->is_featured))
                       class="rounded border-slate-300 text-emerald-700">
                Feature in catalogue
            </label>
        </div>
    </section>

    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="vehicle-copy-heading">
        <h2 id="vehicle-copy-heading" class="text-xl font-black text-emerald-950">Public description</h2>
        <div class="mt-6">
            <label for="vehicle-summary" class="block text-sm font-semibold text-ink-800">Summary</label>
            <textarea id="vehicle-summary" name="summary" required maxlength="500" rows="3"
                      @class(['mt-1 block w-full rounded-xl', 'border-slate-300' => ! $errors->has('summary'), 'border-rose-400 bg-rose-50' => $errors->has('summary')])>{{ old('summary', $vehicle->summary) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">The couple of lines a customer reads on the catalogue card. The specification above is shown separately, so use this for what makes the vehicle worth choosing.</p>
            <x-input-error :messages="$errors->get('summary')" class="mt-1" />
        </div>
    </section>

    {{--
        Photographs are uploaded, not linked.

        This used to be a repeater of URL boxes, which only worked if the picture
        was already hosted somewhere else — and left the catalogue depending on
        somebody else's server staying up. A photograph taken on a phone had
        nowhere to go at all.
    --}}
    <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8" aria-labelledby="vehicle-media-heading">
        <h2 id="vehicle-media-heading" class="text-xl font-black text-emerald-950">Photographs</h2>
        <div class="mt-6">
            <x-image-upload
                name="images"
                label="Vehicle photographs"
                help="The first photograph becomes the catalogue cover. Publishing needs at least one."
                :existing="$editing ? $vehicle->media : null"
                :delete-route="$editing ? fn ($image) => route('admin.vehicles.media.destroy', [$vehicle, $image]) : null" />
        </div>
    </section>

    <div class="flex flex-col-reverse gap-3 sm:flex-row sm:justify-end">
        <a href="{{ $editing ? route('admin.vehicles.show', $vehicle) : route('admin.vehicles.index') }}"
           class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Cancel</a>
        <button type="submit" class="min-h-11 rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white">
            {{ $editing ? 'Save vehicle' : 'Create draft vehicle' }}
        </button>
    </div>
</form>
