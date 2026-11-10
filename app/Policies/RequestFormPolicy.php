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
     * Determine whether the user may list users the request can be shared
     * with. Only the requester who owns the request may do so.
     */
    public function listShareCandidates(
        User $user,
        RequestForm $requestForm
    ): bool {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]) && (int) $requestForm->requester_id === (int) $user->getKey();
    }

    /**
     * Determine whether the user may share the request with other users.
     * Only the requester who owns the request may do so.
     */
    public function share(User $user, RequestForm $requestForm): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]) && (int) $requestForm->requester_id === (int) $user->getKey();
    }

    public function viewAttachment(User $user, RequestForm $requestForm): bool
    {
        if (! $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ])) {
            return false;
        }

        if ((int) $requestForm->requester_id === (int) $user->getKey()) {
            return true;
        }

        return $requestForm->approvalSteps()
            ->where('approver_id', $user->getKey())
            ->exists();
    }

    public function viewPrintable(
        User $user,
        RequestForm $requestForm
    ): bool {
        if (! $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ])) {
            return false;
        }

        if ((int) $requestForm->requester_id === (int) $user->getKey()) {
            return true;
        }

        return $requestForm->approvalSteps()
            ->where('approver_id', $user->getKey())
            ->exists();
    }

    /**
     * Determine whether the user may access their assigned approval requests.
     */
    public function viewAssigned(User $user): bool
    {
        return $user->hasRole('approver');
    }

    public function viewAssignedRequest(User $user, RequestForm $requestForm): bool
    {
        if (! $user->hasRole('approver')) {
            return false;
        }

        return $requestForm->approvalSteps()
            ->where('approver_id', $user->getKey())
            ->exists();
    }

    public function decideAssignedRequest(User $user, RequestForm $requestForm): bool
    {
        if (! $user->hasRole('approver')) {
            return false;
        }

        return $requestForm->approvalSteps()
            ->where('approver_id', $user->getKey())
            ->exists();
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
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]) && (int) $user->getKey() === (int) $requestForm->requester_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, RequestForm $requestForm): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]) && (int) $user->getKey() === (int) $requestForm->requester_id;
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

    public function cancel(User $user, RequestForm $requestForm): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]) && (int) $requestForm->requester_id ===
            (int) $user->getKey();
    }
}
