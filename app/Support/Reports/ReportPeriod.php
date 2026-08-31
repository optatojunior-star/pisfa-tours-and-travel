<?php

namespace App\Support\Reports;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * A reporting window, expressed as the Kampala calendar days an operator typed
 * and the UTC instants actually stored.
 *
 * Keeping both on one object is deliberate: every report needs the UTC bounds
 * for its query and the local dates for its heading, and deriving one from the
 * other at each call site is how a report ends up three hours out.
 */
final class ReportPeriod
{
    private function __construct(
        public readonly CarbonImmutable $startDate,
        public readonly CarbonImmutable $endDate,
        public readonly CarbonImmutable $startsAt,
        public readonly CarbonImmutable $endsAt,
        public readonly string $timezone,
    ) {}

    /** The longest window a single report will build, to bound the query cost. */
    public const MAXIMUM_DAYS = 731;

    public static function between(?string $from, ?string $to, ?string $timezone = null): self
    {
        $timezone ??= (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $now = CarbonImmutable::now($timezone);

        $start = filled($from)
            ? CarbonImmutable::parse((string) $from, $timezone)->startOfDay()
            : $now->subDays(29)->startOfDay();

        $end = filled($to)
            ? CarbonImmutable::parse((string) $to, $timezone)->endOfDay()
            : $now->endOfDay();

        if ($end->isBefore($start)) {
            throw new InvalidArgumentException('The end of the range cannot be before its start.');
        }

        if ($start->diffInDays($end) > self::MAXIMUM_DAYS) {
            throw new InvalidArgumentException(
                'A report covers at most '.self::MAXIMUM_DAYS.' days. Narrow the range.',
            );
        }

        return new self(
            startDate: $start,
            endDate: $end,
            startsAt: $start->utc(),
            endsAt: $end->utc(),
            timezone: $timezone,
        );
    }

    public static function lastDays(int $days, ?string $timezone = null): self
    {
        $timezone ??= (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $now = CarbonImmutable::now($timezone);

        return self::between(
            $now->subDays(max(0, $days - 1))->toDateString(),
            $now->toDateString(),
            $timezone,
        );
    }

    public function days(): int
    {
        return max(1, (int) $this->startDate->diffInDays($this->endDate) + 1);
    }

    public function label(): string
    {
        if ($this->startDate->isSameDay($this->endDate)) {
            return $this->startDate->format('j M Y');
        }

        return $this->startDate->format('j M Y').' – '.$this->endDate->format('j M Y');
    }

    /** The immediately preceding window of the same length, for comparison. */
    public function previous(): self
    {
        $days = $this->days();

        return self::between(
            $this->startDate->subDays($days)->toDateString(),
            $this->startDate->subDay()->toDateString(),
            $this->timezone,
        );
    }

    /** @return array{from: string, to: string} */
    public function toQueryString(): array
    {
        return [
            'from' => $this->startDate->toDateString(),
            'to' => $this->endDate->toDateString(),
        ];
    }
}
