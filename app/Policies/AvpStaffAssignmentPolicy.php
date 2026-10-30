<?php

namespace App\Policies;

use App\Models\AvpStaffAssignment;
use App\Models\User;

class AvpStaffAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    public function view(User $user, AvpStaffAssignment $assignment): bool
    {
        return $user->hasRole('Admin');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    public function update(User $user, AvpStaffAssignment $assignment): bool
    {
        return $user->hasRole('Admin');
    }

    public function delete(User $user, AvpStaffAssignment $assignment): bool
    {
        return $user->hasRole('Admin');
    }
}
