<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A thread between PISFA and one person.
 *
 * Guests are first class: `customer_id` stays null rather than pointing at a
 * placeholder account, and a WhatsApp conversation is addressed by its phone
 * number alone.
 *
 * @property ConversationStatus $status
 * @property ConversationChannel $channel
 * @property string $reference
 * @property string $contact_name
 * @property string|null $contact_email
 * @property string|null $contact_phone
 * @property string|null $subject
 * @property int|null $customer_id
 * @property int|null $assigned_to_user_id
 * @property CarbonImmutable|null $last_message_at
 * @property CarbonImmutable|null $last_customer_message_at
 * @property CarbonImmutable|null $last_staff_message_at
 * @property CarbonImmutable|null $last_auto_reply_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $closed_at
 * @property string|null $closure_reason
 * @property CarbonImmutable $created_at
 * @property User|null $customer
 * @property User|null $assignee
 */
class Conversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference',
        'status',
        'channel',
        'customer_id',
        'assigned_to_user_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'subject',
        'subject_type',
        'subject_id',
        'last_message_at',
        'last_customer_message_at',
        'last_staff_message_at',
        'last_auto_reply_at',
        'resolved_at',
        'closed_at',
        'closure_reason',
        'idempotency_owner_hash',
        'idempotency_key',
    ];

    /** Idempotency material never leaves the server. */
    protected $hidden = [
        'idempotency_owner_hash',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'channel' => ConversationChannel::class,
            'last_message_at' => 'immutable_datetime',
            'last_customer_message_at' => 'immutable_datetime',
            'last_staff_message_at' => 'immutable_datetime',
            'last_auto_reply_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'reference';
    }

    /**
     * Phone numbers are stored as digits and a leading plus.
     *
     * The same person typing +256 700 000 000 one day and 0700000000 the next
     * has to land in one conversation, and the inbound webhook only ever sees
     * the provider's normalised form.
     */
    protected function contactPhone(): Attribute
    {
        return Attribute::make(
            set: static fn (mixed $value): ?string => self::normalisePhone($value),
        );
    }

    protected function contactEmail(): Attribute
    {
        return Attribute::make(set: static function (mixed $value): ?string {
            $value = $value === null ? null : mb_strtolower(trim((string) $value));

            return $value === '' ? null : $value;
        });
    }

    public static function normalisePhone(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/[^0-9+]/', '', (string) $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /** @return HasMany<ConversationMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(ConversationMessage::class)->orderBy('id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForCustomer(Builder $query, User|int $customer): Builder
    {
        return $query->where('customer_id', $customer instanceof User ? $customer->getKey() : $customer);
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', ConversationStatus::openValues());
    }

    /** Threads nobody has answered, oldest first — the actual work queue. */
    public function scopeNeedingReply(Builder $query): Builder
    {
        return $query->where('status', ConversationStatus::Open->value)
            ->orderBy('last_customer_message_at');
    }

    public function scopeSearch(Builder $query, string $search): Builder
    {
        $search = trim($search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($search): void {
            $nested->where('reference', 'like', '%'.$search.'%')
                ->orWhere('contact_name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%')
                ->orWhere('contact_phone', 'like', '%'.$search.'%')
                ->orWhere('subject', 'like', '%'.$search.'%');
        });
    }

    public function isGuest(): bool
    {
        return $this->customer_id === null;
    }

    public function canTransitionTo(ConversationStatus $next): bool
    {
        return $this->status->canTransitionTo($next);
    }

    public function acceptsMessages(): bool
    {
        return $this->status->acceptsMessages();
    }

    /**
     * Whether a reply can physically be delivered.
     *
     * A WhatsApp thread with no number is a thread nobody can answer, and the
     * console needs to say so rather than queueing a message that will fail.
     */
    public function isReachable(): bool
    {
        return ! $this->channel->requiresPhoneNumber() || $this->contact_phone !== null;
    }

    /** How long the customer has been waiting, in minutes. */
    public function waitingMinutes(): ?int
    {
        if ($this->status !== ConversationStatus::Open || $this->last_customer_message_at === null) {
            return null;
        }

        return (int) $this->last_customer_message_at->diffInMinutes(now());
    }
}
