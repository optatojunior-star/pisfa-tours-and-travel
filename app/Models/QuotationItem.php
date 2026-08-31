<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $line_total_minor
 * @property string $description
 * @property Quotation|null $quotation
 */
class QuotationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'sort_order',
        'description',
        'unit_label',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function formattedUnitPrice(string $currency): string
    {
        return Money::format($this->unit_price_minor, $currency);
    }

    public function formattedLineTotal(string $currency): string
    {
        return Money::format($this->line_total_minor, $currency);
    }

    public function quantityLabel(): string
    {
        return $this->quantity.($this->unit_label === null ? '' : ' '.$this->unit_label);
    }
}
