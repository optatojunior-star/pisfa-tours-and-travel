<?php

namespace App\Services\Reports;

use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Invoice;
use App\Support\Bookings\BookingSource;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What PISFA actually collected, and from where.
 *
 * Two figures are reported side by side and never conflated: the amount in the
 * currency it was received in, and the base-currency equivalent stamped on the
 * payment when it settled. Only the second is safe to add up — UGX and USD have
 * different exponents, and the rate that applied is a property of the moment the
 * money arrived, not of today.
 */
class RevenueReport
{
    /** @return array<string, mixed> */
    public function summary(ReportPeriod $period): array
    {
        $current = $this->totals($period);
        $previous = $this->totals($period->previous());

        return [
            'period' => $period,
            'base_currency' => (string) config('payments.base_currency', 'UGX'),
            'current' => $current,
            'previous' => $previous,
            'change_percent' => $this->changePercent(
                $previous['base_total_minor'],
                $current['base_total_minor'],
            ),
            'by_currency' => $this->byCurrency($period),
            'by_provider' => $this->byProvider($period),
            'by_service' => $this->byService($period),
            'daily' => $this->daily($period),
            'refunds' => $this->refunds($period),
        ];
    }

    /** @return array{base_total_minor: int, payments: int} */
    private function totals(ReportPeriod $period): array
    {
        $row = DB::table('payments')
            ->whereIn('status', PaymentStatus::settledValues())
            ->whereBetween('paid_at', [$period->startsAt, $period->endsAt])
            ->selectRaw('count(*) as payments, coalesce(sum(base_amount_minor), 0) as base_total_minor')
            ->first();

        return [
            'payments' => (int) ($row->payments ?? 0),
            'base_total_minor' => (int) ($row->base_total_minor ?? 0),
        ];
    }

    /** @return array<string, array{collected_minor: int, base_minor: int, payments: int}> */
    private function byCurrency(ReportPeriod $period): array
    {
        $rows = DB::table('payments')
            ->whereIn('status', PaymentStatus::settledValues())
            ->whereBetween('paid_at', [$period->startsAt, $period->endsAt])
            ->selectRaw('currency, count(*) as payments, sum(amount_minor) as collected_minor, sum(base_amount_minor) as base_minor')
            ->groupBy('currency')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->currency] = [
                'collected_minor' => (int) $row->collected_minor,
                'base_minor' => (int) $row->base_minor,
                'payments' => (int) $row->payments,
            ];
        }

        ksort($result);

        return $result;
    }

    /** @return array<string, array{base_minor: int, payments: int}> */
    private function byProvider(ReportPeriod $period): array
    {
        $rows = DB::table('payments')
            ->whereIn('status', PaymentStatus::settledValues())
            ->whereBetween('paid_at', [$period->startsAt, $period->endsAt])
            ->selectRaw('provider, count(*) as payments, sum(base_amount_minor) as base_minor')
            ->groupBy('provider')
            ->orderByDesc('base_minor')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->provider] = [
                'base_minor' => (int) $row->base_minor,
                'payments' => (int) $row->payments,
            ];
        }

        return $result;
    }

    /**
     * Revenue attributed to the service it paid for.
     *
     * Grouped by the polymorphic payable type, so a booking, an import, and an
     * invoice each report under their own heading rather than one lump.
     *
     * @return array<string, array{base_minor: int, payments: int}>
     */
    private function byService(ReportPeriod $period): array
    {
        $rows = DB::table('payments')
            ->whereIn('status', PaymentStatus::settledValues())
            ->whereBetween('paid_at', [$period->startsAt, $period->endsAt])
            ->selectRaw('payable_type, count(*) as payments, sum(base_amount_minor) as base_minor')
            ->groupBy('payable_type')
            ->orderByDesc('base_minor')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[$this->serviceLabel((string) $row->payable_type)] = [
                'base_minor' => (int) $row->base_minor,
                'payments' => (int) $row->payments,
            ];
        }

        return $result;
    }

    /**
     * A base-currency total per Kampala calendar day, for the trend row.
     *
     * Bucketed in PHP rather than with a SQL date function, because the offset
     * has to be applied before the day is decided and `date()` in SQLite and
     * MySQL would each need different syntax to do it.
     *
     * @return array<string, int>
     */
    private function daily(ReportPeriod $period): array
    {
        $buckets = [];
        $cursor = $period->startDate;

        while (! $cursor->isAfter($period->endDate)) {
            $buckets[$cursor->toDateString()] = 0;
            $cursor = $cursor->addDay();
        }

        DB::table('payments')
            ->whereIn('status', PaymentStatus::settledValues())
            ->whereBetween('paid_at', [$period->startsAt, $period->endsAt])
            ->orderBy('id')
            ->select(['paid_at', 'base_amount_minor'])
            ->chunk(500, function ($payments) use (&$buckets, $period): void {
                foreach ($payments as $payment) {
                    if ($payment->paid_at === null) {
                        continue;
                    }

                    $day = CarbonImmutable::parse($payment->paid_at)
                        ->timezone($period->timezone)
                        ->toDateString();

                    if (array_key_exists($day, $buckets)) {
                        $buckets[$day] += (int) $payment->base_amount_minor;
                    }
                }
            });

        return $buckets;
    }

    /**
     * Refunds in the window, per currency.
     *
     * Reported separately rather than netted off revenue: a month with heavy
     * refunds against last month's takings is a fact worth seeing, not one to
     * bury in a smaller net figure.
     *
     * @return array<string, array{amount_minor: int, count: int}>
     */
    private function refunds(ReportPeriod $period): array
    {
        // Only money that actually went back counts: a pending or failed
        // refund has not left the account.
        $rows = DB::table('refunds')
            ->where('status', RefundStatus::Completed->value)
            ->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$period->startsAt, $period->endsAt])
            ->selectRaw('currency, count(*) as count, sum(amount_minor) as amount_minor')
            ->groupBy('currency')
            ->get();

        $result = [];

        foreach ($rows as $row) {
            $result[(string) $row->currency] = [
                'amount_minor' => (int) $row->amount_minor,
                'count' => (int) $row->count,
            ];
        }

        ksort($result);

        return $result;
    }

    private function serviceLabel(string $morphClass): string
    {
        foreach (BookingSource::cases() as $case) {
            if ((new ($case->model()))->getMorphClass() === $morphClass) {
                return $case->label();
            }
        }

        return $morphClass === (new Invoice)->getMorphClass()
            ? 'Invoice'
            : class_basename($morphClass);
    }

    /** Null when there is no previous figure to compare against. */
    private function changePercent(int $previous, int $current): ?int
    {
        if ($previous < 1) {
            return null;
        }

        return (int) round(($current - $previous) / $previous * 100);
    }
}
