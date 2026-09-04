<?php

namespace App\Services\Bookings;

use App\Enums\BookingStage;
use App\Support\Bookings\BookingSource;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * One list across tours, car hire, airport transfers, vehicle imports,
 * accommodation, and group bookings.
 *
 * Built as a UNION of projections over the live domain tables rather than a
 * materialised index table. An index would be faster, but it can drift: a status
 * change that forgets to update it makes the console quietly wrong, which for an
 * operations screen is worse than a slower query. The domain tables are the only
 * source of truth here.
 *
 * Every projected column is bound or drawn from an enum-controlled allowlist,
 * never interpolated from the request.
 */
class UnifiedBookingQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 25): LengthAwarePaginator
    {
        $sources = $this->selectedSources($filters['source'] ?? null);
        $union = null;

        foreach ($sources as $source) {
            $projection = $this->projectionFor($source, $filters);

            $union = $union === null ? $projection : $union->unionAll($projection);
        }

        if ($union === null) {
            // No source matched, so there is nothing to union. Paginating an
            // empty derived table keeps the caller's contract intact.
            return DB::query()->selectRaw('1')->whereRaw('1 = 0')->paginate($perPage);
        }

        // Mapped to the column the union actually projects. The sort key is
        // part of the public query string, so 'amount' stays the name a caller
        // uses, but the union aliases the value as amount_minor and MySQL will
        // not order by a column that is not in the result set.
        $sortable = [
            'service_date' => 'service_date',
            'created_at' => 'created_at',
            'amount' => 'amount_minor',
        ];

        $sort = $sortable[$filters['sort'] ?? ''] ?? 'created_at';

        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return DB::query()
            ->fromSub($union, 'bookings')
            ->orderBy($sort, $direction)
            // A stable tiebreak, so two records sharing a timestamp do not swap
            // places between pages and hide a row.
            ->orderBy('source')
            ->orderBy('reference')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Counts per stage across the selected sources, for the filter chips. */
    public function stageCounts(array $filters): array
    {
        $counts = [];

        foreach (BookingStage::cases() as $stage) {
            $counts[$stage->value] = 0;
        }

        foreach ($this->selectedSources($filters['source'] ?? null) as $source) {
            foreach (BookingStage::cases() as $stage) {
                $statuses = $source->statusValuesInStage($stage);

                if ($statuses === []) {
                    continue;
                }

                $counts[$stage->value] += $this->baseQuery($source, $filters, ignoreStage: true)
                    ->whereIn('status', $statuses)
                    ->count();
            }
        }

        return $counts;
    }

    /** @return list<BookingSource> */
    private function selectedSources(mixed $requested): array
    {
        if (is_string($requested) && $requested !== '') {
            $source = BookingSource::tryFrom($requested);

            return $source === null ? [] : [$source];
        }

        return BookingSource::cases();
    }

    /**
     * A projection of one domain onto the shared column set.
     *
     * @param  array<string, mixed>  $filters
     */
    private function projectionFor(BookingSource $source, array $filters): Builder
    {
        $amount = $source->amountColumn();
        $currency = $source->currencyColumn();
        $serviceDate = $source->serviceDateColumn();
        $summary = $source->summaryColumn();

        return $this->baseQuery($source, $filters)->select([
            DB::raw("'{$source->value}' as source"),
            'id',
            'reference',
            'status',
            DB::raw($source->contactNameColumn().' as contact_name'),
            DB::raw($source->contactEmailColumn().' as contact_email'),
            DB::raw($source->customerColumn().' as customer_id'),
            DB::raw("coalesce({$amount}, 0) as amount_minor"),
            DB::raw("{$currency} as currency"),
            DB::raw("{$serviceDate} as service_date"),
            DB::raw("{$summary} as summary"),
            'created_at',
        ]);
    }

    /**
     * The filtered row set for one domain, before projection.
     *
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(BookingSource $source, array $filters, bool $ignoreStage = false): Builder
    {
        $query = DB::table($source->table());

        if (! $ignoreStage && filled($filters['stage'] ?? null)) {
            $stage = BookingStage::tryFrom((string) $filters['stage']);

            // An unknown stage must return nothing rather than everything.
            $query->whereIn(
                'status',
                $stage === null ? [] : $source->statusValuesInStage($stage),
            );
        }

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);

            $nameColumn = $source->contactNameColumn();
            $emailColumn = $source->contactEmailColumn();

            $query->where(function (Builder $nested) use ($search, $nameColumn, $emailColumn): void {
                $nested->where('reference', 'like', '%'.$search.'%');

                // A source with no contact columns is searched by reference
                // alone rather than by a literal that can never match.
                if ($nameColumn !== "''") {
                    $nested->orWhere($nameColumn, 'like', '%'.$search.'%')
                        ->orWhere($emailColumn, 'like', '%'.$search.'%');
                }
            });
        }

        $serviceDate = $source->serviceDateColumn();
        $isCalendarDate = $source->serviceDateIsCalendarDate();

        if (filled($filters['from'] ?? null)) {
            $query->where($serviceDate, '>=', $isCalendarDate
                ? CarbonImmutable::parse((string) $filters['from'])->toDateString()
                : $this->boundary((string) $filters['from'], startOfDay: true));
        }

        if (filled($filters['to'] ?? null)) {
            $query->where($serviceDate, '<=', $isCalendarDate
                ? CarbonImmutable::parse((string) $filters['to'])->toDateString()
                : $this->boundary((string) $filters['to'], startOfDay: false));
        }

        if (filled($filters['customer_id'] ?? null)) {
            $query->where($source->customerColumn(), (int) $filters['customer_id']);
        }

        return $query;
    }

    /**
     * A calendar day the operator typed in Kampala, converted to the UTC instant
     * actually stored. Comparing a local date against a UTC column without this
     * silently shifts the boundary by three hours.
     */
    private function boundary(string $date, bool $startOfDay): CarbonImmutable
    {
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $moment = CarbonImmutable::parse($date, $timezone);

        return ($startOfDay ? $moment->startOfDay() : $moment->endOfDay())->utc();
    }
}
