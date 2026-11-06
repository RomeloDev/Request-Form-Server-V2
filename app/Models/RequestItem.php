<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'quantity',
    'unit',
    'description',
    'unit_cost',
    'remarks',
    'item_data',
])]
class RequestItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'line_total' => 'decimal:2',
            'item_data' => 'array',
        ];
    }

    public function requestForm(): BelongsTo
    {
        return $this->belongsTo(RequestForm::class);
    }
}
