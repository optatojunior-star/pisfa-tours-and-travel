@php
    use App\Enums\BookingStage;
    use App\Support\Bookings\BookingSource;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">All bookings</h1>
            </div>
            <a href="{{ route('admin.dashboard') }}" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-bold text-slate-700">Back to dashboard</a>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-6 px-4 sm:px-6 lg:px-8">
            <p class="text-sm text-slate-600">
                Tours, car hire, airport transfers, and vehicle imports in one list. Each domain keeps its own detailed
                status; the stages below are the shared vocabulary this screen groups by.
            </p>

            <section class="grid gap-4 sm:grid-cols-3 lg:grid-cols-5" aria-label="Stage counts">
                @foreach (BookingStage::cases() as $case)
                    @php $count = $stageCounts[$case->value] ?? 0; @endphp
                    <a href="{{ route('admin.bookings.index', array_filter(array_merge($filters, ['stage' => $case->value, 'page' => null]))) }}" @class([
                        'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                        'border-amber-300 bg-amber-50 hover:bg-amber-100' => $case === BookingStage::AwaitingAction && $count > 0,
                        'border-slate-200 bg-white hover:bg-slate-50' => $case !== BookingStage::AwaitingAction || $count === 0,
                        'ring-2 ring-emerald-600' => $stage === $case,
                    ])>
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $case->label() }}</p>
                        <p class="mt-1 text-2xl font-black text-slate-900">{{ $count }}</p>
                    </a>
                @endforeach
            </section>

            <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm" aria-labelledby="booking-filters">
                <h2 id="booking-filters" class="sr-only">Filter bookings</h2>
                <form method="GET" action="{{ route('admin.bookings.index') }}" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 lg:items-end">
                    <div class="lg:col-span-2">
                        <label for="bk-q" class="block text-sm font-semibold">Search</label>
                        <input id="bk-q" name="q" type="search" maxlength="100" value="{{ $filters['q'] ?? '' }}"
                               placeholder="Reference, customer name, email"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="bk-source" class="block text-sm font-semibold">Service</label>
                        <select id="bk-source" name="source" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All services</option>
                            @foreach (BookingSource::cases() as $case)
                                <option value="{{ $case->value }}" @selected($source === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="bk-stage" class="block text-sm font-semibold">Stage</label>
                        <select id="bk-stage" name="stage" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="">All stages</option>
                            @foreach (BookingStage::cases() as $case)
                                <option value="{{ $case->value }}" @selected($stage === $case)>{{ $case->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label for="bk-from" class="block text-sm font-semibold">Service date from</label>
                        <input id="bk-from" name="from" type="date" value="{{ $filters['from'] ?? '' }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="bk-to" class="block text-sm font-semibold">to</label>
                        <input id="bk-to" name="to" type="date" value="{{ $filters['to'] ?? '' }}"
                               class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                    </div>
                    <div>
                        <label for="bk-sort" class="block text-sm font-semibold">Sort by</label>
                        <select id="bk-sort" name="sort" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="created_at" @selected(($filters['sort'] ?? 'created_at') === 'created_at')>Recently received</option>
                            <option value="service_date" @selected(($filters['sort'] ?? '') === 'service_date')>Service date</option>
                            <option value="amount" @selected(($filters['sort'] ?? '') === 'amount')>Amount</option>
                        </select>
                    </div>
                    <div>
                        <label for="bk-direction" class="block text-sm font-semibold">Order</label>
                        <select id="bk-direction" name="direction" class="mt-1 block w-full rounded-xl border-slate-300 focus:border-emerald-600 focus:ring-emerald-600">
                            <option value="desc" @selected(($filters['direction'] ?? 'desc') === 'desc')>Newest first</option>
                            <option value="asc" @selected(($filters['direction'] ?? '') === 'asc')>Oldest first</option>
                        </select>
                    </div>
                    <div class="flex gap-2 lg:col-span-4">
                        <button type="submit" class="inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">Apply</button>
                        <a href="{{ route('admin.bookings.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-5 text-sm font-bold text-slate-700">Reset</a>
                    </div>
                </form>
            </section>

            @if ($bookings->isEmpty())
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-10 text-center">
                    <h2 class="text-lg font-bold">No bookings match</h2>
                    <p class="mt-2 text-sm text-slate-600">Try a wider date range, a different stage, or clear the search.</p>
                </div>
            @else
                <div class="overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Bookings across every service</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Reference</th>
                                <th scope="col" class="px-4 py-3">Service</th>
                                <th scope="col" class="px-4 py-3">Customer</th>
                                <th scope="col" class="px-4 py-3">Status</th>
                                <th scope="col" class="px-4 py-3">Service date</th>
                                <th scope="col" class="px-4 py-3 text-right">Amount</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($bookings as $row)
                                @php
                                    $rowSource = BookingSource::from($row->source);
                                    $rowStage = $rowSource->stageFor((string) $row->status);
                                    $serviceDate = $row->service_date
                                        ? \Carbon\CarbonImmutable::parse($row->service_date)->timezone($timezone)
                                        : null;
                                @endphp
                                <tr class="align-top">
                                    <td class="px-4 py-3 font-mono text-xs text-slate-600">{{ $row->reference }}</td>
                                    <td class="px-4 py-3">
                                        <p class="font-semibold text-slate-800">{{ $rowSource->label() }}</p>
                                        <p class="mt-0.5 max-w-48 truncate text-xs text-slate-500">{{ $row->summary }}</p>
                                    </td>
                                    <td class="px-4 py-3">
                                        <p class="text-slate-800">{{ $row->contact_name }}</p>
                                        <p class="text-xs text-slate-500">{{ $row->contact_email }}</p>
                                        @if ($row->customer_id === null)
                                            <p class="text-[10px] font-semibold uppercase text-amber-700">Guest</p>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <span @class([
                                            'rounded-full px-3 py-1 text-xs font-bold',
                                            'bg-amber-50 text-amber-900' => $rowStage?->tone() === 'amber',
                                            'bg-sky-50 text-sky-800' => $rowStage?->tone() === 'sky',
                                            'bg-emerald-50 text-emerald-800' => $rowStage?->tone() === 'emerald',
                                            'bg-slate-100 text-slate-700' => $rowStage?->tone() === 'slate' || $rowStage === null,
                                            'bg-rose-50 text-rose-800' => $rowStage?->tone() === 'rose',
                                        ])>{{ $rowSource->statusLabel((string) $row->status) }}</span>
                                    </td>
                                    <td class="px-4 py-3 text-xs text-slate-500">
                                        @if ($rowSource === BookingSource::VehicleImports)
                                            <span class="text-slate-400">No fixed date</span>
                                        @else
                                            {{ $serviceDate?->format('j M Y, H:i') ?? '—' }}
                                        @endif
                                    </td>
                                    <td class="px-4 py-3 text-right font-semibold tabular-nums text-slate-900">
                                        @if ((int) $row->amount_minor > 0)
                                            {{ Money::format((int) $row->amount_minor, $row->currency) }}
                                        @else
                                            <span class="text-xs font-normal text-slate-400">Not priced</span>
                                        @endif
                                    </td>
                                    <td class="px-4 py-3">
                                        <a href="{{ $rowSource->consoleRoute($row->reference) }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div>{{ $bookings->links() }}</div>
            @endif
        </div>
    </div>
</x-app-layout>
