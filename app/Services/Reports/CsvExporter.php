<?php

namespace App\Services\Reports;

use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Bookings\BookingSource;
use App\Support\Export\ExportDataset;
use App\Support\Export\StreamedCsv;
use App\Support\Money;
use App\Support\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a dataset as CSV.
 *
 * Every export is chunked, so memory use is flat regardless of how much data is
 * behind it, and every export is audited: taking a copy of customer or payment
 * data out of the system is a data-access event, not a page view.
 *
 * Money is written as a decimal string in its own currency with the currency in
 * a neighbouring column. A single "amount" column mixing UGX and USD would be
 * summed by whoever opened it, and the answer would be wrong.
 */
class CsvExporter
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function stream(User $actor, ExportDataset $dataset, ReportPeriod $period): StreamedResponse
    {
        if (! $dataset->isAvailableTo($actor)) {
            throw new AuthorizationException;
        }

        $this->auditLogger->record(
            event: 'export.generated',
            auditable: $actor,
            newValues: [
                'dataset' => $dataset->value,
                'from' => $period->startDate->toDateString(),
                'to' => $period->endDate->toDateString(),
            ],
            user: $actor,
        );

        $filename = 'pisfa-'.$dataset->value.'-'
            .$period->startDate->toDateString().'-to-'.$period->endDate->toDateString().'.csv';

        return match ($dataset) {
            ExportDataset::Bookings => $this->bookings($filename, $period),
            ExportDataset::Payments => $this->payments($filename, $period),
            ExportDataset::Invoices => $this->invoices($filename, $period),
            ExportDataset::Customers => $this->customers($filename, $period),
            ExportDataset::Fleet => $this->fleet($filename, $period),
        };
    }

    private function bookings(string $filename, ReportPeriod $period): StreamedResponse
    {
        return StreamedCsv::download($filename, [
            'Service', 'Reference', 'Status', 'Customer', 'Email', 'Guest',
            'Service date', 'Amount', 'Currency', 'Received',
        ], function (callable $write) use ($period): void {
            foreach (BookingSource::cases() as $source) {
                DB::table($source->table())
                    ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
                    ->orderBy('id')
                    ->chunkById(500, function ($rows) use ($write, $source, $period): void {
                        foreach ($rows as $row) {
                            $currency = $row->{$source->currencyColumn()} ?? 'UGX';
                            $amount = (int) ($row->{$source->amountColumn()} ?? 0);
                            $serviceDate = $row->{$source->serviceDateColumn()} ?? null;

                            $write([
                                $source->label(),
                                $row->reference,
                                $source->statusLabel((string) $row->status),
                                $row->contact_name,
                                $row->contact_email,
                                $row->customer_id === null ? 'yes' : 'no',
                                $this->localDate($serviceDate, $period),
                                Money::forInput($amount, (string) $currency),
                                $currency,
                                $this->localDate($row->created_at, $period),
                            ]);
                        }
                    });
            }
        });
    }

    private function payments(string $filename, ReportPeriod $period): StreamedResponse
    {
        $base = (string) config('payments.base_currency', 'UGX');

        return StreamedCsv::download($filename, [
            'Reference', 'Status', 'Provider', 'Amount', 'Currency',
            'Base amount', 'Base currency', 'Paid at', 'Created at',
        ], function (callable $write) use ($period, $base): void {
            DB::table('payments')
                ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
                ->orderBy('id')
                // Deliberately no provider reference, webhook payload, or
                // idempotency material: an export must never carry a secret.
                ->select([
                    'id', 'reference', 'status', 'provider', 'amount_minor',
                    'currency', 'base_amount_minor', 'paid_at', 'created_at',
                ])
                ->chunkById(500, function ($rows) use ($write, $period, $base): void {
                    foreach ($rows as $row) {
                        $write([
                            $row->reference,
                            PaymentStatus::tryFrom((string) $row->status)?->label() ?? $row->status,
                            $row->provider,
                            Money::forInput((int) $row->amount_minor, (string) $row->currency),
                            $row->currency,
                            Money::forInput((int) $row->base_amount_minor, $base),
                            $base,
                            $this->localDate($row->paid_at, $period),
                            $this->localDate($row->created_at, $period),
                        ]);
                    }
                });
        });
    }

    private function invoices(string $filename, ReportPeriod $period): StreamedResponse
    {
        return StreamedCsv::download($filename, [
            'Number', 'Status', 'Billed to', 'Email', 'Title',
            'Total', 'Balance', 'Currency', 'Issued', 'Due', 'Overdue',
        ], function (callable $write) use ($period): void {
            Invoice::query()
                ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
                ->with('paymentAllocations.payment:id,status')
                ->orderBy('id')
                ->chunkById(200, function ($invoices) use ($write): void {
                    foreach ($invoices as $invoice) {
                        $write([
                            $invoice->number,
                            $invoice->status->label(),
                            $invoice->contact_name,
                            $invoice->contact_email,
                            $invoice->title,
                            Money::forInput((int) $invoice->total_minor, $invoice->currency),
                            Money::forInput($invoice->remainingBalanceMinor(), $invoice->currency),
                            $invoice->currency,
                            $invoice->issued_on?->toDateString(),
                            $invoice->due_on?->toDateString(),
                            $invoice->isOverdue() ? 'yes' : 'no',
                        ]);
                    }
                });
        });
    }

    private function customers(string $filename, ReportPeriod $period): StreamedResponse
    {
        $base = (string) config('payments.base_currency', 'UGX');

        return StreamedCsv::download($filename, [
            'Name', 'Email', 'Phone', 'Status', 'Registered',
            'Payments', 'Lifetime value', 'Currency', 'Loyalty points', 'Tier',
        ], function (callable $write) use ($period, $base): void {
            User::query()
                ->where('role', UserRole::Customer->value)
                ->whereBetween('created_at', [$period->startsAt, $period->endsAt])
                ->with('loyaltyAccount')
                ->orderBy('id')
                // Explicit columns: never the password hash, the remember token,
                // or the two-factor secret.
                ->select(['id', 'name', 'email', 'phone', 'status', 'created_at'])
                ->chunkById(500, function ($customers) use ($write, $period, $base): void {
                    foreach ($customers as $customer) {
                        $totals = DB::table('payments')
                            ->where('customer_id', $customer->getKey())
                            ->whereIn('status', PaymentStatus::settledValues())
                            ->selectRaw('count(*) as payments, coalesce(sum(base_amount_minor), 0) as base_minor')
                            ->first();

                        $write([
                            $customer->name,
                            $customer->email,
                            $customer->phone,
                            $customer->status->label(),
                            $this->localDate($customer->created_at, $period),
                            (int) ($totals->payments ?? 0),
                            Money::forInput((int) ($totals->base_minor ?? 0), $base),
                            $base,
                            $customer->loyaltyAccount->points_balance ?? 0,
                            $customer->loyaltyAccount?->tier->label() ?? '',
                        ]);
                    }
                });
        });
    }

    private function fleet(string $filename, ReportPeriod $period): StreamedResponse
    {
        return StreamedCsv::download($filename, [
            'Vehicle', 'Plate', 'Record', 'Type', 'Detail',
            'Odometer km', 'Cost', 'Currency', 'Date',
        ], function (callable $write) use ($period): void {
            DB::table('vehicle_maintenance_records')
                ->join('vehicles', 'vehicles.id', '=', 'vehicle_maintenance_records.vehicle_id')
                ->whereNotNull('vehicle_maintenance_records.completed_at')
                ->whereBetween('vehicle_maintenance_records.completed_at', [$period->startsAt, $period->endsAt])
                ->orderBy('vehicle_maintenance_records.id')
                ->select([
                    'vehicle_maintenance_records.id',
                    'vehicles.make', 'vehicles.model', 'vehicles.registration_plate',
                    'vehicle_maintenance_records.type', 'vehicle_maintenance_records.title',
                    'vehicle_maintenance_records.odometer_km', 'vehicle_maintenance_records.cost_minor',
                    'vehicle_maintenance_records.currency', 'vehicle_maintenance_records.completed_at',
                ])
                ->chunkById(500, function ($rows) use ($write, $period): void {
                    foreach ($rows as $row) {
                        $write([
                            trim($row->make.' '.$row->model),
                            $row->registration_plate,
                            'Maintenance',
                            $row->type,
                            $row->title,
                            $row->odometer_km,
                            Money::forInput((int) $row->cost_minor, (string) $row->currency),
                            $row->currency,
                            $this->localDate($row->completed_at, $period),
                        ]);
                    }
                }, 'vehicle_maintenance_records.id', 'id');

            DB::table('vehicle_fuel_logs')
                ->join('vehicles', 'vehicles.id', '=', 'vehicle_fuel_logs.vehicle_id')
                ->whereBetween('vehicle_fuel_logs.filled_at', [$period->startsAt, $period->endsAt])
                ->orderBy('vehicle_fuel_logs.id')
                ->select([
                    'vehicle_fuel_logs.id',
                    'vehicles.make', 'vehicles.model', 'vehicles.registration_plate',
                    'vehicle_fuel_logs.volume_ml', 'vehicle_fuel_logs.odometer_km',
                    'vehicle_fuel_logs.cost_minor', 'vehicle_fuel_logs.currency',
                    'vehicle_fuel_logs.filled_at',
                ])
                ->chunkById(500, function ($rows) use ($write, $period): void {
                    foreach ($rows as $row) {
                        $write([
                            trim($row->make.' '.$row->model),
                            $row->registration_plate,
                            'Fuel',
                            'fuel',
                            number_format((int) $row->volume_ml / 1000, 2).' L',
                            $row->odometer_km,
                            Money::forInput((int) $row->cost_minor, (string) $row->currency),
                            $row->currency,
                            $this->localDate($row->filled_at, $period),
                        ]);
                    }
                }, 'vehicle_fuel_logs.id', 'id');
        });
    }

    /** Stored UTC, rendered in the business timezone the report is labelled in. */
    private function localDate(mixed $value, ReportPeriod $period): string
    {
        if ($value === null) {
            return '';
        }

        return CarbonImmutable::parse($value)
            ->timezone($period->timezone)
            ->format('Y-m-d H:i');
    }
}
