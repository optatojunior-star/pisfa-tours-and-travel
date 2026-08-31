<?php

namespace App\Models;

use App\Enums\ConversationChannel;
use App\Enums\MessageAuthorType;
use App\Enums\MessageDeliveryStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a thread.
 *
 * @property MessageAuthorType $author_type
 * @property MessageDeliveryStatus $delivery_status
 * @property ConversationChannel $channel
 * @property string $body
 * @property bool $is_internal_note
 * @property int $conversation_id
 * @property int|null $author_user_id
 * @property string|null $provider_message_id
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable $created_at
 * @property Conversation|null $conversation
 * @property User|null $author
 */
class ConversationMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'author_type',
        'author_user_id',
        'channel',
        'body',
        'is_internal_note',
        'delivery_status',
        'failure_reason',
        'provider_message_id',
        'sent_at',
        'delivered_at',
        'read_at',
        'idempotency_key',
    ];

    protected $hidden = ['idempotency_key'];

    protected function casts(): array
    {
        return [
            'author_type' => MessageAuthorType::class,
            'delivery_status' => MessageDeliveryStatus::class,
            'channel' => ConversationChannel::class,
            'is_internal_note' => 'boolean',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /**
     * The only scope a customer-facing surface may use.
     *
     * Internal notes and system entries are staff-only; a customer surface that
     * forgot to filter would show the customer what staff said about them.
     */
    public function scopeVisibleToCustomer(Builder $query): Builder
    {
        return $query->where('is_internal_note', false)
            ->whereIn('author_type', [
                MessageAuthorType::Customer->value,
                MessageAuthorType::Staff->value,
                MessageAuthorType::Bot->value,
            ]);
    }

    public function scopeAfter(Builder $query, ?int $cursor): Builder
    {
        return $cursor === null ? $query : $query->where('id', '>', $cursor);
    }

    public function scopeAwaitingDelivery(Builder $query): Builder
    {
        return $query->whereIn('delivery_status', [
            MessageDeliveryStatus::Pending->value,
            MessageDeliveryStatus::Sent->value,
        ]);
    }

    public function isFromCustomer(): bool
    {
        return $this->author_type === MessageAuthorType::Customer;
    }

    public function isVisibleToCustomer(): bool
    {
        return ! $this->is_internal_note && $this->author_type->isVisibleToCustomer();
    }

    /**
     * Applies a delivery report, without ever moving backwards.
     *
     * Returns whether anything changed, so a duplicate callback can be recorded
     * as a no-op rather than counted as progress.
     */
    public function applyDeliveryReport(MessageDeliveryStatus $reported, ?string $failureReason = null): bool
    {
        $next = $this->delivery_status->advanceTo($reported);

        if ($next === $this->delivery_status) {
            return false;
        }

        $this->forceFill(array_filter([
            'delivery_status' => $next,
            'sent_at' => $this->sent_at ?? ($next->rank() >= MessageDeliveryStatus::Sent->rank() ? now() : null),
            'delivered_at' => $this->delivered_at
                ?? ($next->rank() >= MessageDeliveryStatus::Delivered->rank() ? now() : null),
            'read_at' => $this->read_at ?? ($next === MessageDeliveryStatus::Read ? now() : null),
            'failure_reason' => $next->isFailure() ? $failureReason : null,
        ], static fn (mixed $value): bool => $value !== null));

        return true;
    }
}
