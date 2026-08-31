@php
    use App\Support\Money;

    $timezone = $snapshot['timezone'];
    $invoices = $snapshot['invoices_due'];
    $loyalty = $snapshot['loyalty'];

    $services = [
        ['title' => 'Tours and safaris', 'description' => 'Browse published packages and real scheduled availability.', 'route' => 'tours.index', 'action' => 'Browse tours'],
        ['title' => 'Car hire', 'description' => 'Search vehicles by dates, mode, seats, and exact rates.', 'route' => 'car-hire.index', 'action' => 'Browse vehicles'],
        ['title' => 'Airport transfers', 'description' => 'Book a planned pickup or drop-off.', 'route' => 'airport-transfers.index', 'action' => 'Plan a transfer'],
        ['title' => 'Vehicle imports', 'description' => 'Ask us to source, ship, and clear a vehicle for you.', 'route' => 'vehicle-imports.create', 'action' => 'Start an import'],
    ];
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">My PISFA</p>
                <h1 class="mt-1 text-2xl font-bold tracking-tight text-slate-950">Hello, {{ str($customer->name)->before(' ') }}</h1>
            </div>
            <p class="text-sm text-slate-500">{{ now($timezone)->format('l, j F Y') }}</p>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-6xl space-y-8 px-4 sm:px-6 lg:px-8">

            <section class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" aria-label="Your account at a glance">
                <a href="{{ route('portal.bookings.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Open bookings</p>
                    <p class="mt-1 text-3xl font-black text-slate-900">{{ $snapshot['open_bookings'] }}</p>
                </a>

                <a href="{{ route('portal.quotations.index') }}" @class([
                    'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'border-amber-300 bg-amber-50 hover:bg-amber-100' => $snapshot['quotations_awaiting_response'] > 0,
                    'border-slate-200 bg-white hover:bg-slate-50' => $snapshot['quotations_awaiting_response'] === 0,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quotations to review</p>
                    <p class="mt-1 text-3xl font-black text-slate-900">{{ $snapshot['quotations_awaiting_response'] }}</p>
                </a>

                <a href="{{ route('portal.invoices.index') }}" @class([
                    'rounded-2xl border p-5 transition focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600',
                    'border-rose-300 bg-rose-50 hover:bg-rose-100' => $invoices['overdue'] > 0,
                    'border-slate-200 bg-white hover:bg-slate-50' => $invoices['overdue'] === 0,
                ])>
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Invoices to pay</p>
                    <p class="mt-1 text-3xl font-black text-slate-900">{{ $invoices['count'] }}</p>
                    @if ($invoices['overdue'] > 0)
                        <p class="mt-1 text-xs font-bold text-rose-700">{{ $invoices['overdue'] }} overdue</p>
                    @endif
                </a>

                @if ($loyalty)
                    <a href="{{ route('portal.loyalty.index') }}" class="rounded-2xl border border-slate-200 bg-white p-5 transition hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Loyalty points</p>
                        <p class="mt-1 text-3xl font-black text-emerald-800">{{ number_format($loyalty['points']) }}</p>
                        <p class="mt-1 text-xs text-slate-500">{{ $loyalty['tier_label'] }} tier</p>
                    </a>
                @else
                    <div class="rounded-2xl border border-slate-200 bg-white p-5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Loyalty points</p>
                        <p class="mt-1 text-3xl font-black text-slate-400">0</p>
                        <p class="mt-1 text-xs text-slate-500">Earned on your first paid booking</p>
                    </div>
                @endif
            </section>

            @if ($invoices['count'] > 0)
                <section class="rounded-3xl border border-amber-200 bg-amber-50/70 p-6" aria-labelledby="due-heading">
                    <h2 id="due-heading" class="text-lg font-black text-slate-950">Amounts due</h2>
                    <dl class="mt-3 space-y-1 text-sm">
                        @foreach ($invoices['by_currency'] as $currency => $minor)
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-slate-600">{{ $currency }}</dt>
                                <dd class="text-lg font-black tabular-nums text-slate-900">{{ Money::format($minor, $currency) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    <a href="{{ route('portal.invoices.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">View and pay</a>
                </section>
            @endif

            @if ($unread > 0)
                <a href="{{ route('portal.notifications.index') }}"
                   class="block rounded-3xl border border-emerald-300 bg-emerald-50 p-6 transition hover:bg-emerald-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600">
                    <h2 class="text-lg font-black text-slate-950">
                        {{ $unread }} unread {{ str('message')->plural($unread) }}
                    </h2>
                    <p class="mt-1 text-sm text-slate-700">Booking confirmations, quotations, invoices, and reminders.</p>
                </a>
            @endif

            <section aria-labelledby="upcoming-heading">
                <h2 id="upcoming-heading" class="text-lg font-black text-slate-950">What is coming up</h2>

                @if ($snapshot['upcoming'] === [])
                    <div class="mt-4 rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                        <p class="text-sm text-slate-600">Nothing is scheduled yet. Browse a service below to get started.</p>
                    </div>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($snapshot['upcoming'] as $item)
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $item['source']->label() }}</p>
                                    <p class="mt-1 font-semibold text-slate-900">{{ $item['summary'] }}</p>
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $item['reference'] }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="font-semibold text-slate-900">{{ $item['service_date']->timezone($timezone)->format('j M Y') }}</p>
                                    <p class="text-xs text-slate-500">{{ $item['service_date']->timezone($timezone)->format('H:i') }} · {{ $item['status_label'] }}</p>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="recent-heading">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <h2 id="recent-heading" class="text-lg font-black text-slate-950">Recent activity</h2>
                    <a href="{{ route('portal.activity') }}" class="text-sm font-bold text-emerald-800 underline">See everything</a>
                </div>

                @if ($recent->isEmpty())
                    <div class="mt-4 rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                        <p class="text-sm text-slate-600">Nothing yet. Anything you book, ask for, or pay will appear here.</p>
                    </div>
                @else
                    <ul class="mt-4 space-y-3">
                        @foreach ($recent as $item)
                            @php
                                $kind = \App\Support\Portal\ActivityKind::from($item->kind);
                                $url = $kind->urlFor($item->source, $item->reference);
                                $at = \App\Services\Portal\CustomerActivityQuery::localTime($item->happened_at);
                            @endphp
                            <li class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-slate-200 bg-white p-5">
                                <div>
                                    <p class="text-xs font-bold uppercase tracking-wide text-emerald-700">{{ $kind->sourceLabel($item->source) }}</p>
                                    <p class="mt-1 font-semibold text-slate-900">{{ $item->summary }}</p>
                                    <p class="mt-1 font-mono text-xs text-slate-500">{{ $item->reference }}</p>
                                </div>
                                <div class="text-right">
                                    @if ((int) $item->amount_minor > 0)
                                        <p class="font-semibold text-slate-900">{{ Money::format((int) $item->amount_minor, $item->currency) }}</p>
                                    @endif
                                    <p class="text-xs text-slate-500">{{ $at?->format('j M Y') }}</p>
                                    @if ($url)
                                        <a href="{{ $url }}" class="mt-1 inline-block text-xs font-bold text-emerald-800 underline">Open</a>
                                    @endif
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif

                <div class="mt-4 flex flex-wrap gap-2">
                    <a href="{{ route('portal.documents') }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">My documents</a>
                    <a href="{{ route('portal.notifications.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">Messages</a>
                    <a href="{{ route('profile.edit') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-bold text-slate-700">Profile and preferences</a>
                </div>
            </section>

            <section aria-labelledby="services-heading">
                <h2 id="services-heading" class="text-lg font-black text-slate-950">Plan something new</h2>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach ($services as $service)
                        <div class="rounded-2xl border border-slate-200 bg-white p-5">
                            <h3 class="font-bold text-slate-900">{{ $service['title'] }}</h3>
                            <p class="mt-1 text-sm text-slate-600">{{ $service['description'] }}</p>
                            <a href="{{ route($service['route']) }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl border border-emerald-200 px-4 text-sm font-bold text-emerald-800">{{ $service['action'] }}</a>
                        </div>
                    @endforeach
                </div>
                <a href="{{ route('request-quotation') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-emerald-700 px-5 text-sm font-bold text-white hover:bg-emerald-800">
                    Ask us to quote something else
                </a>
            </section>
        </div>
    </div>
</x-app-layout>
