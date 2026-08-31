@php
    use App\Enums\ListingStatus;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $openEnquiries = $enquiries->filter(fn ($enquiry) => $enquiry->status->isOpen());
    $canSell = auth()->user()?->can('sell', $listing) ?? false;
    $canTransition = auth()->user()?->can('transition', $listing) ?? false;
    $canEdit = auth()->user()?->can('update', $listing) ?? false;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Showroom</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">{{ $listing->title }}</h1>
                <p class="mt-1 text-sm text-slate-600">{{ $listing->reference }}</p>
            </div>
            <div class="flex flex-wrap gap-2">
                @if ($listing->status->isPubliclyVisible())
                    <a href="{{ route('showroom.show', $listing->slug) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700 hover:bg-slate-50">
                        View in showroom
                    </a>
                @endif
                @if ($canEdit)
                    <a href="{{ route('admin.showroom.edit', $listing) }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white hover:bg-emerald-800">
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
                                'bg-slate-100 text-slate-800' => $listing->status->tone() === 'slate',
                                'bg-emerald-100 text-emerald-900' => $listing->status->tone() === 'emerald',
                                'bg-amber-100 text-amber-900' => $listing->status->tone() === 'amber',
                                'bg-sky-100 text-sky-900' => $listing->status->tone() === 'sky',
                                'bg-rose-100 text-rose-900' => $listing->status->tone() === 'rose',
                            ])>{{ $listing->status->label() }}</span>
                            @if ($listing->is_featured)
                                <span class="inline-flex rounded-full bg-amber-100 px-3 py-1 text-xs font-bold text-amber-900">Featured</span>
                            @endif
                        </div>

                        <dl class="mt-5 grid gap-x-8 gap-y-3 sm:grid-cols-2">
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Asking price</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $listing->formattedAskingPrice() }}</dd>
                            </div>
                            @if ($listing->formattedSoldPrice())
                                <div class="flex justify-between border-b border-slate-200 py-2">
                                    <dt class="text-sm font-semibold text-slate-600">Sold for</dt>
                                    <dd class="text-sm font-bold text-sky-800">{{ $listing->formattedSoldPrice() }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Specification</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $listing->specSummary() }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Days listed</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $listing->daysListed() ?? 'Not yet listed' }}</dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Fleet vehicle</dt>
                                <dd class="text-sm font-bold text-slate-900">
                                    {{ $listing->vehicle?->registration_plate ?? 'Not a fleet vehicle' }}
                                </dd>
                            </div>
                            <div class="flex justify-between border-b border-slate-200 py-2">
                                <dt class="text-sm font-semibold text-slate-600">Created by</dt>
                                <dd class="text-sm font-bold text-slate-900">{{ $listing->createdBy?->name ?? '—' }}</dd>
                            </div>
                        </dl>

                        @if ($listing->closure_reason)
                            <p class="mt-4 rounded-xl bg-rose-50 p-3 text-sm text-rose-900">
                                <span class="font-bold">Closure reason:</span> {{ $listing->closure_reason }}
                            </p>
                        @endif

                        @if ($listing->internal_notes)
                            <div class="mt-5 rounded-xl bg-slate-50 p-4">
                                <h3 class="text-sm font-bold text-slate-900">Internal notes</h3>
                                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $listing->internal_notes }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h2 class="text-lg font-black text-slate-900">Enquiries</h2>

                        @if ($enquiries->isEmpty())
                            <p class="mt-3 text-sm text-slate-600">Nobody has enquired about this vehicle yet.</p>
                        @else
                            <ul class="mt-4 divide-y divide-slate-100">
                                @foreach ($enquiries as $enquiry)
                                    <li class="flex flex-wrap items-center justify-between gap-3 py-3">
                                        <div>
                                            <a href="{{ route('admin.showroom.enquiries.show', $enquiry) }}" class="font-bold text-emerald-800 hover:underline">
                                                {{ $enquiry->contact_name }}
                                            </a>
                                            <p class="text-xs text-slate-500">
                                                {{ $enquiry->reference }} ·
                                                {{ $enquiry->created_at->timezone($timezone)->format('j M Y, H:i') }}
                                                @if ($enquiry->formattedOffer())
                                                    · offered {{ $enquiry->formattedOffer() }}
                                                @endif
                                                @if ($enquiry->assignee)
                                                    · {{ $enquiry->assignee->name }}
                                                @endif
                                            </p>
                                        </div>
                                        <span @class([
                                            'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                            'bg-amber-100 text-amber-900' => $enquiry->status->tone() === 'amber',
                                            'bg-sky-100 text-sky-900' => $enquiry->status->tone() === 'sky',
                                            'bg-emerald-100 text-emerald-900' => $enquiry->status->tone() === 'emerald',
                                            'bg-rose-100 text-rose-900' => $enquiry->status->tone() === 'rose',
                                        ])>{{ $enquiry->status->label() }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </section>

                <aside class="space-y-6 lg:col-span-1">
                    @if ($canTransition && in_array(ListingStatus::Available, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.showroom.publish', $listing) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">
                                {{ $listing->status === ListingStatus::Reserved ? 'Release the reservation' : 'Publish to the showroom' }}
                            </h2>
                            <p class="mt-2 text-sm text-slate-600">
                                {{ $listing->status === ListingStatus::Reserved
                                    ? 'The deposit fell through — put it back on the market.'
                                    : 'Make it visible to the public and open to enquiries.' }}
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                {{ $listing->status === ListingStatus::Reserved ? 'Back on the market' : 'Publish' }}
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(ListingStatus::Reserved, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.showroom.reserve', $listing) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Reserve</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                A deposit is being held. It stays visible, marked reserved, and still takes enquiries
                                in case the deposit falls through.
                            </p>
                            <div class="mt-4">
                                <label for="reserve-enquiry" class="block text-sm font-semibold">For which enquiry?</label>
                                <select id="reserve-enquiry" name="enquiry_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                    <option value="">Not recorded</option>
                                    @foreach ($openEnquiries as $enquiry)
                                        <option value="{{ $enquiry->getKey() }}">{{ $enquiry->contact_name }} ({{ $enquiry->reference }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-amber-600 px-5 text-sm font-bold text-white hover:bg-amber-700">
                                Reserve
                            </button>
                        </form>
                    @endif

                    @if (in_array(ListingStatus::Sold, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.showroom.sell', $listing) }}" class="rounded-3xl border border-sky-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Record the sale</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                This is final, and it is what the sales figures are built from.
                                @if ($listing->vehicle)
                                    It also retires {{ $listing->vehicle->registration_plate }} from the hire fleet.
                                @endif
                            </p>

                            @if (! $canSell)
                                <p class="mt-4 rounded-xl bg-slate-50 p-3 text-sm text-slate-600">
                                    A manager has to record the sale.
                                </p>
                            @else
                                <div class="mt-4">
                                    <label for="sold-price" class="block text-sm font-semibold">Sold for ({{ $listing->currency }})</label>
                                    <input id="sold-price" name="sold_price" type="text" inputmode="numeric" required maxlength="24"
                                           value="{{ old('sold_price', \App\Support\Money::forInput($listing->asking_price_minor, $listing->currency)) }}"
                                           class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                </div>
                                <div class="mt-4">
                                    <label for="buyer-enquiry" class="block text-sm font-semibold">Buyer</label>
                                    <select id="buyer-enquiry" name="buyer_enquiry_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                        <option value="">Not from an enquiry</option>
                                        @foreach ($openEnquiries as $enquiry)
                                            <option value="{{ $enquiry->getKey() }}">{{ $enquiry->contact_name }} ({{ $enquiry->reference }})</option>
                                        @endforeach
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500">
                                        Every other open enquiry on this vehicle is closed as lost.
                                    </p>
                                </div>
                                <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-sky-700 px-5 text-sm font-bold text-white hover:bg-sky-800">
                                    Record the sale
                                </button>
                            @endif
                        </form>
                    @endif

                    @if ($canTransition && in_array(ListingStatus::Withdrawn, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.showroom.withdraw', $listing) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Withdraw</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Takes it out of the showroom without claiming it was sold.
                            </p>
                            <div class="mt-4">
                                <label for="withdraw-reason" class="block text-sm font-semibold">Reason</label>
                                <input id="withdraw-reason" name="reason" type="text" required minlength="5" maxlength="255"
                                       placeholder="Going back into the hire fleet"
                                       class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            </div>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-rose-300 px-5 text-sm font-bold text-rose-800 hover:bg-rose-50">
                                Withdraw
                            </button>
                        </form>
                    @endif

                    @if ($canTransition && in_array(ListingStatus::Draft, $nextStatuses, true))
                        <form method="POST" action="{{ route('admin.showroom.restore', $listing) }}" class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            @csrf
                            <h2 class="text-lg font-black text-slate-900">Bring it back</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                Returns it to draft so the price and photographs can be checked before it goes public
                                again.
                            </p>
                            <button type="submit" class="mt-4 inline-flex min-h-11 w-full items-center justify-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                                Restore as draft
                            </button>
                        </form>
                    @endif

                    @if ($nextStatuses === [])
                        <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                            <h2 class="text-lg font-black text-slate-900">Closed</h2>
                            <p class="mt-2 text-sm text-slate-600">
                                A sold listing is the record of what was advertised and what it fetched, so it is not
                                reopened. If the sale was recorded in error, create a new listing.
                            </p>
                        </div>
                    @endif
                </aside>
            </div>
        </div>
    </div>
</x-app-layout>
