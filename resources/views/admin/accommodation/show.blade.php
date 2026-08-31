@php
    use App\Enums\PropertyStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $canPublish = auth()->user()?->can('publish', $property) ?? false;
    $canEdit = auth()->user()?->can('update', $property) ?? false;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Accommodation</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $property->name }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $property->property_type->label() }} · {{ $property->locationLabel() }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($property->isPublishedAt())
                    <a href="{{ route('accommodation.show', $property->slug) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        View on the site
                    </a>
                @endif
                @if ($canEdit)
                    <a href="{{ route('admin.accommodation.edit', $property) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
                        Edit
                    </a>
                @endif
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            @if ($errors->any())
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-900" role="alert">
                    <ul class="list-disc space-y-1 pl-5">
                        @foreach ($errors->all() as $message)
                            <li>{{ $message }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid gap-6 lg:grid-cols-3">
                <section class="space-y-6 lg:col-span-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center gap-3">
                            <span @class([
                                'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                                'bg-slate-100 text-slate-800' => $property->status->tone() === 'slate',
                                'bg-emerald-100 text-emerald-900' => $property->status->tone() === 'emerald',
                                'bg-rose-100 text-rose-900' => $property->status->tone() === 'rose',
                            ])>{{ $property->status->label() }}</span>
                            @if ($property->is_featured)
                                <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">Featured</span>
                            @endif
                        </div>

                        <p class="mt-4 text-sm text-slate-700">{{ $property->summary }}</p>

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Check in / out</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ Str::substr($property->getRawOriginal('check_in_from'), 0, 5) }} /
                                    {{ Str::substr($property->getRawOriginal('check_out_by'), 0, 5) }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Free cancellation</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $property->cancellation_cutoff_hours }} hours</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">First published</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $property->published_at?->timezone($timezone)->format('j M Y') ?? 'Never' }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Created by</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $property->createdBy?->name ?? '—' }}</dd>
                            </div>
                        </dl>

                        @if ($property->internal_notes)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h3 class="text-sm font-bold text-slate-900">Internal notes</h3>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $property->internal_notes }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Rooms and prices</h2>
                        <p class="mt-1 text-sm text-slate-600">
                            A room type is however many identical rooms exist. Availability is counted against that
                            number for every night of a stay.
                        </p>

                        @if ($property->roomTypes->isEmpty())
                            <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                                No rooms yet. Add one below — a property cannot be published without a priced room.
                            </p>
                        @else
                            <div class="mt-5 space-y-5">
                                @foreach ($property->roomTypes as $roomType)
                                    <div class="rounded-2xl border border-slate-200 p-5">
                                        <div class="flex flex-wrap items-start justify-between gap-3">
                                            <div>
                                                <p class="font-bold text-slate-900">
                                                    {{ $roomType->name }}
                                                    @unless ($roomType->is_active)
                                                        <span class="ml-2 rounded-full bg-slate-100 px-2 py-0.5 text-xs font-bold text-slate-700">Not bookable</span>
                                                    @endunless
                                                </p>
                                                <p class="text-xs text-slate-500">
                                                    {{ $roomType->quantity }} {{ Str::plural('room', $roomType->quantity) }} ·
                                                    sleeps {{ $roomType->occupancyLabel() }}
                                                </p>
                                            </div>
                                        </div>

                                        @if ($roomType->rates->isEmpty())
                                            <p class="mt-3 text-sm text-amber-800">No price set — this room cannot be booked.</p>
                                        @else
                                            <ul class="mt-3 space-y-2">
                                                @foreach ($roomType->rates as $rate)
                                                    <li class="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-slate-50 px-4 py-2 text-sm">
                                                        <span>
                                                            <span class="font-bold text-slate-900">{{ $rate->formattedNightlyRate() }}</span>
                                                            <span class="text-slate-600">a night · {{ $rate->seasonLabel() }}</span>
                                                            @if ($rate->minimum_nights > 1)
                                                                <span class="text-slate-600">· minimum {{ $rate->minimum_nights }} nights</span>
                                                            @endif
                                                            @unless ($rate->is_active)
                                                                <span class="ml-2 rounded-full bg-slate-200 px-2 py-0.5 text-xs font-bold text-slate-700">Retired</span>
                                                            @endunless
                                                        </span>
                                                        @if ($rate->is_active && $canPublish)
                                                            <form method="POST" action="{{ route('admin.accommodation.rates.retire', [$property, $rate]) }}">
                                                                @csrf
                                                                <button type="submit" class="text-xs font-bold text-rose-700 hover:underline">Retire</button>
                                                            </form>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        @if ($canEdit)
                                            <details class="mt-4">
                                                <summary class="cursor-pointer text-sm font-bold text-emerald-800">Edit this room</summary>
                                                <form method="POST" action="{{ route('admin.accommodation.rooms.update', [$property, $roomType]) }}" class="mt-3 grid gap-3 sm:grid-cols-4">
                                                    @csrf
                                                    @method('PATCH')
                                                    <div class="sm:col-span-2">
                                                        <label for="rt-name-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Name</label>
                                                        <input id="rt-name-{{ $roomType->getKey() }}" name="name" type="text" required maxlength="160" value="{{ $roomType->name }}"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div>
                                                        <label for="rt-qty-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Rooms</label>
                                                        <input id="rt-qty-{{ $roomType->getKey() }}" name="quantity" type="number" required min="1" max="500" value="{{ $roomType->quantity }}"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div>
                                                        <label for="rt-adults-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Max adults</label>
                                                        <input id="rt-adults-{{ $roomType->getKey() }}" name="max_adults" type="number" required min="1" max="20" value="{{ $roomType->max_adults }}"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div>
                                                        <label for="rt-children-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Max children</label>
                                                        <input id="rt-children-{{ $roomType->getKey() }}" name="max_children" type="number" required min="0" max="20" value="{{ $roomType->max_children }}"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <label class="flex items-center gap-2 sm:col-span-2">
                                                        <input type="hidden" name="is_active" value="0">
                                                        <input type="checkbox" name="is_active" value="1" @checked($roomType->is_active)
                                                               class="rounded border-slate-300 text-emerald-700 focus:ring-emerald-600">
                                                        <span class="text-sm font-semibold">Bookable</span>
                                                    </label>
                                                    <div class="sm:col-span-2">
                                                        <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                                            Save room
                                                        </button>
                                                    </div>
                                                </form>
                                            </details>
                                        @endif

                                        @if ($canPublish)
                                            <details class="mt-3">
                                                <summary class="cursor-pointer text-sm font-bold text-emerald-800">Add a price</summary>
                                                <form method="POST" action="{{ route('admin.accommodation.rates.store', [$property, $roomType]) }}" class="mt-3 grid gap-3 sm:grid-cols-5">
                                                    @csrf
                                                    <div>
                                                        <label for="rate-currency-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Currency</label>
                                                        <select id="rate-currency-{{ $roomType->getKey() }}" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                            @foreach ($currencies as $code)
                                                                <option value="{{ $code }}">{{ $code }}</option>
                                                            @endforeach
                                                        </select>
                                                    </div>
                                                    <div>
                                                        <label for="rate-amount-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Per night</label>
                                                        <input id="rate-amount-{{ $roomType->getKey() }}" name="nightly_rate" type="text" inputmode="numeric" required maxlength="24"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div>
                                                        <label for="rate-from-{{ $roomType->getKey() }}" class="block text-xs font-semibold">From</label>
                                                        <input id="rate-from-{{ $roomType->getKey() }}" name="effective_from" type="date" required
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div>
                                                        <label for="rate-until-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Until</label>
                                                        <input id="rate-until-{{ $roomType->getKey() }}" name="effective_until" type="date"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div>
                                                        <label for="rate-min-{{ $roomType->getKey() }}" class="block text-xs font-semibold">Min nights</label>
                                                        <input id="rate-min-{{ $roomType->getKey() }}" name="minimum_nights" type="number" required min="1" max="60" value="1"
                                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                                    </div>
                                                    <div class="sm:col-span-5">
                                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                                            Add price
                                                        </button>
                                                        <p class="mt-1 text-xs text-slate-500">
                                                            Two active prices in one currency may not cover the same night.
                                                        </p>
                                                    </div>
                                                </form>
                                            </details>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        @if ($canEdit)
                            <details class="mt-6">
                                <summary class="cursor-pointer text-sm font-bold text-emerald-800">Add a room type</summary>
                                <form method="POST" action="{{ route('admin.accommodation.rooms.store', $property) }}" class="mt-3 grid gap-3 sm:grid-cols-4">
                                    @csrf
                                    <div class="sm:col-span-2">
                                        <label for="new-room-name" class="block text-xs font-semibold">Name</label>
                                        <input id="new-room-name" name="name" type="text" required maxlength="160" placeholder="Standard Double"
                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="new-room-qty" class="block text-xs font-semibold">How many rooms</label>
                                        <input id="new-room-qty" name="quantity" type="number" required min="1" max="500" value="1"
                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="new-room-beds" class="block text-xs font-semibold">Beds</label>
                                        <input id="new-room-beds" name="bed_configuration" type="text" maxlength="120" placeholder="One king bed"
                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="new-room-adults" class="block text-xs font-semibold">Max adults</label>
                                        <input id="new-room-adults" name="max_adults" type="number" required min="1" max="20" value="2"
                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div>
                                        <label for="new-room-children" class="block text-xs font-semibold">Max children</label>
                                        <input id="new-room-children" name="max_children" type="number" required min="0" max="20" value="0"
                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div class="sm:col-span-2">
                                        <label for="new-room-description" class="block text-xs font-semibold">Description</label>
                                        <input id="new-room-description" name="description" type="text" maxlength="2000"
                                               class="mt-1 block w-full rounded-xl border-slate-300 text-sm focus:border-emerald-600 focus:ring-emerald-600">
                                    </div>
                                    <div class="sm:col-span-4">
                                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                            Add room type
                                        </button>
                                    </div>
                                </form>
                            </details>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    @if ($canPublish && in_array(PropertyStatus::Published, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.accommodation.publish', $property) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Publish</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Puts it on the public site and opens it for bookings. Needs at least one active room
                                with a price.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Publish
                            </button>
                        </form>
                    @endif

                    @if ($canPublish && $property->status === PropertyStatus::Published)
                        <form method="POST" action="{{ route('admin.accommodation.unpublish', $property) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Take it off the site</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Stops new bookings. Stays already made are unaffected, and republishing keeps the
                                original publication date.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Unpublish
                            </button>
                        </form>
                    @endif

                    @if ($canPublish && in_array(PropertyStatus::Archived, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.accommodation.archive', $property) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Archive</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Retires it for good. Refused while guests are still expected — PISFA would still owe
                                them a room.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Archive
                            </button>
                        </form>
                    @endif

                    @if ($canPublish && $property->status === PropertyStatus::Archived)
                        <form method="POST" action="{{ route('admin.accommodation.restore', $property) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Bring it back</h2>
                            <p class="mt-2 text-sm text-slate-600">Returns it to draft so it can be checked again.</p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Restore as draft
                            </button>
                        </form>
                    @endif

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Guests expected</h2>

                        @if ($upcoming->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">Nobody is booked in at the moment.</p>
                        @else
                            <ul class="mt-4 space-y-3">
                                @foreach ($upcoming as $stay)
                                    <li class="border-b border-slate-100 pb-3 last:border-0 last:pb-0">
                                        <a href="{{ route('admin.accommodation.bookings.show', $stay) }}" class="font-bold text-emerald-800 hover:underline">
                                            {{ $stay->contact_name }}
                                        </a>
                                        <p class="text-xs text-slate-500">
                                            {{ $stay->check_in_date->format('j M') }} — {{ $stay->check_out_date->format('j M') }} ·
                                            {{ $stay->rooms }} &times; {{ $stay->room_type_name_snapshot }} ·
                                            {{ $stay->status->label() }}
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        @endif

                        <a href="{{ route('admin.accommodation.bookings.index', ['property' => $property->slug]) }}"
                           class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                            All stays here
                        </a>
                    </div>
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
