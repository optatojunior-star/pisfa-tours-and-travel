<?php

namespace App\Services\Dashboard;

use App\Enums\BookingStage;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Support\Bookings\BookingSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What one customer actually has with PISFA right now.
 *
 * Only that customer's own rows, scoped by `customer_id` on every query. There
 * is no cross-customer aggregate here that a wrong filter could leak.
 */
class CustomerSnapshot
{
    /** @return array<string, mixed> */
    public function forCustomer(User $customer): array
    {
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $now = CarbonImmutable::now($timezone);

        return [
            'timezone' => $timezone,
            'upcoming' => $this->upcoming($customer, $now),
            'open_bookings' => $this->openBookingCount($customer),
            'quotations_awaiting_response' => Quotation::query()
                ->forCustomer($customer)
                ->where('status', QuotationStatus::Sent->value)
                ->count(),
            'invoices_due' => $this->invoicesDue($customer),
            'loyalty' => $this->loyalty($customer),
        ];
    }

    /**
     * The next few services across every domain, soonest first.
     *
     * @return list<array<string, mixed>>
     */
    private function upcoming(User $customer, CarbonImmutable $now): array
    {
        $from = $now->utc();
        $rows = [];

        foreach (BookingSource::cases() as $source) {
            // An import runs for months and has no service date, so it would
            // never be "upcoming" in any useful sense.
            if ($source === BookingSource::VehicleImports) {
                continue;
            }

            $serviceDate = $source->serviceDateColumn();
            $openStatuses = array_merge(
                $source->statusValuesInStage(BookingStage::AwaitingAction),
                $source->statusValuesInStage(BookingStage::Confirmed),
                $source->statusValuesInStage(BookingStage::InProgress),
            );

            $records = DB::table($source->table())
                ->where('customer_id', $customer->getKey())
                ->whereIn('status', $openStatuses)
                ->where($serviceDate, '>=', $from)
                ->orderBy($serviceDate)
                ->limit(5)
                ->get(['reference', 'status', $serviceDate.' as service_date', $source->summaryColumn().' as summary']);

            foreach ($records as $record) {
                $rows[] = [
                    'source' => $source,
                    'reference' => $record->reference,
                    'status_label' => $source->statusLabel((string) $record->status),
                    'service_date' => CarbonImmutable::parse($record->service_date),
                    'summary' => $record->summary,
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['service_date'] <=> $b['service_date']);

        return array_slice($rows, 0, 5);
    }

    private function openBookingCount(User $customer): int
    {
        $total = 0;

        foreach (BookingSource::cases() as $source) {
            $openStatuses = array_merge(
                $source->statusValuesInStage(BookingStage::AwaitingAction),
                $source->statusValuesInStage(BookingStage::Confirmed),
                $source->statusValuesInStage(BookingStage::InProgress),
            );

            $total += DB::table($source->table())
                ->where('customer_id', $customer->getKey())
                ->whereIn('status', $openStatuses)
                ->count();
        }

        return $total;
    }

    /**
     * Invoices with money still owing, per currency. Never summed across
     * currencies: UGX and USD have different exponents.
     *
     * @return array{count: int, by_currency: array<string, int>, overdue: int}
     */
    private function invoicesDue(User $customer): array
    {
        $byCurrency = [];
        $count = 0;
        $overdue = 0;

        Invoice::query()
            ->forCustomer($customer)
            ->whereIn('status', InvoiceStatus::outstandingValues())
            ->with('paymentAllocations.payment:id,status')
            ->chunkById(100, function ($invoices) use (&$byCurrency, &$count, &$overdue): void {
                foreach ($invoices as $invoice) {
                    $balance = $invoice->remainingBalanceMinor();

                    if ($balance < 1) {
                        continue;
                    }

                    $byCurrency[$invoice->currency] ??= 0;
                    $byCurrency[$invoice->currency] += $balance;
                    $count++;

                    if ($invoice->isOverdue()) {
                        $overdue++;
                    }
                }
            });

        ksort($byCurrency);

        return ['count' => $count, 'by_currency' => $byCurrency, 'overdue' => $overdue];
    }

    /** @return array{points: int, tier_label: string}|null */
    private function loyalty(User $customer): ?array
    {
        $account = $customer->loyaltyAccount;

        if ($account === null) {
            return null;
        }

        return [
            'points' => (int) $account->points_balance,
            'tier_label' => $account->tier->label(),
        ];
    }
}
