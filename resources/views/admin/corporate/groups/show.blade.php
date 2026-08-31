@php
    use App\Enums\GroupBookingStatus;
    use App\Support\Money;

    $canTransition = auth()->user()?->can('transition', $booking) ?? false;
    $shortfall = $booking->manifestShortfall();
    $overLimit = $position !== null
        && $booking->quoted_total_minor !== null
        && strtoupper($booking->currency) === strtoupper($position['currency'])
        && $position['available_minor'] < $booking->quoted_total_minor;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Group booking</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $booking->title }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $booking->reference }} · {{ $booking->dateLabel() }}</p>
            </div>
            <a href="{{ route('admin.corporate.groups.index') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                All groups
            </a>
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
                        <span @class([
                            'inline-flex rounded-full px-3 py-1 text-xs font-bold',
                            'bg-slate-100 text-slate-800' => $booking->status->tone() === 'slate',
                            'bg-amber-100 text-amber-900' => $booking->status->tone() === 'amber',
                            'bg-sky-100 text-sky-900' => $booking->status->tone() === 'sky',
                            'bg-emerald-100 text-emerald-900' => $booking->status->tone() === 'emerald',
                            'bg-rose-100 text-rose-900' => $booking->status->tone() === 'rose',
                        ])>{{ $booking->status->label() }}</span>

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Organiser</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->organiser?->name ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Billed to</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    @if ($booking->account)
                                        <a href="{{ route('admin.corporate.show', $booking->account) }}" class="text-emerald-800 hover:underline">
                                            {{ $booking->account->name }}
                                        </a>
                                    @else
                                        The organiser personally
                                    @endif
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Headcount</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->headcount }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">On the list</dt>
                                <dd @class([
                                    'text-sm font-bold',
                                    'text-emerald-800' => $shortfall === 0,
                                    'text-amber-800' => $shortfall > 0,
                                ])>{{ $booking->manifestCount() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">From</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->pickup_location ?? '—' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">To</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $booking->destination ?? '—' }}</dd>
                            </div>
                        </dl>

                        @if ($booking->requirements)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h2 class="text-sm font-bold text-slate-900">What the organiser asked for</h2>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $booking->requirements }}</p>
                            </div>
                        @endif

                        @if ($booking->closure_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Closed:</span> {{ $booking->closure_reason }}
                            </p>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <h2 class="text-lg font-black text-slate-900">Traveller list</h2>
                            <p class="text-sm font-semibold text-slate-600">
                                {{ $booking->manifestCount() }} of {{ $booking->headcount }}
                            </p>
                        </div>

                        @if ($booking->travelers->isEmpty())
                            <p class="mt-4 rounded-xl bg-amber-50 p-4 text-sm text-amber-900">
                                Nobody on the list. The group cannot be confirmed until every seat has a name.
                            </p>
                        @else
                            <div class="mt-5 overflow-x-auto">
                                <table class="min-w-full divide-y divide-slate-200 text-sm">
                                    <caption class="sr-only">Travellers on this group</caption>
                                    <thead class="bg-slate-50">
                                        <tr>
                                            <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Name</th>
                                            <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Type</th>
                                            <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Nationality</th>
                                            <th scope="col" class="px-4 py-3 text-left font-bold text-slate-700">Requirements</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100">
                                        @foreach ($booking->travelers as $traveler)
                                            <tr>
                                                <td class="px-4 py-3 font-semibold text-slate-900">{{ $traveler->full_name }}</td>
                                                <td class="px-4 py-3 text-slate-700">{{ $traveler->traveler_type->label() }}</td>
                                                <td class="px-4 py-3 text-slate-700">{{ $traveler->nationality ?? '—' }}</td>
                                                <td class="px-4 py-3 text-slate-700">
                                                    {{ collect([$traveler->dietary_requirements, $traveler->accessibility_needs])->filter()->join(' · ') ?: '—' }}
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    @if ($position !== null)
                        <div @class([
                            'rounded-3xl border p-6 shadow-sm',
                            'border-rose-200 bg-rose-50' => $overLimit,
                            'border-slate-200 bg-white' => ! $overLimit,
                        ])>
                            <h2 class="text-lg font-black text-slate-900">Account credit</h2>
                            <p class="mt-2 text-sm text-slate-700">
                                {{ Money::format($position['available_minor'], $position['currency']) }} available of
                                {{ Money::format($position['limit_minor'], $position['currency']) }}.
                            </p>
                            @if ($overLimit)
                                <p class="mt-2 text-sm font-bold text-rose-800">
                                    This group is {{ $booking->formattedQuotedTotal() }} and would take the account
                                    past its limit. Confirming will be refused.
                                </p>
                            @endif
                        </div>
                    @endif

                    @if ($canTransition && $booking->status->isOpen())
                        <form method="POST" action="{{ route('admin.corporate.groups.price', $booking) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Price it</h2>
                            <div>
                                <label for="quoted_total" class="block text-sm font-semibold">Total ({{ $booking->currency }})</label>
                                <input id="quoted_total" name="quoted_total" type="text" inputmode="numeric" required maxlength="24"
                                       value="{{ $booking->quoted_total_minor !== null ? \App\Support\Money::forInput($booking->quoted_total_minor, $booking->currency) : '' }}"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                                Save the price
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(GroupBookingStatus::Quoted, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.groups.quote', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Send the quote</h2>
                            <p class="mt-2 text-sm text-slate-600">Tells the organiser the price.</p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Quote it
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(GroupBookingStatus::ManifestPending, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.groups.manifest', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Ask for the list</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                The organiser is asked for all {{ $booking->headcount }} names.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Request the manifest
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(GroupBookingStatus::Confirmed, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.groups.confirm', $booking) }}" class="rounded-3xl border border-emerald-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Confirm</h2>
                            @if ($shortfall > 0)
                                <p class="mt-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900">
                                    {{ $shortfall }} {{ Str::plural('name', $shortfall) }} still missing. Confirming
                                    will be refused until every seat has somebody in it.
                                </p>
                            @else
                                <p class="mt-2 text-sm text-slate-600">
                                    Every seat has a name. Credit is re-checked as this runs.
                                </p>
                            @endif
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Confirm the group
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(GroupBookingStatus::InProgress, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.groups.start', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">They are on their way</h2>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Mark under way
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(GroupBookingStatus::Completed, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.groups.complete', $booking) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Finished</h2>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Mark completed
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(GroupBookingStatus::Cancelled, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.corporate.groups.cancel', $booking) }}" class="space-y-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Cancel</h2>
                            <div>
                                <label for="cancel-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="cancel-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Cancel the group
                            </button>
                        </form>
                    @endif

                    @if ($nextStatuses === [])
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Closed</h2>
                            <p class="mt-2 text-sm text-slate-600">This group has reached the end of its life.</p>
                        </div>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
