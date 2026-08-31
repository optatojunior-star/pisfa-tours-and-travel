@php
    use App\Enums\FlightInquiryScope;
    use App\Enums\FlightInquiryStatus;
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Flights</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">My flight enquiries</h1>
            </div>
            <a href="{{ route('flight-inquiries.create') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2.5 text-sm font-bold text-white">New enquiry</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="my-flight-filters">
                <h2 id="my-flight-filters" class="sr-only">Filter flight enquiries</h2>
                <form method="GET" action="{{ route('portal.flight-inquiries.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="flight-history-search" class="block text-sm font-semibold">Search</label>
                        <input id="flight-history-search" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}" placeholder="Reference, origin, destination" class="mt-1 block w-full rounded-xl border-slate-300">
                    </div>
                    <div>
                        <label for="flight-history-status" class="block text-sm font-semibold">Status</label>
                        <select id="flight-history-status" name="status" class="mt-1 block w-full rounded-xl border-slate-300">
                            <option value="">All statuses</option>
                            @foreach (FlightInquiryStatus::cases() as $case)
                                <option value="{{ $case->value }}" @selected(($filters['status'] ?? '') === $case->value)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="flex gap-2">
                        <button class="min-h-11 rounded-xl bg-emerald-700 px-4 text-sm font-bold text-white">Filter</button>
                        <a href="{{ route('portal.flight-inquiries.index') }}" class="inline-flex min-h-11 items-center px-2 text-sm font-bold text-slate-600">Reset</a>
                    </div>
                </form>
            </section>

            @if ($inquiries->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No flight enquiries yet</h2>
                    <p class="mt-2 text-sm text-slate-600">Enquiries you send while signed in appear here with their follow-up status.</p>
                    <a href="{{ route('flight-inquiries.create') }}" class="mt-5 inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-5 py-3 text-sm font-bold text-white">Send an enquiry</a>
                </div>
            @else
                <div class="grid gap-5 md:grid-cols-2">
                    @foreach ($inquiries as $inquiry)
                        <article class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="font-mono text-xs font-bold text-emerald-700">{{ $inquiry->reference }}</p>
                                    <h2 class="mt-1 text-lg font-black text-slate-950">{{ $inquiry->routeLabel() }}</h2>
                                    <p class="text-sm text-slate-600">{{ $inquiry->scope->label() }} · {{ $inquiry->trip_type->label() }}</p>
                                </div>
                                <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-700">{{ $inquiry->status->label() }}</span>
                            </div>
                            <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
                                <div><dt class="text-xs text-slate-500">Outbound</dt><dd class="font-semibold">{{ $inquiry->outbound_on->format('j M Y') }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Return</dt><dd class="font-semibold">{{ $inquiry->return_on?->format('j M Y') ?? '—' }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Travellers</dt><dd class="font-semibold">{{ $inquiry->passenger_count }} · {{ $inquiry->travel_class->label() }}</dd></div>
                                <div><dt class="text-xs text-slate-500">Payment</dt><dd class="font-semibold text-amber-800">Not collected</dd></div>
                            </dl>
                            <a href="{{ route('portal.flight-inquiries.show', $inquiry) }}" class="mt-5 inline-flex min-h-11 w-full items-center justify-center rounded-xl border border-emerald-200 text-sm font-bold text-emerald-800">View enquiry</a>
                        </article>
                    @endforeach
                </div>
                <div>{{ $inquiries->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
