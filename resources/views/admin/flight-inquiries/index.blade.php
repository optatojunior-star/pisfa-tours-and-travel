@php
    use App\Enums\FlightInquiryScope;
    use App\Enums\FlightInquiryStatus;
    use App\Enums\FlightTripType;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Flight enquiries</h1>
            </div>
            <div class="flex flex-wrap gap-2 text-sm font-bold">
                <a href="{{ route('admin.flight-inquiries.index', ['queue' => 'open']) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 bg-emerald-50 px-4 text-emerald-800">{{ $openCount }} open</a>
                <a href="{{ route('admin.flight-inquiries.index', ['queue' => 'unassigned']) }}" class="inline-flex min-h-11 items-center rounded-xl border border-amber-200 bg-amber-50 px-4 text-amber-900">{{ $unassignedCount }} unassigned</a>
            </div>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="flight-admin-filters">
                <h2 id="flight-admin-filters" class="sr-only">Filter flight enquiries</h2>
                <form method="GET" action="{{ route('admin.flight-inquiries.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="admin-flight-q" class="block text-sm font-semibold">Search</label>
                        <input id="admin-flight-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, traveller, route" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="admin-flight-status" class="block text-sm font-semibold">Status</label>
                        <select id="admin-flight-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All statuses</option>
                            @foreach (FlightInquiryStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-flight-scope" class="block text-sm font-semibold">Flight type</label>
                        <select id="admin-flight-scope" name="scope" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Domestic and international</option>
                            @foreach (FlightInquiryScope::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['scope'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-flight-trip" class="block text-sm font-semibold">Trip type</label>
                        <select id="admin-flight-trip" name="trip_type" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Any</option>
                            @foreach (FlightTripType::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['trip_type'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-flight-consultant" class="block text-sm font-semibold">Consultant</label>
                        <select id="admin-flight-consultant" name="assigned_to_user_id" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Anyone</option>
                            @foreach ($consultants as $consultant)
                                <option value="{{ $consultant->id }}" @selected((int) ($filters['assigned_to_user_id'] ?? 0) === $consultant->id)>{{ $consultant->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="admin-flight-queue" class="block text-sm font-semibold">Queue</label>
                        <select id="admin-flight-queue" name="queue" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">Everything</option>
                            <option value="open" @selected(($filters['queue'] ?? '') === 'open')>Open only</option>
                            <option value="mine" @selected(($filters['queue'] ?? '') === 'mine')>Assigned to me</option>
                            <option value="unassigned" @selected(($filters['queue'] ?? '') === 'unassigned')>Open and unassigned</option>
                        </select>
                    </div>
                    <div>
                        <label for="admin-flight-from" class="block text-sm font-semibold">Outbound from</label>
                        <input id="admin-flight-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="admin-flight-to" class="block text-sm font-semibold">Outbound to</label>
                        <input id="admin-flight-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div class="flex gap-2">
                        <button class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white">Filter</button>
                        <a href="{{ route('admin.flight-inquiries.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-slate-600">Reset</a>
                    </div>
                </form>
            </section>

            @if ($inquiries->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No flight enquiries match these filters</h2>
                    <p class="mt-2 text-sm text-slate-600">Adjust the search or clear the queue filter.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Flight enquiries</caption>
                        <thead class="bg-slate-50 text-left text-xs font-bold uppercase tracking-wide text-slate-600">
                            <tr>
                                <th scope="col" class="px-4 py-3">Reference</th>
                                <th scope="col" class="px-4 py-3">Route</th>
                                <th scope="col" class="px-4 py-3">Dates</th>
                                <th scope="col" class="px-4 py-3">Traveller</th>
                                <th scope="col" class="px-4 py-3">Consultant</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($inquiries as $inquiry)
                                <tr>
                                    <td class="px-4 py-3 font-mono text-xs font-bold text-emerald-700">{{ $inquiry->reference }}</td>
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-slate-900">{{ $inquiry->routeLabel() }}</span>
                                        <span class="block text-xs text-slate-500">{{ $inquiry->scope->label() }} · {{ $inquiry->trip_type->label() }} · {{ $inquiry->travel_class->label() }}</span>
                                    </td>
                                    <td class="px-4 py-3 whitespace-nowrap text-xs">
                                        <span class="block">{{ $inquiry->outbound_on->format('j M Y') }}</span>
                                        <span class="block text-slate-500">{{ $inquiry->return_on?->format('j M Y') ?? 'one way' }}</span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <span class="block font-semibold text-slate-900">{{ $inquiry->contact_name }}</span>
                                        <span class="block text-xs text-slate-500">{{ $inquiry->passenger_count }} pax · {{ $inquiry->isGuest() ? 'Guest' : 'Customer account' }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs">
                                        @if ($inquiry->assignee === null)
                                            <span class="rounded-full bg-amber-100 px-2 py-1 font-bold text-amber-900">Unassigned</span>
                                        @else
                                            <span class="font-semibold text-slate-900">{{ $inquiry->assignee->name }}</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3"><span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $inquiry->status->label() }}</span></td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('admin.flight-inquiries.show', $inquiry) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-3 text-xs font-bold text-emerald-800">Manage</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $inquiries->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
