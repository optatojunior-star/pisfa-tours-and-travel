<?php

namespace App\Models;

use App\Enums\CarHireDocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CarHireDocument extends Model
{
    use HasFactory;

    protected $fillable = [
        'car_hire_booking_id',
        'document_type',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
        'content_sha256',
        'uploaded_by_user_id',
    ];

    protected $hidden = [
        'disk',
        'path',
        'content_sha256',
    ];

    protected function casts(): array
    {
        return [
            'document_type' => CarHireDocumentType::class,
            'size_bytes' => 'integer',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CarHireBooking::class, 'car_hire_booking_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
