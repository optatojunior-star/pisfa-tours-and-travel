<?php

namespace App\Actions\Loyalty;

use App\Enums\LoyaltyTransactionType;
use App\Enums\ReferralStatus;
use App\Models\LoyaltyAccount;
use App\Models\Referral;
use App\Models\User;
use App\Services\Payments\ExchangeRateResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Converts a settled booking into points.
 *
 * Reads the durable `LoyaltyEligible` markers that the tour, car-hire,
 * airport-transfer, and vehicle-import domains already write with
 * `firstOrCreate`. Those markers are the idempotency anchor: awarding is keyed
 * on the marker row, so replaying a webhook or re-running the command cannot
 * award twice.
 */
class AwardLoyaltyForEvent
{
    public function __construct(
        private readonly RecordLoyaltyMovement $ledger,
        private readonly ExchangeRateResolver $rates,
    ) {}

    /**
     * @param  Model  $event  The domain's LoyaltyEligible event row.
     * @return bool True when points were awarded on this call.
     */
    public function execute(Model $event): bool
    {
        $payload = (array) ($event->getAttribute('payload') ?? []);
        $customerId = $payload['customer_id'] ?? null;

        // A guest booking earns nothing: there is no account to credit. The
        // marker is still consumed so it does not requeue forever.
        if ($customerId === null) {
            $this->markProcessed($event);

            return false;
        }

        $customer = User::query()->find($customerId);

        if ($customer === null) {
            $this->markProcessed($event);

            return false;
        }

        $points = $this->pointsFor($payload);

        if ($points < 1) {
            $this->markProcessed($event);

            return false;
        }

        $account = LoyaltyAccount::forUser($customer);
        $reference = (string) ($payload['booking_reference'] ?? $payload['order_reference'] ?? '');

        $transaction = $this->ledger->execute(
            account: $account,
            type: LoyaltyTransactionType::Earned,
            points: $points,
            description: 'Points earned on '.($reference !== '' ? $reference : 'a completed booking').'.',
            // Keyed on the marker row itself, so the same event can never be
            // awarded twice however many times this runs.
            idempotencyKey: 'event:'.$event->getMorphClass().':'.$event->getKey(),
            source: $event,
            payload: ['reference' => $reference],
        );

        $this->markProcessed($event);

        if ($transaction !== null) {
            $this->qualifyReferral($customer);
        }

        return $transaction !== null;
    }

    /**
     * Points are derived from the base-currency amount, so a later exchange
     * rate change cannot alter what a past booking earned.
     *
     * @param  array<string, mixed>  $payload
     */
    private function pointsFor(array $payload): int
    {
        $amount = (int) ($payload['total_minor'] ?? $payload['amount_minor'] ?? 0);
        $currency = strtoupper((string) ($payload['currency'] ?? $this->rates->baseCurrency()));

        if ($amount < 1) {
            return 0;
        }

        $base = $currency === $this->rates->baseCurrency()
            ? $amount
            : $this->rates->convert($amount, $currency, $this->rates->baseCurrency());

        $divisor = (int) config('loyalty.minor_units_per_point', 10_000);

        // Floor rather than round: a customer is never credited for value they
        // did not spend.
        return intdiv($base, max(1, $divisor));
    }

    /**
     * A first completed booking is what qualifies the referrer's reward. This
     * only moves the referral to Qualified; paying the reward is a separate
     * step so a failure there cannot lose the qualification.
     */
    private function qualifyReferral(User $customer): void
    {
        DB::transaction(function () use ($customer): void {
            $referral = Referral::query()
                ->where('referred_user_id', $customer->getKey())
                ->lockForUpdate()
                ->first();

            if ($referral === null
                || ! $referral->canTransitionTo(ReferralStatus::Qualified)) {
                return;
            }

            $referral->forceFill([
                'status' => ReferralStatus::Qualified,
                'qualified_at' => now(),
            ])->save();
        }, 3);
    }

    private function markProcessed(Model $event): void
    {
        if ($event->getAttribute('processed_at') === null) {
            $event->forceFill(['processed_at' => now()])->save();
        }
    }
}
