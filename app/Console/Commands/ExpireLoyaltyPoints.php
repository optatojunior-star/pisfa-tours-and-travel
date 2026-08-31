<?php

namespace App\Console\Commands;

use App\Actions\Loyalty\RecordLoyaltyMovement;
use App\Enums\LoyaltyTransactionType;
use App\Models\LoyaltyAccount;
use App\Notifications\Loyalty\LoyaltyPointsExpiredNotification;
use App\Notifications\Loyalty\LoyaltyPointsExpiringNotification;
use Illuminate\Console\Command;
use Throwable;

/**
 * Warns about, then expires, balances that have gone untouched for the
 * configured inactivity window.
 *
 * Both halves are idempotent: the warning is gated on `expiry_warned_at` being
 * older than the last activity, and expiry writes a ledger movement keyed on
 * the account and month, so a second run in the same period does nothing.
 */
class ExpireLoyaltyPoints extends Command
{
    protected $signature = 'loyalty:expire-points';

    protected $description = 'Warn about and expire loyalty points after the configured inactivity window';

    public function handle(RecordLoyaltyMovement $ledger): int
    {
        $warned = $this->sendWarnings();
        [$expired, $failures] = $this->expire($ledger);

        $this->components->info(
            "Loyalty expiry: {$warned} warning(s); {$expired} balance(s) expired; {$failures} failure(s)."
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function sendWarnings(): int
    {
        $sent = 0;

        LoyaltyAccount::query()
            ->dueExpiryWarning()
            ->with('user')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use (&$sent): void {
                foreach ($accounts as $account) {
                    if ($account->user === null) {
                        continue;
                    }

                    $account->user->notify(new LoyaltyPointsExpiringNotification(
                        recipientName: $account->user->name,
                        points: $account->points_balance,
                        value: $account->formattedBalanceValue(),
                        expiresOn: $account->expiresOn()?->toIso8601String() ?? '',
                    ));

                    // Stamped after sending, and compared against last activity
                    // so a customer who returns and lapses again is warned once
                    // more rather than never again.
                    $account->forceFill(['expiry_warned_at' => now()])->save();
                    $sent++;
                }
            });

        return $sent;
    }

    /** @return array{int, int} */
    private function expire(RecordLoyaltyMovement $ledger): array
    {
        $expired = 0;
        $failures = 0;
        $period = now()->format('Y-m');

        LoyaltyAccount::query()
            ->expirable()
            ->with('user')
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($ledger, $period, &$expired, &$failures): void {
                foreach ($accounts as $account) {
                    try {
                        $balance = $account->points_balance;

                        if ($balance < 1) {
                            continue;
                        }

                        $movement = $ledger->execute(
                            account: $account,
                            type: LoyaltyTransactionType::Expired,
                            points: $balance,
                            description: number_format($balance)
                                .' points expired after '
                                .config('loyalty.expiry.inactivity_months', 12)
                                .' months of inactivity.',
                            // Keyed by month so a re-run in the same period is
                            // a no-op rather than a second expiry.
                            idempotencyKey: 'expiry:'.$period,
                            payload: ['expired_points' => $balance],
                        );

                        if ($movement === null) {
                            continue;
                        }

                        $expired++;

                        $account->user?->notify(new LoyaltyPointsExpiredNotification(
                            recipientName: $account->user->name,
                            points: $balance,
                        ));
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error("Account {$account->getKey()} remains retryable.");
                    }
                }
            });

        return [$expired, $failures];
    }
}
