<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Payments\RefundPayment;
use App\Actions\Payments\SettlePayment;
use App\Enums\PaymentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\IndexPaymentsRequest;
use App\Http\Requests\Admin\RecordManualPaymentRequest;
use App\Http\Requests\Admin\RefundPaymentRequest;
use App\Models\Payment;
use App\Services\Payments\ExchangeRateResolver;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private readonly ExchangeRateResolver $rates) {}

    public function index(IndexPaymentsRequest $request): View
    {
        $filters = $request->validated();
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        $query = Payment::query()
            ->with(['customer:id,name,email', 'payable'])
            ->latest('created_at')
            ->latest('id');

        if (filled($filters['q'] ?? null)) {
            $query->search((string) $filters['q']);
        }

        foreach (['status', 'provider', 'currency'] as $field) {
            if (filled($filters[$field] ?? null)) {
                $query->where($field, $filters[$field]);
            }
        }

        match ($filters['bucket'] ?? null) {
            'settled' => $query->settled(),
            'in_flight' => $query->inFlight(),
            'refunded' => $query->whereIn('status', [
                PaymentStatus::PartiallyRefunded->value,
                PaymentStatus::Refunded->value,
            ]),
            // Settled but never credited to a service: the queue an operator
            // works through during reconciliation.
            'unreconciled' => $query->settled()->whereDoesntHave('allocations'),
            default => null,
        };

        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', CarbonImmutable::parse($filters['from'], $timezone)
                ->startOfDay()->utc());
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('created_at', '<', CarbonImmutable::parse($filters['to'], $timezone)
                ->addDay()->startOfDay()->utc());
        }

        $payments = $query->paginate(20)->withQueryString();

        return view('admin.payments.index', [
            'payments' => $payments,
            'filters' => $filters,
            'totals' => $this->reconciliationTotals($filters, $timezone),
        ]);
    }

    public function show(Payment $payment): View
    {
        $this->authorize('view', $payment);

        return view('admin.payments.show', [
            'payment' => $payment->load([
                'customer:id,name,email,phone',
                'recordedBy:id,name',
                'payable',
                'allocations.allocatable',
                'allocations.allocatedBy:id,name',
                'refunds.requestedBy:id,name',
                'webhookEvents',
            ]),
        ]);
    }

    /**
     * Record a manual receipt (bank transfer or cash) against evidence.
     *
     * Routed through the same SettlePayment action as a webhook, so allocation
     * and idempotency behave identically however money is confirmed.
     */
    public function record(
        RecordManualPaymentRequest $request,
        Payment $payment,
        SettlePayment $action,
    ): RedirectResponse {
        abort_unless($payment->provider->isManual(), 404);

        $action->execute(
            payment: $payment,
            actor: $request->user(),
            providerTransactionId: $request->validated('evidence_reference'),
            source: 'manual',
        );

        return back()->with('success', 'The payment was recorded and allocated.');
    }

    public function refund(
        RefundPaymentRequest $request,
        Payment $payment,
        RefundPayment $action,
    ): RedirectResponse {
        $action->execute(
            $request->user(),
            $payment,
            Money::parse($request->validated('amount'), $payment->currency),
            $request->validated('reason'),
            $request->validated('idempotency_key'),
        );

        return back()->with('success', 'The refund was submitted.');
    }

    /**
     * Reconciliation figures for the current filter, in the reporting base
     * currency so mixed-currency results still add up.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, int>
     */
    private function reconciliationTotals(array $filters, string $timezone): array
    {
        $scope = fn (): Builder => Payment::query()
            ->when(filled($filters['provider'] ?? null),
                fn (Builder $q) => $q->where('provider', $filters['provider']))
            ->when(filled($filters['from'] ?? null),
                fn (Builder $q) => $q->where('created_at', '>=',
                    CarbonImmutable::parse($filters['from'], $timezone)->startOfDay()->utc()))
            ->when(filled($filters['to'] ?? null),
                fn (Builder $q) => $q->where('created_at', '<',
                    CarbonImmutable::parse($filters['to'], $timezone)->addDay()->startOfDay()->utc()));

        $collected = (int) (clone $scope())->settled()->sum('base_amount_minor');

        // Refunds are stored in the payment currency. Converting them in SQL
        // would apply the rate without the UGX/USD exponent difference, so the
        // grouped totals are converted in PHP through the same resolver that
        // stamped each payment. Grouping keeps this bounded to the distinct
        // (currency, rate) pairs actually used.
        $refunded = 0;

        $groups = (clone $scope())
            ->settled()
            ->where('refunded_amount_minor', '>', 0)
            ->groupBy('currency', 'exchange_rate_ppm')
            ->selectRaw('currency, exchange_rate_ppm, SUM(refunded_amount_minor) AS grouped_total')
            ->get();

        foreach ($groups as $group) {
            // getAttribute rather than a dynamic property: `grouped_total` is a
            // query aggregate, not a column on the model.
            $refunded += $this->rates->convert(
                (int) $group->getAttribute('grouped_total'),
                (string) $group->getAttribute('currency'),
                $this->rates->baseCurrency(),
                (int) $group->getAttribute('exchange_rate_ppm'),
            );
        }

        return [
            'collected_minor' => $collected,
            'refunded_minor' => $refunded,
            'net_minor' => $collected - $refunded,
            'settled_count' => (clone $scope())->settled()->count(),
            'in_flight_count' => (clone $scope())->inFlight()->count(),
            'unreconciled_count' => (clone $scope())->settled()->whereDoesntHave('allocations')->count(),
        ];
    }
}
