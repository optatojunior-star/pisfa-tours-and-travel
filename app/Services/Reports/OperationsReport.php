<?php

namespace App\Services\Reports;

use App\Enums\BookingStage;
use App\Enums\DriverTripStatus;
use App\Enums\InvoiceStatus;
use App\Enums\MaintenanceStatus;
use App\Enums\QuotationStatus;
use App\Enums\ReviewStatus;
use App\Support\Bookings\BookingSource;
use App\Support\Reports\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Volume rather than value: how much work came in, how much converted, and how
 * the fleet and the reputation held up.
 */
class OperationsReport
{
    /** @return array<string, mixed> */
    public function summary(ReportPeriod $period): array
    {
        return [
            'period' => $period,
            'bookings' => $this->bookings($period),
            'conversion' => $this->conversion($period),
            'reviews' => $this->reviews($period),
            'fleet' => $this->fleet($period),
        ];
    }

    /**
     * Bookings received per domain, broken down by where they ended up.
     *
     * Counted by when the request arrived, not when the service happened: a
     * booking taken in March for a June trip is March's work.
     *
     * @return array<string, mixed>
     */
    private function bookings(ReportPeriod $period): array
    {
        $rows = [];
        $total = 0;

        foreach (BookingSource::cases() as $source) {
            $byStage = [];
            $sourceTotal = 0;

            foreach (BookingStage::cases() as $stage) {
                $statuses = $source->statusValuesInStage($stage);

                $count = $statuses === [] ? 0 : DB::table($source->table())
                    ->whereIn('status', $statuses)
                    ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
                    ->count();

                $byStage[$stage->value] = $count;
                $sourceTotal += $count;
            }

            $rows[$source->value] = [
                'label' => $source->label(),
                'total' => $sourceTotal,
                'by_stage' => $byStage,
            ];
            $total += $sourceTotal;
        }

        return ['total' => $total, 'by_source' => $rows];
    }

    /**
     * How many enquiries turned into money.
     *
     * @return array<string, mixed>
     */
    private function conversion(ReportPeriod $period): array
    {
        $requests = DB::table('quotation_requests')
            ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
            ->count();

        $sent = DB::table('quotations')
            ->whereNotNull('sent_at')
            ->whereBetween('sent_at', [$period->startsAt, $period->endsAt])
            ->count();

        $accepted = DB::table('quotations')
            ->where('status', QuotationStatus::Accepted->value)
            ->whereNotNull('accepted_at')
            ->whereBetween('accepted_at', [$period->startsAt, $period->endsAt])
            ->count();

        $invoiced = DB::table('invoices')
            ->whereNotNull('issued_at')
            ->whereBetween('issued_at', [$period->startsAt, $period->endsAt])
            ->count();

        $paid = DB::table('invoices')
            ->where('status', InvoiceStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$period->startsAt, $period->endsAt])
            ->count();

        return [
            'requests' => $requests,
            'quotations_sent' => $sent,
            'quotations_accepted' => $accepted,
            'invoices_issued' => $invoiced,
            'invoices_paid' => $paid,
            // A rate needs a denominator; without one it is a division by zero
            // dressed up as a percentage.
            'acceptance_rate' => $sent > 0 ? (int) round($accepted / $sent * 100) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function reviews(ReportPeriod $period): array
    {
        $published = DB::table('reviews')
            ->whereNull('deleted_at')
            ->where('status', ReviewStatus::Published->value)
            ->whereNotNull('published_at')
            ->whereBetween('published_at', [$period->startsAt, $period->endsAt])
            ->selectRaw('count(*) as reviews, coalesce(sum(rating), 0) as rating_sum')
            ->first();

        $count = (int) ($published->reviews ?? 0);
        $sum = (int) ($published->rating_sum ?? 0);

        return [
            'published' => $count,
            // Exact from an integer sum and count, with no stored float.
            'average_rating' => $count > 0 ? round($sum / $count, 1) : null,
            'submitted' => DB::table('reviews')
                ->whereNull('deleted_at')
                ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
                ->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function fleet(ReportPeriod $period): array
    {
        $trips = DB::table('driver_trips')
            ->where('status', DriverTripStatus::Completed->value)
            ->whereBetween('completed_at', [$period->startsAt, $period->endsAt])
            ->selectRaw('count(*) as trips, coalesce(sum(distance_km), 0) as distance_km')
            ->first();

        $defects = DB::table('vehicle_inspections')
            ->where('has_defects', true)
            ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
            ->count();

        $jobs = DB::table('vehicle_maintenance_records')
            ->where('status', MaintenanceStatus::Completed->value)
            ->whereBetween('completed_at', [$period->startsAt, $period->endsAt])
            ->count();

        return [
            'trips_completed' => (int) ($trips->trips ?? 0),
            'distance_km' => (int) ($trips->distance_km ?? 0),
            'defects_reported' => $defects,
            'maintenance_completed' => $jobs,
        ];
    }
}
