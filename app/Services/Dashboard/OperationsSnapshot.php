<?php

namespace App\Services\Dashboard;

use App\Enums\BookingStage;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Enums\ReviewStatus;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\Review;
use App\Support\Bookings\BookingSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The live figures behind the operations dashboard.
 *
 * Everything here is computed from the domain tables at request time. Nothing is
 * cached or denormalised: a dashboard that is quietly stale is worse than one
 * that takes an extra moment, because people make decisions from it.
 *
 * Money is never summed across currencies. UGX has exponent 0 and USD exponent
 * 2, so adding their minor units would misstate a total by a factor of a
 * hundred. Every money figure is reported per currency.
 */
class OperationsSnapshot
{
    /** @return array<string, mixed> */
    public function forDate(?CarbonImmutable $at = null): array
    {
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $now = ($at ?? CarbonImmutable::now())->setTimezone($timezone);

        return [
            'generated_at' => $now,
            'timezone' => $timezone,
            'today' => $this->todaysService($now),
            'queues' => $this->queues(),
            'revenue' => $this->revenue($now),
            'receivables' => $this->receivables(),
        ];
    }

    /**
     * Services actually happening today, per domain.
     *
     * The window is a Kampala calendar day converted to UTC, because that is
     * what "today" means to the person reading the screen.
     *
     * @return array<string, mixed>
     */
    private function todaysService(CarbonImmutable $now): array
    {
        $from = $now->startOfDay()->utc();
        $to = $now->endOfDay()->utc();
        $rows = [];
        $total = 0;

        foreach (BookingSource::cases() as $source) {
            // An import has no service date, so counting it here would report
            // months of work as happening today.
            if ($source === BookingSource::VehicleImports) {
                continue;
            }

            $openStatuses = array_merge(
                $source->statusValuesInStage(BookingStage::Confirmed),
                $source->statusValuesInStage(BookingStage::InProgress),
            );

            $count = DB::table($source->table())
                ->whereIn('status', $openStatuses)
                ->whereBetween($source->serviceDateColumn(), [$from, $to])
                ->count();

            $rows[$source->value] = ['label' => $source->label(), 'count' => $count];
            $total += $count;
        }

        return ['sources' => $rows, 'total' => $total];
    }

    /**
     * What is waiting for somebody. These are the numbers that should be zero
     * at the end of a good day.
     *
     * @return array<string, mixed>
     */
    private function queues(): array
    {
        $awaiting = 0;
        $perSource = [];

        foreach (BookingSource::cases() as $source) {
            $count = DB::table($source->table())
                ->whereIn('status', $source->statusValuesInStage(BookingStage::AwaitingAction))
                ->count();

            $perSource[$source->value] = ['label' => $source->label(), 'count' => $count];
            $awaiting += $count;
        }

        return [
            'bookings_awaiting_action' => $awaiting,
            'bookings_by_source' => $perSource,
            'new_quotation_requests' => QuotationRequest::query()
                ->where('status', QuotationRequestStatus::New->value)
                ->count(),
            'quotations_awaiting_customer' => Quotation::query()
                ->where('status', QuotationStatus::Sent->value)
                ->count(),
            'draft_invoices' => Invoice::query()
                ->where('status', InvoiceStatus::Draft->value)
                ->count(),
            'overdue_invoices' => Invoice::query()->overdue()->count(),
            'reviews_awaiting_moderation' => Review::query()
                ->where('status', ReviewStatus::Pending->value)
                ->count(),
            'payments_unreconciled' => Payment::query()
                ->whereIn('status', PaymentStatus::settledValues())
                ->whereDoesntHave('allocations')
                ->count(),
        ];
    }

    /**
     * Settled payments in the last 30 days, grouped by the currency actually
     * collected and by the reporting base currency stamped on each payment.
     *
     * The base figure is safe to add up: it was converted at the rate in force
     * when the money arrived, so historic revenue cannot move when a rate does.
     *
     * @return array<string, mixed>
     */
    private function revenue(CarbonImmutable $now): array
    {
        $since = $now->subDays(30)->startOfDay()->utc();

        // A pure aggregate, so it goes through the query builder rather than
        // hydrating Payment models with columns that are not theirs.
        $rows = DB::table('payments')
            ->whereIn('status', PaymentStatus::settledValues())
            ->where('paid_at', '>=', $since)
            ->selectRaw('currency, count(*) as payments, sum(amount_minor) as collected_minor, sum(base_amount_minor) as base_minor')
            ->groupBy('currency')
            ->get();

        $byCurrency = [];
        $baseTotal = 0;
        $payments = 0;

        foreach ($rows as $row) {
            $byCurrency[$row->currency] = [
                'collected_minor' => (int) $row->collected_minor,
                'payments' => (int) $row->payments,
            ];
            $baseTotal += (int) $row->base_minor;
            $payments += (int) $row->payments;
        }

        ksort($byCurrency);

        return [
            'since' => $since,
            'by_currency' => $byCurrency,
            'base_currency' => (string) config('payments.base_currency', 'UGX'),
            'base_total_minor' => $baseTotal,
            'payments' => $payments,
        ];
    }

    /**
     * Outstanding invoice balances per currency.
     *
     * Summed in PHP through each invoice's own balance rather than in SQL,
     * because the amount owed depends on settled allocations and on whether a
     * deposit stage is still open.
     *
     * @return array<string, array{outstanding_minor: int, overdue_minor: int, count: int}>
     */
    private function receivables(): array
    {
        $totals = [];

        Invoice::query()
            ->outstanding()
            ->with('paymentAllocations.payment:id,status')
            ->chunkById(200, function ($invoices) use (&$totals): void {
                foreach ($invoices as $invoice) {
                    $currency = $invoice->currency;
                    $balance = $invoice->remainingBalanceMinor();

                    $totals[$currency] ??= [
                        'outstanding_minor' => 0,
                        'overdue_minor' => 0,
                        'count' => 0,
                    ];

                    $totals[$currency]['outstanding_minor'] += $balance;
                    $totals[$currency]['count']++;

                    if ($invoice->isOverdue()) {
                        $totals[$currency]['overdue_minor'] += $balance;
                    }
                }
            });

        ksort($totals);

        return $totals;
    }
}
