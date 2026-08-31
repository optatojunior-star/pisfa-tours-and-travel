<?php

namespace App\Models;

use App\Enums\VehicleImportEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only timeline entry.
 *
 * @property VehicleImportEventType $event_type
 * @property bool $is_customer_visible
 * @property array<string, mixed>|null $payload
 */
class VehicleImportEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_import_order_id',
        'actor_user_id',
        'event_type',
        'is_customer_visible',
        'summary',
        'payload',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => VehicleImportEventType::class,
            'is_customer_visible' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(VehicleImportOrder::class, 'vehicle_import_order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function scopeCustomerVisible(Builder $query): Builder
    {
        return $query->where('is_customer_visible', true);
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->whereNull('processed_at');
    }
}
