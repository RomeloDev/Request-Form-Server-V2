<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'disk',
    'path',
    'original_name',
    'mime_type',
    'size_bytes',
])]
class RequestAttachment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
        ];
    }

    public function requestForm(): BelongsTo
    {
        return $this->belongsTo(RequestForm::class);
    }
}
