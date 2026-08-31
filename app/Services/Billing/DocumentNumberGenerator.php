<?php

namespace App\Services\Billing;

use App\Models\NumberSequence;
use DateTimeInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Issues the next number in a per-year series, e.g. INV-2026-00042.
 *
 * A finance document cannot take its number from an auto-increment id or a
 * random string: the series has to be contiguous and predictable to anyone
 * auditing it. The counter row is therefore locked for the duration of the
 * caller's transaction, which serialises concurrent issuers.
 *
 * This must be called inside the transaction that persists the document. If the
 * document write rolls back, the counter rolls back with it and the number is
 * not burnt.
 */
class DocumentNumberGenerator
{
    public function next(string $prefix, ?DateTimeInterface $at = null): string
    {
        $prefix = strtoupper(trim($prefix));
        $period = ($at ?? now())->format('Y');

        $sequence = $this->lockedSequence($prefix, $period);

        $value = $sequence->next_value;
        $sequence->forceFill(['next_value' => $value + 1])->save();

        return sprintf(
            '%s-%s-%0'.$this->pad().'d',
            $prefix,
            $period,
            $value,
        );
    }

    private function lockedSequence(string $prefix, string $period): NumberSequence
    {
        $existing = NumberSequence::query()
            ->where('prefix', $prefix)
            ->where('period', $period)
            ->lockForUpdate()
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            NumberSequence::query()->create([
                'prefix' => $prefix,
                'period' => $period,
                'next_value' => 1,
            ]);
        } catch (QueryException) {
            // Another transaction created the series first. The unique index
            // caught it, so fall through and lock what they inserted.
        }

        return NumberSequence::query()
            ->where('prefix', $prefix)
            ->where('period', $period)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function pad(): int
    {
        return max(3, min(10, (int) config('billing.numbering.pad', 5)));
    }

    /** Convenience wrapper for callers that are not already in a transaction. */
    public function nextInTransaction(string $prefix, ?DateTimeInterface $at = null): string
    {
        return DB::transaction(fn (): string => $this->next($prefix, $at), 3);
    }
}
