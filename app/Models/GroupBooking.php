<?php

namespace App\Models;

use App\Enums\GroupBookingStatus;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One trip for many people.
 *
 * `headcount` is what was agreed and priced; the manifest is who is actually
 * coming. They are separate on purpose — the gap between them is the work, and
 * closing it is what `Confirmed` means.
 *
 * @property GroupBookingStatus $status
 * @property string $reference
 * @property string $title
 * @property string $service_kind
 * @property string $currency
 * @property int $headcount
 * @property int $organiser_id
 * @property int|null $corporate_account_id
 * @property int|null $quotation_id
 * @property int|null $quoted_total_minor
 * @property string|null $internal_notes
 * @property string|null $closure_reason
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable|null $confirmed_at
 * @property CorporateAccount|null $account
 * @property User|null $organiser
 */
class GroupBooking extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'corporate_account_id',
        'organiser_id',
        'status',
        'title',
        'service_kind',
        'starts_on',
        'ends_on',
        'headcount',
        'pickup_location',
        'destination',
        'requirements',
        'internal_notes',
        'quoted_total_minor',
        'currency',
        'quotation_id',
        'confirmed_at',
        'cancelled_at',
        'closure_reason',
    ];

    protected $hidden = ['internal_notes'];

    protected function casts(): array
    {
        return [
            'status' => GroupBookingStatus::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'headcount' => 'integer',
            'quoted_total_minor' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class, 'corporate_account_id');
    }

    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organiser_id');
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    /** @return HasMany<GroupTraveler, $this> */
    public function travelers(): HasMany
    {
        return $this->hasMany(GroupTraveler::class)->orderBy('full_name');
    }

    public function scopeForOrganiser(Builder $query, User|int $organiser): Builder
    {
        return $query->where('organiser_id', $organiser instanceof User ? $organiser->getKey() : $organiser);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', GroupBookingStatus::openValues());
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('reference', 'like', '%'.$search.'%')
                ->orWhere('title', 'like', '%'.$search.'%')
                ->orWhere('destination', 'like', '%'.$search.'%');
        });
    }

    public function canTransitionTo(GroupBookingStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    /** How many names are on the list. */
    public function manifestCount(): int
    {
        return $this->relationLoaded('travelers')
            ? $this->travelers->count()
            : $this->travelers()->count();
    }

    /** Names still missing before the group can be confirmed. */
    public function manifestShortfall(): int
    {
        return max(0, $this->headcount - $this->manifestCount());
    }

    public function manifestIsComplete(): bool
    {
        return $this->manifestCount() === $this->headcount;
    }

    public function nights(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on);
    }

    public function dateLabel(): string
    {
        return $this->starts_on->format('j M Y').' — '.$this->ends_on->format('j M Y');
    }

    public function formattedQuotedTotal(): ?string
    {
        return $this->quoted_total_minor === null
            ? null
            : Money::format($this->quoted_total_minor, $this->currency);
    }
}
