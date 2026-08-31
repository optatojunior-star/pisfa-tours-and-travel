<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A message on the import thread.
 *
 * `is_internal` is the privacy boundary: an internal note is written by staff
 * for staff and must never appear in the customer thread.
 *
 * @property bool $from_customer
 * @property bool $is_internal
 */
class VehicleImportMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'vehicle_import_order_id',
        'author_user_id',
        'from_customer',
        'is_internal',
        'body',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'from_customer' => 'boolean',
            'is_internal' => 'boolean',
            'read_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(VehicleImportOrder::class, 'vehicle_import_order_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }

    /** Everything the customer is allowed to see on the thread. */
    public function scopeShared(Builder $query): Builder
    {
        return $query->where('is_internal', false);
    }

    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('is_internal', true);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null;
    }
}
