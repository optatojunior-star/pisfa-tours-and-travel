<?php

namespace App\Console\Commands;

use App\Actions\Loyalty\AwardLoyaltyForEvent;
use App\Actions\Loyalty\RecordLoyaltyMovement;
use App\Enums\AirportTransferEventType;
use App\Enums\CarHireBookingEventType;
use App\Enums\LoyaltyTransactionType;
use App\Enums\ReferralStatus;
use App\Enums\TourBookingEventType;
use App\Enums\VehicleImportEventType;
use App\Models\AirportTransferEvent;
use App\Models\CarHireBookingEvent;
use App\Models\LoyaltyAccount;
use App\Models\Referral;
use App\Models\TourBookingEvent;
use App\Models\VehicleImportEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Converts unprocessed loyalty-eligibility markers into points, then pays any
 * referral rewards that have qualified.
 *
 * Safe to run repeatedly: awarding is keyed on the marker row and referral
 * rewards on the referral row, so a second run is a no-op.
 */
class ProcessLoyaltyAwards extends Command
{
    protected $signature = 'loyalty:process-awards';

    protected $description = 'Award loyalty points for settled bookings and pay qualified referral rewards';

    public function handle(AwardLoyaltyForEvent $award, RecordLoyaltyMovement $ledger): int
    {
        $awarded = 0;
        $failures = 0;

        foreach ($this->sources() as [$query, $label]) {
            $query->orderBy('id')->select('id')->chunkById(100, function ($rows) use (
                $award, $label, &$awarded, &$failures
            ): void {
                foreach ($rows as $row) {
                    try {
                        $event = $row->newQuery()->whereKey($row->getKey())->first();

                        if ($event !== null && $award->execute($event)) {
                            $awarded++;
                        }
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error("{$label} event {$row->getKey()} remains retryable.");
                    }
                }
            });
        }

        [$rewarded, $rewardFailures] = $this->payReferralRewards($ledger);
        $failures += $rewardFailures;

        $this->components->info(
            "Loyalty: {$awarded} booking award(s); {$rewarded} referral reward(s); {$failures} failure(s)."
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Every domain that records a loyalty-eligibility marker.
     *
     * @return list<array{0: Builder, 1: string}>
     */
    private function sources(): array
    {
        return [
            [
                TourBookingEvent::query()
                    ->where('event_type', TourBookingEventType::LoyaltyEligible->value)
                    ->whereNull('processed_at'),
                'Tour',
            ],
            [
                CarHireBookingEvent::query()
                    ->where('event_type', CarHireBookingEventType::LoyaltyEligible->value)
                    ->whereNull('processed_at'),
                'Car hire',
            ],
            [
                AirportTransferEvent::query()
                    ->where('event_type', AirportTransferEventType::LoyaltyEligible->value)
                    ->whereNull('processed_at'),
                'Airport transfer',
            ],
            [
                VehicleImportEvent::query()
                    ->where('event_type', VehicleImportEventType::LoyaltyEligible->value)
                    ->whereNull('processed_at'),
                'Vehicle import',
            ],
        ];
    }

    /** @return array{int, int} */
    private function payReferralRewards(RecordLoyaltyMovement $ledger): array
    {
        $points = (int) config('loyalty.referral.reward_points', 200);
        $paid = 0;
        $failures = 0;

        if ($points < 1) {
            return [0, 0];
        }

        Referral::query()
            ->awaitingReward()
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($rows) use ($ledger, $points, &$paid, &$failures): void {
                foreach ($rows as $row) {
                    try {
                        $done = DB::transaction(function () use ($ledger, $points, $row): bool {
                            $referral = Referral::query()
                                ->with('referrerAccount')
                                ->whereKey($row->getKey())
                                ->lockForUpdate()
                                ->first();

                            $account = $referral?->referrerAccount;

                            if ($referral === null
                                || ! $referral->canTransitionTo(ReferralStatus::Rewarded)
                                || ! $account instanceof LoyaltyAccount) {
                                return false;
                            }

                            $ledger->execute(
                                account: $account,
                                type: LoyaltyTransactionType::ReferralReward,
                                points: $points,
                                description: 'Referral reward for a friend’s first completed booking.',
                                idempotencyKey: 'referral-reward:'.$referral->getKey(),
                                source: $referral,
                            );

                            $referral->forceFill([
                                'status' => ReferralStatus::Rewarded,
                                'rewarded_at' => now(),
                            ])->save();

                            return true;
                        }, 3);

                        $paid += $done ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                    }
                }
            });

        return [$paid, $failures];
    }
}
