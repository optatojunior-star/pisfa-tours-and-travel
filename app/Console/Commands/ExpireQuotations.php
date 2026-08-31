<?php

namespace App\Console\Commands;

use App\Actions\Billing\TransitionQuotation;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use Illuminate\Console\Command;
use Throwable;

/**
 * Relabels sent quotations whose validity date has passed.
 *
 * This is bookkeeping, not enforcement: TransitionQuotation::respond already
 * refuses to accept an offer past its date, so a customer can never lock in an
 * expired price even if this sweep has not run.
 */
class ExpireQuotations extends Command
{
    protected $signature = 'quotations:expire';

    protected $description = 'Mark sent quotations expired once their validity date has passed';

    public function handle(TransitionQuotation $action): int
    {
        $now = now();
        $expired = 0;
        $failures = 0;

        Quotation::query()
            ->where('status', QuotationStatus::Sent->value)
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', $now->format('Y-m-d'))
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($quotations) use ($action, &$expired, &$failures): void {
                foreach ($quotations as $quotation) {
                    try {
                        $expired += $action->expire($quotation) ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error(
                            "Quotation {$quotation->getKey()} could not be expired and remains sent.",
                        );
                    }
                }
            });

        $this->components->info("Quotations: {$expired} expired; {$failures} failure(s).");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
