<?php

namespace App\Policies;

use App\Models\RequestForm;
use App\Models\User;

class RequestFormPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]);
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, RequestForm $requestForm): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]) && (int) $requestForm->requester_id === (int) $user->getKey();
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]);
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, RequestForm $requestForm): bool
    {
        return false;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RequestForm $requestForm): bool
    {
        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, RequestForm $requestForm): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, RequestForm $requestForm): bool
    {
        return false;
    }
}
