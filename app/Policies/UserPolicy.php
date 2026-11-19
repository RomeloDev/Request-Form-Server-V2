<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, User $model): bool
    {
        return false;
    }

    public function viewProfile(User $user, User $profile): bool
    {
        return $user->is($profile);
    }

    /**
     * Determine whether the user can create a user account.
     */
    public function create(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can bulk-create or import user accounts.
     */
    public function bulkCreate(User $user): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can verify another user's email address.
     *
     * Verification is an administrative action, so only the Admin role
     * may perform it.
     */
    public function verify(User $user, User $model): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can view another user's protected
     * profile picture.
     */
    public function viewProfilePicture(User $user, User $model): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can view another user's protected
     * signature.
     */
    public function viewSignature(User $user, User $model): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, User $model): bool
    {
        return $user->hasRole('Admin');
    }

    public function changePassword(User $user, User $profile): bool
    {
        return $user->is($profile);
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, User $model): bool
    {
        return $user->hasRole('Admin');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, User $model): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, User $model): bool
    {
        return false;
    }

    public function manageProfilePicture(User $user, User $profile): bool
    {
        return $user->is($profile);
    }

    public function manageSignature(User $user, User $profile): bool
    {
        return $user->is($profile);
    }
}
