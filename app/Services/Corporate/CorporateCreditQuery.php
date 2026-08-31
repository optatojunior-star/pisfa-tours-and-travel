<?php

namespace App\Services\Corporate;

use App\Enums\InvoiceStatus;
use App\Models\CorporateAccount;
use App\Models\Invoice;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a company currently owes, computed from live invoices.
 *
 * Never a stored balance column. A stored figure drifts the moment an invoice
 * is voided, a payment lands out of band, or two requests update it at once —
 * and a credit limit checked against a drifted balance is worse than no limit,
 * because it looks like a control while letting the wrong thing through.
 *
 * Outstanding is summed per currency and never across. An account is
 * denominated in one currency; invoices raised in another are reported
 * separately so the desk can see them rather than having them quietly folded
 * into a total that means nothing.
 */
class CorporateCreditQuery
{
    /**
     * The account's position in its own currency.
     *
     * @return array{
     *     currency: string,
     *     limit_minor: int,
     *     outstanding_minor: int,
     *     available_minor: int,
     *     overdue_minor: int,
     *     invoice_count: int,
     *     other_currency_count: int
     * }
     */
    public function position(CorporateAccount $account, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now((string) config('pisfa.business_timezone', 'Africa/Kampala'));
        $currency = strtoupper($account->currency);

        $rows = DB::table('invoices')
            ->where('corporate_account_id', $account->getKey())
            // Only invoices that are actually owed: a draft has not been sent,
            // and a cancelled or voided one is not a debt.
            ->whereIn('status', InvoiceStatus::outstandingValues())
            ->selectRaw('currency, count(*) as invoice_count, sum(total_minor) as billed')
            ->groupBy('currency')
            ->get();

        $billed = 0;
        $invoiceCount = 0;
        $otherCurrencyCount = 0;

        foreach ($rows as $row) {
            if (strtoupper((string) $row->currency) !== $currency) {
                $otherCurrencyCount += (int) $row->invoice_count;

                continue;
            }

            $billed = (int) $row->billed;
            $invoiceCount = (int) $row->invoice_count;
        }

        // What has actually been settled against those invoices. Taken from
        // allocations rather than an invoice column, for the same reason the
        // balance is not stored: it is derived, so it is derived once.
        $settled = $this->settledAgainstOutstanding($account, $currency);

        $outstanding = max(0, $billed - $settled);

        return [
            'currency' => $currency,
            'limit_minor' => $account->credit_limit_minor,
            'outstanding_minor' => $outstanding,
            'available_minor' => max(0, $account->credit_limit_minor - $outstanding),
            'overdue_minor' => $this->overdue($account, $currency, $at),
            'invoice_count' => $invoiceCount,
            'other_currency_count' => $otherCurrencyCount,
        ];
    }

    /** Whether the account can carry another invoice of this size. */
    public function canCarry(CorporateAccount $account, int $amountMinor, string $currency): bool
    {
        if (strtoupper($currency) !== strtoupper($account->currency)) {
            return false;
        }

        $position = $this->position($account);

        return $position['available_minor'] >= $amountMinor;
    }

    /** A sentence the console and the refusal message can both use. */
    public function summary(CorporateAccount $account): string
    {
        $position = $this->position($account);

        return Money::format($position['outstanding_minor'], $position['currency'])
            .' outstanding of '.Money::format($position['limit_minor'], $position['currency'])
            .', leaving '.Money::format($position['available_minor'], $position['currency']).'.';
    }

    /**
     * Settled money on the account's outstanding invoices.
     *
     * Scoped to those invoices rather than to the account as a whole: a payment
     * against an invoice that has since been voided is not credit the company
     * can spend again.
     */
    private function settledAgainstOutstanding(CorporateAccount $account, string $currency): int
    {
        $invoiceIds = Invoice::query()
            ->where('corporate_account_id', $account->getKey())
            ->whereIn('status', InvoiceStatus::outstandingValues())
            ->where('currency', $currency)
            ->pluck('id');

        if ($invoiceIds->isEmpty()) {
            return 0;
        }

        return (int) Invoice::query()
            ->whereIn('id', $invoiceIds)
            ->get()
            ->sum(fn (Invoice $invoice): int => $invoice->settledAmountMinor());
    }

    /** What is past its due date, in the account's own currency. */
    private function overdue(CorporateAccount $account, string $currency, CarbonImmutable $at): int
    {
        $invoices = Invoice::query()
            ->where('corporate_account_id', $account->getKey())
            ->whereIn('status', InvoiceStatus::outstandingValues())
            ->where('currency', $currency)
            ->whereNotNull('due_on')
            ->whereDate('due_on', '<', $at->toDateString())
            ->get();

        return (int) $invoices->sum(
            fn (Invoice $invoice): int => max(0, $invoice->total_minor - $invoice->settledAmountMinor()),
        );
    }
}
