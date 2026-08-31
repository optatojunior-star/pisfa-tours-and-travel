<?php

namespace App\Models;

use App\Enums\PaymentProvider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable log of every provider notification received.
 *
 * The unique key on (provider, event_id) is the primary duplicate-webhook
 * defence: a replayed delivery collides at the database before any handler can
 * run, rather than relying on an application check that could race.
 *
 * @property PaymentProvider $provider
 * @property array<string, mixed>|null $payload
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
class PaymentWebhookEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'event_id',
        'event_type',
        'payment_id',
        'signature_verified',
        'payload_sha256',
        'payload',
        'received_at',
        'processed_at',
        'processing_error',
    ];

    /** Raw provider payloads are operator-only, never serialised to a client. */
    protected $hidden = ['payload', 'payload_sha256'];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'signature_verified' => 'boolean',
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereNotNull('processing_error');
    }

    public function isProcessed(): bool
    {
        return $this->processed_at !== null;
    }
}
