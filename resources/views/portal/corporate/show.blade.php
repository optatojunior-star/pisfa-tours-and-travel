@php
    use App\Enums\GroupBookingStatus;

    $canManifest = auth()->user()?->can('manageManifest', $booking) ?? false;
    $canEdit = auth()->user()?->can('update', $booking) ?? false;
    $canWithdraw = $canEdit && in_array($booking->status, [
        GroupBookingStatus::Enquiry,
        GroupBookingStatus::Quoted,
        GroupBookingStatus::ManifestPending,
    ], true);
    $shortfall = $booking->manifestShortfall();
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Group booking</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $booking->title }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $booking->reference }} · {{ $booking->dateLabel() }}</p>
            </div>
            <a href="{{ route('portal.groups.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All groups
            </a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
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

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <span @class([
                    'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                    'bg-slate-100 text-slate-800' => $booking->status->tone() === 'slate',
                    'bg-amber-100 text-amber-900' => $booking->status->tone() === 'amber',
                    'bg-sky-100 text-sky-900' => $booking->status->tone() === 'sky',
                    'bg-emerald-100 text-emerald-900' => $booking->status->tone() === 'emerald',
                    'bg-rose-100 text-rose-900' => $booking->status->tone() === 'rose',
                ])>{{ $booking->status->label() }}</span>

                @if ($booking->status === GroupBookingStatus::ManifestPending && $shortfall > 0)
                    <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                        We need {{ $shortfall }} more {{ Str::plural('name', $shortfall) }} before the trip can be
                        confirmed. Everybody travelling needs a place booked for them.
                    </p>
                @endif

                @if ($booking->closure_reason)
                    <p class="mt-4 rounded-xl bg-rose-50 p-4 text-sm text-rose-900">
                        <span class="font-bold">Reason:</span> {{ $booking->closure_reason }}
                    </p>
                @endif

                <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Travelling</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $booking->headcount }} people</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">On the list</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $booking->manifestCount() }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">From</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $booking->pickup_location ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">To</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $booking->destination ?? '—' }}</dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Price</dt>
                        <dd class="text-sm font-bold text-slate-900">
                            {{ $booking->formattedQuotedTotal() ?? 'Being worked out' }}
                        </dd>
                    </div>
                    <div class="flex justify-between border-b border-slate-200 py-2">
                        <dt class="text-sm font-semibold text-slate-600">Billed to</dt>
                        <dd class="text-sm font-bold text-slate-900">{{ $booking->account?->name ?? 'You' }}</dd>
                    </div>
                </dl>

                @if ($booking->requirements)
                    <div class="mt-5 rounded-xl bg-slate-50 p-4">
                        <h2 class="text-sm font-bold text-slate-900">What you told us</h2>
                        <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $booking->requirements }}</p>
                    </div>
                @endif
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-black text-slate-900">Who is coming</h2>
                    <p class="text-sm font-semibold text-slate-600">
                        {{ $booking->manifestCount() }} of {{ $booking->headcount }}
                    </p>
                </div>

                @if ($booking->travelers->isEmpty())
                    <p class="mt-4 rounded-xl bg-slate-50 p-4 text-sm text-slate-600">
                        Nobody on the list yet.
                    </p>
                @else
                    <ul class="mt-5 divide-y divide-slate-100">
                        @foreach ($booking->travelers as $traveler)
                            <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                <div>
                                    <p class="font-bold text-slate-900">{{ $traveler->full_name }}</p>
                                    <p class="text-xs text-slate-500">
                                        {{ $traveler->traveler_type->label() }}
                                        @if ($traveler->nationality)
                                            · {{ $traveler->nationality }}
                                        @endif
                                        @if ($traveler->hasSpecialRequirements())
                                            · <span class="font-semibold text-amber-700">has requirements</span>
                                        @endif
                                    </p>
                                </div>
                                @if ($canManifest)
                                    <form method="POST" action="{{ route('portal.groups.travelers.destroy', [$booking, $traveler]) }}">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="text-xs font-bold text-rose-700 hover:underline">Remove</button>
                                    </form>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($canManifest && $shortfall > 0)
                    <form method="POST" action="{{ route('portal.groups.travelers.store', $booking) }}" class="mt-6 grid gap-3 sm:grid-cols-4">
                        @csrf
                        <div class="sm:col-span-2">
                            <label for="full_name" class="block text-sm font-semibold">Full name</label>
                            <input id="full_name" name="full_name" type="text" required minlength="2" maxlength="180"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <div>
                            <label for="traveler_type" class="block text-sm font-semibold">Adult or child</label>
                            <select id="traveler_type" name="traveler_type" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                @foreach ($travelerTypes as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="nationality" class="block text-sm font-semibold">Nationality</label>
                            <input id="nationality" name="nationality" type="text" maxlength="80"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <div class="sm:col-span-2">
                            <label for="identity_document" class="block text-sm font-semibold">
                                ID or passport number <span class="font-normal text-slate-500">(for park permits)</span>
                            </label>
                            <input id="identity_document" name="identity_document" type="text" maxlength="60"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <div>
                            <label for="dietary_requirements" class="block text-sm font-semibold">Dietary needs</label>
                            <input id="dietary_requirements" name="dietary_requirements" type="text" maxlength="255"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <div>
                            <label for="accessibility_needs" class="block text-sm font-semibold">Access needs</label>
                            <input id="accessibility_needs" name="accessibility_needs" type="text" maxlength="255"
                                   class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                        </div>
                        <div class="sm:col-span-4">
                            <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Add to the list
                            </button>
                        </div>
                    </form>
                @elseif ($canManifest)
                    <p class="mt-5 rounded-xl bg-emerald-50 p-4 text-sm text-emerald-900">
                        The list is complete. Remove somebody first if you need to swap a name.
                    </p>
                @endif
            </div>

            @if ($canWithdraw)
                <form method="POST" action="{{ route('portal.groups.cancel', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                    @csrf
                    <h2 class="text-lg font-black text-slate-900">Withdraw this enquiry</h2>
                    <p class="mt-2 text-sm text-slate-600">
                        You can withdraw before the trip is confirmed. After that, please talk to us — a confirmed
                        trip is our commitment as much as yours.
                    </p>
                    <div class="mt-4">
                        <label for="cancel-reason" class="block text-sm font-semibold">Why</label>
                        <input id="cancel-reason" name="reason" type="text" required minlength="5" maxlength="255"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <button type="submit" class="mt-4 inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                        Withdraw
                    </button>
                </form>
            @endif
        </div>
    </div>
</x-app-layout>
