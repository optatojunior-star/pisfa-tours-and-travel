@php
    use App\Enums\BookingStage;
    use App\Support\Bookings\BookingSource;
    use App\Support\Money;

    $queues = $snapshot['queues'];
    $today = $snapshot['today'];
    $revenue = $snapshot['revenue'];
    $receivables = $snapshot['receivables'];

    // Every one of these should be zero at the end of a good day.
    $actionCards = [
        ['label' => 'Bookings awaiting action', 'count' => $queues['bookings_awaiting_action'], 'url' => route('admin.bookings.index', ['stage' => BookingStage::AwaitingAction->value])],
        ['label' => 'New quotation requests', 'count' => $queues['new_quotation_requests'], 'url' => route('admin.quotation-requests.index', ['status' => 'new'])],
        ['label' => 'Reviews to moderate', 'count' => $queues['reviews_awaiting_moderation'], 'url' => route('admin.reviews.index', ['status' => 'pending'])],
        ['label' => 'Overdue invoices', 'count' => $queues['overdue_invoices'], 'url' => route('admin.invoices.index', ['bucket' => 'overdue'])],
        ['label' => 'Draft invoices', 'count' => $queues['draft_invoices'], 'url' => route('admin.invoices.index', ['status' => 'draft'])],
        ['label' => 'Unreconciled payments', 'count' => $queues['payments_unreconciled'], 'url' => route('admin.payments.index', ['bucket' => 'unreconciled'])],
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Operations</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Dashboard</h1>
            </div>
            <p class="text-sm text-slate-500">
                {{ $snapshot['generated_at']->format('l, j F Y, H:i') }} ({{ $snapshot['timezone'] }})
            </p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-7xl space-y-8 px-4 sm:px-6 lg:px-8">

            <section aria-labelledby="attention-heading">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="attention-heading" class="text-lg font-black text-slate-950">Needs attention</h2>
                    <p class="text-xs text-slate-500">Live figures, computed on load. Nothing here is cached.</p>
                </div>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach ($actionCards as $card)
                        <a href="{{ $card['url'] }}" @class([
                            'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                            'border-amber-300 bg-amber-50 hover:bg-amber-100' => $card['count'] > 0,
                            'border-slate-200 bg-white hover:bg-slate-50' => $card['count'] === 0,
                        ])>
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $card['label'] }}</p>
                            <p @class([
                                'mt-1 text-3xl font-black',
                                'text-amber-900' => $card['count'] > 0,
                                'text-slate-400' => $card['count'] === 0,
                            ])>{{ $card['count'] }}</p>
                        </a>
                    @endforeach
                </div>
            </section>

            <section aria-labelledby="today-heading">
                <h2 id="today-heading" class="text-lg font-black text-slate-950">Happening today</h2>
                <p class="mt-1 text-sm text-slate-600">
                    Confirmed and in-progress services with a {{ $snapshot['generated_at']->format('j M') }} date, in
                    {{ $snapshot['timezone'] }} terms. Vehicle imports run for months and have no service date, so they
                    are not counted here.
                </p>

                <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    @foreach ($today['sources'] as $key => $row)
                        <a href="{{ route('admin.bookings.index', ['source' => $key, 'from' => $snapshot['generated_at']->toDateString(), 'to' => $snapshot['generated_at']->toDateString()]) }}"
                           class="rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $row['label'] }}</p>
                            <p class="mt-1 text-3xl font-black text-slate-900">{{ $row['count'] }}</p>
                        </a>
                    @endforeach
                </div>

                @if ($today['total'] === 0)
                    <p class="mt-4 rounded-2xl border border-dashed border-slate-300 bg-white p-5 text-sm text-slate-600">
                        Nothing is scheduled for today.
                    </p>
                @endif
            </section>

            <section aria-labelledby="money-heading">
                <h2 id="money-heading" class="text-lg font-black text-slate-950">Money</h2>

                <div class="mt-4 grid gap-6 lg:grid-cols-2">
                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-sm font-black uppercase tracking-wide text-slate-700">Collected, last 30 days</h3>
                        <p class="mt-1 text-xs text-slate-500">
                            Settled payments since {{ $revenue['since']->timezone($snapshot['timezone'])->format('j M Y') }}.
                        </p>

                        @if ($revenue['payments'] === 0)
                            <p class="mt-4 text-sm text-slate-600">No payments have settled in this window.</p>
                        @else
                            <p class="mt-4 text-3xl font-black text-emerald-800">
                                {{ Money::format($revenue['base_total_minor'], $revenue['base_currency']) }}
                            </p>
                            <p class="mt-1 text-xs text-slate-500">
                                {{ $revenue['payments'] }} {{ str('payment')->plural($revenue['payments']) }},
                                converted at the rate stamped on each one — a later rate change cannot move this figure.
                            </p>

                            <dl class="mt-4 space-y-2 border-t border-slate-200 pt-4 text-sm">
                                @foreach ($revenue['by_currency'] as $currency => $row)
                                    <div class="flex items-center justify-between gap-4">
                                        <dt class="text-slate-500">{{ $currency }} received</dt>
                                        <dd class="tabular-nums font-semibold text-slate-800">
                                            {{ Money::format($row['collected_minor'], $currency) }}
                                            <span class="ml-1 text-xs font-normal text-slate-500">({{ $row['payments'] }})</span>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        @endif
                    </div>

                    <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
                        <h3 class="text-sm font-black uppercase tracking-wide text-slate-700">Outstanding receivables</h3>
                        {{-- Reported per currency and never added together: UGX
                             and USD have different exponents. --}}
                        <p class="mt-1 text-xs text-slate-500">Unpaid balances on issued invoices, per currency.</p>

                        @if ($receivables === [])
                            <p class="mt-4 text-sm text-slate-600">Nothing is outstanding.</p>
                        @else
                            <dl class="mt-4 space-y-4">
                                @foreach ($receivables as $currency => $row)
                                    <div class="rounded-2xl border border-slate-200 p-4">
                                        <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">{{ $currency }}</dt>
                                        <dd class="mt-1 text-2xl font-black text-slate-900">{{ Money::format($row['outstanding_minor'], $currency) }}</dd>
                                        <dd class="mt-1 text-xs text-slate-500">
                                            across {{ $row['count'] }} {{ str('invoice')->plural($row['count']) }}
                                            @if ($row['overdue_minor'] > 0)
                                                · <span class="font-bold text-rose-700">{{ Money::format($row['overdue_minor'], $currency) }} overdue</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                            <a href="{{ route('admin.invoices.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open invoices</a>
                        @endif
                    </div>
                </div>
            </section>

            <section aria-labelledby="queues-heading">
                <h2 id="queues-heading" class="text-lg font-black text-slate-950">Awaiting action by service</h2>

                <div class="mt-4 overflow-x-auto rounded-3xl border border-slate-200 bg-white shadow-sm">
                    <table class="min-w-full divide-y divide-slate-200 text-sm">
                        <caption class="sr-only">Bookings awaiting action, by service</caption>
                        <thead class="bg-stone-50 text-left text-xs uppercase tracking-wide text-slate-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Service</th>
                                <th scope="col" class="px-4 py-3 text-right">Awaiting action</th>
                                <th scope="col" class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($queues['bookings_by_source'] as $key => $row)
                                <tr>
                                    <td class="px-4 py-3 font-semibold text-slate-800">{{ $row['label'] }}</td>
                                    <td @class([
                                        'px-4 py-3 text-right text-lg font-black tabular-nums',
                                        'text-amber-800' => $row['count'] > 0,
                                        'text-slate-400' => $row['count'] === 0,
                                    ])>{{ $row['count'] }}</td>
                                    <td class="px-4 py-3 text-right">
                                        <a href="{{ route('admin.bookings.index', ['source' => $key, 'stage' => BookingStage::AwaitingAction->value]) }}"
                                           class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Open</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <a href="{{ route('admin.bookings.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                    Open all bookings
                </a>
            </section>
        </div>
    </div>
</x-app-layout>
