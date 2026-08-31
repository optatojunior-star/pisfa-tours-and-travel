@php
    use App\Enums\PropertyType;

    /** @var \App\Models\Property|null $property */
    $value = static fn (string $field, mixed $fallback = null) => old($field, $property?->{$field} ?? $fallback);
@endphp

@if ($errors->any())
    <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
        <p class="font-bold">Please check the form.</p>
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach ($errors->all() as $message)
                <li>{{ $message }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid gap-6 lg:grid-cols-3">
    <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm lg:col-span-2">
        <h2 class="text-lg font-black text-slate-900">The property</h2>

        <div>
            <label for="name" class="block text-sm font-semibold">Name</label>
            <input id="name" name="name" type="text" required minlength="3" maxlength="200"
                   value="{{ $value('name') }}"
                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            @if ($property?->published_at)
                <p class="mt-1 text-xs text-slate-500">
                    The web address is frozen at <span class="font-mono">{{ $property->slug }}</span> — renaming would
                    break every link already shared.
                </p>
            @endif
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label for="property_type" class="block text-sm font-semibold">Type</label>
                <select id="property_type" name="property_type" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    @foreach (PropertyType::cases() as $case)
                        <option value="{{ $case->value }}" @selected(old('property_type', $property?->property_type?->value) === $case->value)>{{ $case->label() }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="region" class="block text-sm font-semibold">Region</label>
                <input id="region" name="region" type="text" required maxlength="120" value="{{ $value('region') }}"
                       placeholder="Western Uganda"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
            <div>
                <label for="district" class="block text-sm font-semibold">District</label>
                <input id="district" name="district" type="text" maxlength="120" value="{{ $value('district') }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
            </div>
        </div>

        <div>
            <label for="address" class="block text-sm font-semibold">Address</label>
            <input id="address" name="address" type="text" maxlength="500" value="{{ $value('address') }}"
                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
        </div>

        <div>
            <label for="summary" class="block text-sm font-semibold">Summary</label>
            <textarea id="summary" name="summary" rows="2" required minlength="20" maxlength="400"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ $value('summary') }}</textarea>
            <p class="mt-1 text-xs text-slate-500">The line guests read first, on the catalogue card.</p>
        </div>

        <div>
            <label for="description" class="block text-sm font-semibold">Description</label>
            <textarea id="description" name="description" rows="8" required minlength="50" maxlength="8000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ $value('description') }}</textarea>
        </div>

        <div>
            <label for="directions" class="block text-sm font-semibold">Getting there</label>
            <textarea id="directions" name="directions" rows="3" maxlength="2000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ $value('directions') }}</textarea>
        </div>

        <div>
            <label for="internal_notes" class="block text-sm font-semibold">Internal notes</label>
            <textarea id="internal_notes" name="internal_notes" rows="3" maxlength="5000"
                      class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('internal_notes', $property?->internal_notes) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Never shown to guests — commission, who to call, what to watch for.</p>
        </div>
    </section>

    <aside class="space-y-6 lg:col-span-1">
        <section class="space-y-5 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
            <h2 class="text-lg font-black text-slate-900">House rules</h2>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label for="check_in_from" class="block text-sm font-semibold">Check in from</label>
                    <input id="check_in_from" name="check_in_from" type="time" required
                           value="{{ old('check_in_from', Str::substr($property?->getRawOriginal('check_in_from') ?? '14:00:00', 0, 5)) }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                </div>
                <div>
                    <label for="check_out_by" class="block text-sm font-semibold">Check out by</label>
                    <input id="check_out_by" name="check_out_by" type="time" required
                           value="{{ old('check_out_by', Str::substr($property?->getRawOriginal('check_out_by') ?? '10:00:00', 0, 5)) }}"
                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                </div>
            </div>

            <div>
                <label for="cancellation_cutoff_hours" class="block text-sm font-semibold">Free cancellation (hours)</label>
                <input id="cancellation_cutoff_hours" name="cancellation_cutoff_hours" type="number" required
                       min="0" max="2160" value="{{ $value('cancellation_cutoff_hours', 48) }}"
                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                <p class="mt-1 text-xs text-slate-500">
                    How long before arrival a guest may still cancel themselves. Zero means never.
                </p>
            </div>

            <label class="flex items-start gap-3">
                <input type="hidden" name="is_featured" value="0">
                <input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $property?->is_featured ?? false))
                       class="mt-1 rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                <span>
                    <span class="block text-sm font-semibold">Feature it</span>
                    <span class="block text-xs text-slate-500">Pinned to the top of the catalogue once published.</span>
                </span>
            </label>
        </section>
    </aside>
</div>
