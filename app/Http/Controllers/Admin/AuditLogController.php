<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogFilterRequest;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The audit trail, made readable.
 *
 * Read-only by construction: there is no route here that writes, edits, or
 * deletes an entry. A trail somebody can amend is not a trail.
 */
class AuditLogController extends Controller
{
    public function index(AuditLogFilterRequest $request): View
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->validated();
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');

        $query = AuditLog::query()
            ->with(['user:id,name,email', 'auditable'])
            ->latest('id');

        if (filled($filters['event'] ?? null)) {
            // A prefix match, so "payment" finds every payment.* entry.
            $query->where('event', 'like', trim((string) $filters['event']).'%');
        }

        if (filled($filters['user_id'] ?? null)) {
            $query->where('user_id', (int) $filters['user_id']);
        }

        if (filled($filters['q'] ?? null)) {
            $search = trim((string) $filters['q']);

            $query->where(fn (Builder $nested): Builder => $nested
                ->where('event', 'like', '%'.$search.'%')
                ->orWhere('auditable_type', 'like', '%'.$search.'%')
                ->orWhere('ip_address', 'like', '%'.$search.'%'));
        }

        if (filled($filters['from'] ?? null)) {
            $query->where('created_at', '>=', CarbonImmutable::parse(
                (string) $filters['from'], $timezone,
            )->startOfDay()->utc());
        }

        if (filled($filters['to'] ?? null)) {
            $query->where('created_at', '<=', CarbonImmutable::parse(
                (string) $filters['to'], $timezone,
            )->endOfDay()->utc());
        }

        return view('admin.audit.index', [
            'entries' => $query->paginate(30)->withQueryString(),
            'filters' => $filters,
            'events' => $this->eventPrefixes(),
            'actors' => $this->recentActors(),
            'timezone' => $timezone,
        ]);
    }

    public function show(AuditLog $auditLog): View
    {
        $this->authorize('view', $auditLog);

        return view('admin.audit.show', [
            'entry' => $auditLog->load(['user:id,name,email', 'auditable']),
            'timezone' => config('pisfa.business_timezone', 'Africa/Kampala'),
        ]);
    }

    /**
     * The domain prefixes actually present, for the filter list.
     *
     * Derived from the data rather than hardcoded, so a new feature's events
     * appear without anyone remembering to add them here.
     *
     * @return list<string>
     */
    private function eventPrefixes(): array
    {
        $prefixes = AuditLog::query()
            ->select('event')
            ->distinct()
            ->pluck('event')
            ->map(static fn (string $event): string => str_contains($event, '.')
                ? str($event)->before('.')->toString()
                : $event)
            ->unique()
            ->sort()
            ->values()
            ->all();

        return $prefixes;
    }

    /** @return Collection<int, User> */
    private function recentActors()
    {
        return User::query()
            ->whereIn('id', AuditLog::query()->whereNotNull('user_id')->distinct()->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
