<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A delivery from a messaging provider.
 *
 * Only a digest of the payload is kept. The body of a customer's message is
 * already in `conversation_messages`, where deletion and retention apply to it;
 * a second copy in an events table would quietly outlive that.
 *
 * @property string $provider
 * @property string $event_id
 * @property string|null $event_type
 * @property bool $signature_verified
 * @property string $payload_sha256
 * @property string|null $result
 * @property CarbonImmutable|null $processed_at
 */
class MessagingWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'conversation_id',
        'conversation_message_id',
        'signature_verified',
        'payload_sha256',
        'result',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_verified' => 'boolean',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'conversation_message_id');
    }
}
