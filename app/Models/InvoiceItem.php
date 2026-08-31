<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A snapshot of the quotation line it came from. Kept as its own row rather
 * than read through the quotation so revising an offer can never restate an
 * invoice that has already been issued or paid.
 *
 * @property int $quantity
 * @property int $unit_price_minor
 * @property int $line_total_minor
 * @property string $description
 * @property Invoice|null $invoice
 */
class InvoiceItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'invoice_id',
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

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
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
