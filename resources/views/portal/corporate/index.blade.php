@php
    use App\Support\ServiceCatalogue;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div>
            <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Groups</p>
            <h1 class="mt-1 text-2xl font-bold text-slate-950">Group travel</h1>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-6 px-4 sm:px-6 lg:px-8">
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

            <section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                <h2 class="text-lg font-black text-slate-900">Plan a trip for a group</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Tell us the shape of it and we will come back with a price. You add the traveller list once the
                    price is agreed.
                </p>

                <form method="POST" action="{{ route('portal.groups.store') }}" class="mt-5 grid gap-4 sm:grid-cols-6">
                    @csrf

                    @if ($accounts->isNotEmpty())
                        <div class="sm:col-span-3">
                            <label for="corporate_account_id" class="block text-sm font-semibold">Bill it to</label>
                            <select id="corporate_account_id" name="corporate_account_id" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                                <option value="">Me personally</option>
                                @foreach ($accounts as $account)
                                    <option value="{{ $account->getKey() }}" @selected((int) old('corporate_account_id') === (int) $account->getKey())>
                                        {{ $account->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>
                    @endif

                    <div class="sm:col-span-3">
                        <label for="title" class="block text-sm font-semibold">What is it</label>
                        <input id="title" name="title" type="text" required minlength="4" maxlength="200"
                               value="{{ old('title') }}" placeholder="Team retreat to Jinja"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>

                    <div class="sm:col-span-2">
                        <label for="service_kind" class="block text-sm font-semibold">Service</label>
                        <select id="service_kind" name="service_kind" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach ($services as $key)
                                <option value="{{ $key }}" @selected(old('service_kind', 'corporate-travel') === $key)>
                                    {{ ServiceCatalogue::label($key) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label for="starts_on" class="block text-sm font-semibold">From</label>
                        <input id="starts_on" name="starts_on" type="date" required
                               value="{{ old('starts_on') }}" min="{{ now($timezone)->toDateString() }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="ends_on" class="block text-sm font-semibold">To</label>
                        <input id="ends_on" name="ends_on" type="date" required
                               value="{{ old('ends_on') }}" min="{{ now($timezone)->toDateString() }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="headcount" class="block text-sm font-semibold">How many</label>
                        <input id="headcount" name="headcount" type="number" required
                               min="{{ (int) config('corporate.minimum_group_size', 2) }}"
                               max="{{ (int) config('corporate.maximum_group_size', 500) }}"
                               value="{{ old('headcount') }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="currency" class="block text-sm font-semibold">Currency</label>
                        <select id="currency" name="currency" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            @foreach (config('pisfa.currency.supported', ['UGX', 'USD']) as $code)
                                <option value="{{ $code }}" @selected(old('currency', config('pisfa.currency.default', 'UGX')) === $code)>{{ $code }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="sm:col-span-3">
                        <label for="pickup_location" class="block text-sm font-semibold">Starting from</label>
                        <input id="pickup_location" name="pickup_location" type="text" maxlength="500"
                               value="{{ old('pickup_location') }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div class="sm:col-span-3">
                        <label for="destination" class="block text-sm font-semibold">Going to</label>
                        <input id="destination" name="destination" type="text" maxlength="500"
                               value="{{ old('destination') }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>

                    <div class="sm:col-span-6">
                        <label for="requirements" class="block text-sm font-semibold">Anything we should know</label>
                        <textarea id="requirements" name="requirements" rows="3" maxlength="5000"
                                  placeholder="Two wheelchair users, vegetarian meals for six, one late arrival"
                                  class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">{{ old('requirements') }}</textarea>
                    </div>

                    <div class="sm:col-span-6">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-6 text-sm font-bold text-white hover:bg-emerald-800">
                            Send the enquiry
                        </button>
                    </div>
                </form>
            </section>

            @if ($bookings->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-12 text-center">
                    <h2 class="text-lg font-bold text-slate-900">No groups yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Anything you organise for more than one person appears here.</p>
                </div>
            @else
                <section class="overflow-hidden rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-slate-200 text-sm">
                            <caption class="sr-only">Your group bookings</caption>
                            <thead class="bg-slate-50">
                                <tr>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Trip</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Dates</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">List</th>
                                    <th scope="col" class="px-5 py-3 text-left font-bold text-slate-700">Status</th>
                                    <th scope="col" class="px-5 py-3 text-right font-bold text-slate-700">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($bookings as $booking)
                                    <tr class="hover:bg-slate-50">
                                        <td class="px-5 py-4">
                                            <p class="font-bold text-slate-900">{{ $booking->title }}</p>
                                            <p class="text-xs text-slate-500">
                                                {{ $booking->reference }}
                                                @if ($booking->account)
                                                    · {{ $booking->account->name }}
                                                @endif
                                            </p>
                                        </td>
                                        <td class="px-5 py-4 text-slate-700">{{ $booking->dateLabel() }}</td>
                                        <td class="px-5 py-4 text-slate-700">
                                            {{ $booking->travelers_count }} of {{ $booking->headcount }}
                                        </td>
                                        <td class="px-5 py-4">
                                            <span @class([
                                                'inline-flex rounded-full px-2.5 py-1 text-xs font-bold',
                                                'bg-slate-100 text-slate-800' => $booking->status->tone() === 'slate',
                                                'bg-amber-100 text-amber-900' => $booking->status->tone() === 'amber',
                                                'bg-sky-100 text-sky-900' => $booking->status->tone() === 'sky',
                                                'bg-emerald-100 text-emerald-900' => $booking->status->tone() === 'emerald',
                                                'bg-rose-100 text-rose-900' => $booking->status->tone() === 'rose',
                                            ])>{{ $booking->status->label() }}</span>
                                        </td>
                                        <td class="px-5 py-4 text-right">
                                            <a href="{{ route('portal.groups.show', $booking) }}" class="font-bold text-emerald-800 hover:underline">Open</a>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
                <div>{{ $bookings->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
