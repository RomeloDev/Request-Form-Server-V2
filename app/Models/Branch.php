<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'branch_code',
    'branch_name',
    'branch',
    'acronym',
])]

class Branch extends Model
{
    use HasFactory;

    /**
     * Get the AVP Staff assignment that routes this branch.
     *
     * The database enforces only one assignment per branch, although
     * belongsToMany keeps the inverse compatible with the pivot model.
     */
    public function avpStaffAssignments(): BelongsToMany
    {
        return $this->belongsToMany(
            AvpStaffAssignment::class,
            'avp_staff_assignment_branch',
            'branch_id',
            'avp_staff_assignment_id'
        )->withTimestamps();
    }
}
