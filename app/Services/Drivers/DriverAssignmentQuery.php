<?php

namespace App\Services\Drivers;

use App\Models\DriverTrip;
use App\Models\User;
use App\Support\Drivers\AssignmentSource;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * One driver's work across tours, car hire, and airport transfers.
 *
 * Read from the live assignment tables rather than a driver-specific copy: the
 * office assigns work in the domain consoles, and a second store of the same
 * fact would drift from the assignment that is the real record.
 *
 * Every query is scoped by `driver_user_id`. There is no unscoped path here
 * that a wrong filter could turn into another driver's schedule.
 */
class DriverAssignmentQuery
{
    /**
     * Live work: assigned, not withdrawn, and not already finished.
     *
     * @return Collection<int, \stdClass>
     */
    public function upcoming(User $driver, ?CarbonImmutable $at = null, int $limit = 25): Collection
    {
        $at ??= CarbonImmutable::now();

        return $this->rows($driver, $limit, function ($query, string $table) use ($at): void {
            // A job that ended in the past is history, not upcoming. The
            // generous tail keeps one that overran visible to its driver.
            $query->where($table.'.ends_at', '>=', $at->subHours(12));
        });
    }

    /**
     * Completed and withdrawn work, newest first.
     *
     * @return Collection<int, \stdClass>
     */
    public function history(User $driver, ?CarbonImmutable $at = null, int $limit = 50): Collection
    {
        $at ??= CarbonImmutable::now();

        return $this->rows($driver, $limit, function ($query, string $table) use ($at): void {
            $query->where($table.'.ends_at', '<', $at->subHours(12));
        }, descending: true);
    }

    /** Work whose service window covers a given Kampala calendar day. */
    public function forDay(User $driver, CarbonImmutable $day): Collection
    {
        $from = $day->startOfDay()->utc();
        $to = $day->endOfDay()->utc();

        return $this->rows($driver, 25, function ($query, string $table) use ($from, $to): void {
            $query->where($table.'.starts_at', '<=', $to)->where($table.'.ends_at', '>=', $from);
        });
    }

    /**
     * The union across all three assignment tables.
     *
     * @return Collection<int, \stdClass>
     */
    private function rows(
        User $driver,
        int $limit,
        ?callable $filter = null,
        bool $descending = false,
    ): Collection {
        $union = null;

        foreach (AssignmentSource::cases() as $source) {
            $projection = $this->projectionFor($source, $driver, $filter);

            $union = $union === null ? $projection : $union->unionAll($projection);
        }

        if ($union === null) {
            return collect();
        }

        $rows = DB::query()
            ->fromSub($union, 'assignments')
            ->orderBy('starts_at', $descending ? 'desc' : 'asc')
            // A stable tiebreak, so two jobs sharing a start time keep their
            // order between page loads.
            ->orderBy('source')
            ->orderBy('assignment_id')
            ->limit($limit)
            ->get();

        return $this->withTrips($rows);
    }

    private function projectionFor(AssignmentSource $source, User $driver, ?callable $filter)
    {
        $assignments = $source->table();
        $bookings = $source->bookingTable();
        $vehicleColumn = $source->vehicleColumn();

        $query = DB::table($assignments)
            ->join($bookings, $bookings.'.id', '=', $assignments.'.'.$source->bookingForeignKey())
            ->where($assignments.'.driver_user_id', $driver->getKey())
            // A withdrawn assignment is not this driver's work any more.
            ->whereNull($assignments.'.unassigned_at');

        if ($filter !== null) {
            // Qualified with the table name: both sides of the join carry date
            // columns, and an unqualified one would be ambiguous.
            $filter($query, $assignments);
        }

        return $query->select([
            DB::raw("'{$source->value}' as source"),
            $assignments.'.id as assignment_id',
            $assignments.'.starts_at',
            $assignments.'.ends_at',
            $bookings.'.reference as booking_reference',
            $bookings.'.contact_name',
            $bookings.'.contact_phone',
            DB::raw($bookings.'.'.$source->bookingSummaryColumn().' as summary'),
            // A transfer names its vehicle on the assignment; car hire names it
            // on the booking. Qualifying the wrong side is a missing column.
            DB::raw($vehicleColumn === null
                ? 'null as vehicle_id'
                : ($source->vehicleOnAssignment() ? $assignments : $bookings)
                    .'.'.$vehicleColumn.' as vehicle_id'),
        ]);
    }

    /**
     * Attaches each row's trip, if one has been started.
     *
     * Loaded in one pass per source rather than per row, so a driver with a
     * full week does not produce a query per job.
     *
     * @param  Collection<int, \stdClass>  $rows
     * @return Collection<int, \stdClass>
     */
    private function withTrips(Collection $rows): Collection
    {
        if ($rows->isEmpty()) {
            return $rows;
        }

        $trips = collect();

        foreach ($rows->groupBy('source') as $sourceValue => $group) {
            $source = AssignmentSource::tryFrom((string) $sourceValue);

            if ($source === null) {
                continue;
            }

            $found = DriverTrip::query()
                ->where('assignment_type', (new ($source->model()))->getMorphClass())
                ->whereIn('assignment_id', $group->pluck('assignment_id')->all())
                ->get();

            foreach ($found as $trip) {
                $trips->put($sourceValue.':'.$trip->assignment_id, $trip);
            }
        }

        return $rows->map(function (\stdClass $row) use ($trips): \stdClass {
            $row->trip = $trips->get($row->source.':'.$row->assignment_id);
            $row->assignment_source = AssignmentSource::from($row->source);

            return $row;
        });
    }
}
