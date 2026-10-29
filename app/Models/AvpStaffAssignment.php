<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable([
    'avp_user_id',
    'staff_user_id',
])]
class AvpStaffAssignment extends Model
{
    use HasFactory;

    /**
     * Get the AVP Finance represented by this assignment.
     */
    public function avp(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'avp_user_id'
        );
    }

    /**
     * Get the staff member processing approvals for the AVP.
     */
    public function staff(): BelongsTo
    {
        return $this->belongsTo(
            User::class,
            'staff_user_id'
        );
    }

    /**
     * Get the branches routed to this staff assignment.
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(
            Branch::class,
            'avp_staff_assignment_branch',
            'avp_staff_assignment_id',
            'branch_id'
        )->withTimestamps();
    }
}
