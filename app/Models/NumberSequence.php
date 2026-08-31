<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A counter per (prefix, period) behind the gapless document numbering.
 *
 * @property string $prefix
 * @property string $period
 * @property int $next_value
 */
class NumberSequence extends Model
{
    protected $fillable = ['prefix', 'period', 'next_value'];

    protected function casts(): array
    {
        return ['next_value' => 'integer'];
    }
}
