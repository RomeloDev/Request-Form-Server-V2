<?php

namespace App\Policies;

use App\Models\User;

class FeedbackPolicy
{
    /**
     * Create a new policy instance.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin');
    }
}
