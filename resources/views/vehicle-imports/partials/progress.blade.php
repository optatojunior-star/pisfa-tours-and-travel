@php
    use App\Enums\VehicleImportStatus;
    use App\Support\Money;

    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');
    $step = $order->status->step();
    $total = VehicleImportStatus::totalSteps();
@endphp

<section class="rounded-3xl border border-slate-200 bg-white p-6 shadow-sm" aria-labelledby="import-progress">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h2 id="import-progress" class="text-lg font-black text-slate-950">{{ $order->vehicleSummary() }}</h2>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-bold text-slate-700">{{ $order->status->label() }}</span>
    </div>
    <p class="mt-2 text-sm text-slate-600">{{ $order->status->customerDescription() }}</p>

    @if ($step !== null)
        <div class="mt-5">
            <div class="flex items-center justify-between text-xs font-semibold text-slate-500">
                <span>Step {{ $step }} of {{ $total }}</span>
                <span>{{ (int) round($step / $total * 100) }}%</span>
            </div>
            <div class="mt-2 h-2.5 w-full overflow-hidden rounded-full bg-slate-200" role="progressbar"
                 aria-valuenow="{{ $step }}" aria-valuemin="1" aria-valuemax="{{ $total }}"
                 aria-label="Import progress: {{ $order->status->label() }}">
                <div class="h-full rounded-full bg-emerald-600" style="width: {{ (int) round($step / $total * 100) }}%"></div>
            </div>
        </div>
    @else
        <p class="mt-4 rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm font-semibold text-rose-900" role="status">
            This import was cancelled.
            @if (filled($order->cancellation_reason)) {{ $order->cancellation_reason }} @endif
        </p>
    @endif

    <dl class="mt-6 grid gap-5 sm:grid-cols-2">
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Reference</dt><dd class="mt-1 font-mono font-bold text-emerald-700">{{ $order->reference }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Importing from</dt><dd class="mt-1 font-bold text-slate-900">{{ config('vehicle_imports.origin_countries')[$order->origin_country] ?? $order->origin_country }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Specification</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->body_type->label() }} · {{ $order->fuel_type->label() }} · {{ $order->transmission->label() }} · {{ $order->drive_type->label() }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Steering</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->steering->label() }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Units</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->units }}</dd></div>
        <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Your budget</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->formattedBudget() }}</dd></div>
        @if ($order->hasQuote())
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quoted price</dt><dd class="mt-1 font-bold text-emerald-800">{{ Money::format((int) $order->total_price_minor, (string) $order->quote_currency) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Deposit</dt><dd class="mt-1 font-bold text-slate-900">{{ Money::format((int) $order->deposit_minor, (string) $order->quote_currency) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Balance</dt><dd class="mt-1 font-bold text-slate-900">{{ Money::format($order->balanceMinor(), (string) $order->quote_currency) }}</dd></div>
            <div><dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Estimated arrival</dt><dd class="mt-1 font-bold text-slate-900">{{ $order->estimated_arrival_on?->format('j M Y') ?? 'To be confirmed' }}</dd></div>
            @if ($order->quote_expires_at !== null && ! $order->depositIsSettled())
                <div class="sm:col-span-2">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Quotation valid until</dt>
                    <dd class="mt-1 font-bold {{ $order->quoteHasExpired() ? 'text-rose-800' : 'text-slate-900' }}">
                        {{ $order->quote_expires_at->timezone($timezone)->format('j M Y, H:i') }}
                        @if ($order->quoteHasExpired()) — expired, contact us for a fresh quotation @endif
                    </dd>
                </div>
            @endif
        @endif
    </dl>
</section>
