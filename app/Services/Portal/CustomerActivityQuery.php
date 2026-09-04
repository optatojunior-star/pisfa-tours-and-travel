<?php

namespace App\Services\Portal;

use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Models\User;
use App\Support\Bookings\BookingSource;
use App\Support\Portal\ActivityKind;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Everything one customer has with PISFA, in one list.
 *
 * The customer mirror of the admin unified-bookings screen, and built the same
 * way: a UNION over the live domain tables rather than a portal-specific copy
 * that could drift from what the office sees.
 *
 * Every projection is filtered by `customer_id` inside the query itself, not by
 * a caller remembering to. There is no path through this class that returns
 * another customer's row.
 */
class CustomerActivityQuery
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, \stdClass>
     */
    public function paginate(User $customer, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $kinds = $this->selectedKinds($filters['kind'] ?? null);
        $union = null;

        foreach ($kinds as $kind) {
            foreach ($this->projectionsFor($kind, $customer, $filters) as $projection) {
                $union = $union === null ? $projection : $union->unionAll($projection);
            }
        }

        if ($union === null) {
            return new LengthAwarePaginator([], 0, $perPage, 1, [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
            ]);
        }

        return DB::query()
            ->fromSub($union, 'activity')
            ->orderByDesc('happened_at')
            // A stable tiebreak, so two records sharing a timestamp cannot swap
            // places between pages and hide one of them.
            ->orderBy('kind')
            ->orderBy('reference')
            ->paginate($perPage)
            ->withQueryString();
    }

    /** Counts per kind, for the filter chips. */
    public function counts(User $customer): array
    {
        $counts = [];

        foreach (ActivityKind::cases() as $kind) {
            $total = 0;

            foreach ($this->projectionsFor($kind, $customer, []) as $projection) {
                $total += $projection->count();
            }

            $counts[$kind->value] = $total;
        }

        return $counts;
    }

    /** @return list<ActivityKind> */
    private function selectedKinds(mixed $requested): array
    {
        if (is_string($requested) && $requested !== '') {
            $kind = ActivityKind::tryFrom($requested);

            // An unknown kind returns nothing rather than everything.
            return $kind === null ? [] : [$kind];
        }

        return ActivityKind::cases();
    }

    /**
     * One or more projections onto the shared column set.
     *
     * Bookings contribute four, one per domain; the billing kinds contribute
     * one each.
     *
     * @param  array<string, mixed>  $filters
     * @return list<Builder>
     */
    private function projectionsFor(ActivityKind $kind, User $customer, array $filters): array
    {
        return match ($kind) {
            ActivityKind::Bookings => $this->bookingProjections($customer, $filters),
            ActivityKind::Quotations => [$this->quotations($customer, $filters)],
            ActivityKind::Invoices => [$this->invoices($customer, $filters)],
            ActivityKind::Payments => [$this->payments($customer, $filters)],
        };
    }

    /** @return list<Builder> */
    private function bookingProjections(User $customer, array $filters): array
    {
        $projections = [];

        foreach (BookingSource::cases() as $source) {
            // customerColumn(), not a literal: a group booking belongs to its
            // organiser and has no customer_id. SQLite silently matched nothing;
            // MySQL rightly refuses the whole UNION.
            $query = DB::table($source->table())
                ->where($source->customerColumn(), $customer->getKey());

            $this->applySearch($query, $filters, ['reference']);

            $projections[] = $query->select([
                DB::raw("'".ActivityKind::Bookings->value."' as kind"),
                DB::raw("'{$source->value}' as source"),
                'reference',
                'status',
                DB::raw($source->summaryColumn().' as summary'),
                DB::raw('coalesce('.$source->amountColumn().', 0) as amount_minor'),
                DB::raw($source->currencyColumn().' as currency'),
                'created_at as happened_at',
            ]);
        }

        return $projections;
    }

    private function quotations(User $customer, array $filters)
    {
        $query = DB::table('quotations')
            ->where('customer_id', $customer->getKey())
            // A draft is internal and must never reach the portal, not even in
            // an aggregate list.
            ->whereIn('status', QuotationStatus::customerVisibleValues());

        $this->applySearch($query, $filters, ['number', 'title']);

        return $query->select([
            DB::raw("'".ActivityKind::Quotations->value."' as kind"),
            DB::raw("'quotations' as source"),
            'number as reference',
            'status',
            'title as summary',
            'total_minor as amount_minor',
            'currency',
            'created_at as happened_at',
        ]);
    }

    private function invoices(User $customer, array $filters)
    {
        $query = DB::table('invoices')
            ->where('customer_id', $customer->getKey())
            ->whereIn('status', InvoiceStatus::customerVisibleValues());

        $this->applySearch($query, $filters, ['number', 'title']);

        return $query->select([
            DB::raw("'".ActivityKind::Invoices->value."' as kind"),
            DB::raw("'invoices' as source"),
            'number as reference',
            'status',
            'title as summary',
            'total_minor as amount_minor',
            'currency',
            'created_at as happened_at',
        ]);
    }

    private function payments(User $customer, array $filters)
    {
        $query = DB::table('payments')
            ->where('customer_id', $customer->getKey());

        $this->applySearch($query, $filters, ['reference']);

        return $query->select([
            DB::raw("'".ActivityKind::Payments->value."' as kind"),
            DB::raw("'payments' as source"),
            'reference',
            'status',
            DB::raw("'Payment' as summary"),
            'amount_minor',
            'currency',
            'created_at as happened_at',
        ]);
    }

    /**
     * @param  Builder  $query
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $columns
     */
    private function applySearch($query, array $filters, array $columns): void
    {
        if (blank($filters['q'] ?? null)) {
            return;
        }

        $search = trim((string) $filters['q']);

        $query->where(function ($nested) use ($columns, $search): void {
            foreach ($columns as $index => $column) {
                $index === 0
                    ? $nested->where($column, 'like', '%'.$search.'%')
                    : $nested->orWhere($column, 'like', '%'.$search.'%');
            }
        });
    }

    /**
     * The most recent few items, for the portal home.
     *
     * @return Collection<int, \stdClass>
     */
    public function recent(User $customer, int $limit = 5): Collection
    {
        return $this->paginate($customer, [], $limit)->getCollection();
    }

    /** Rendered timestamp in the business timezone the portal is labelled in. */
    public static function localTime(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)
            ->timezone((string) config('pisfa.business_timezone', 'Africa/Kampala'));
    }
}
