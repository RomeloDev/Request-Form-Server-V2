<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'user_id',
])]
class BranchHeadAssignment extends Model
{
    use HasFactory;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class,
            'branch_head_assignment_branch',
            'branch_head_assignment_id',
            'branch_id'
        )->withTimestamps();
    }
}
