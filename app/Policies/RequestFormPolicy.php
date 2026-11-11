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
     * Determine whether the user may list requests shared with their own
     * account. Approved application roles may use this endpoint.
     */
    public function viewSharedRequests(User $user): bool
    {
        return $user->hasAnyRole([
            'Admin',
            'User',
            'approver',
        ]);
    }

    /**
     * Determine whether the user may view a request that was explicitly
     * shared with their account.
     *
     * This is deliberately relationship-based only. Request ownership does
     * not satisfy this ability; owners use the My Requests endpoint. The
     * recipient identity always comes from the authenticated user, never
     * from a client-supplied parameter.
     */
    public function viewSharedRequest(
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

        return $requestForm->sharedRecipients()
            ->where('users.id', $user->getKey())
            ->exists();
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
     * Determine whether the user may list the recipients a request has
     * been shared with. Only the requester who owns the request may do so.
     */
    public function listShares(
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
     * Determine whether the user may revoke a shared recipient from the
     * request. Only the requester who owns the request may do so.
     */
    public function revokeShare(
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

    /**
     * Determine whether the user may access this request's attachments.
     *
     * Requesters and assigned approvers keep their existing access. An
     * approved user who is a current shared recipient may also access the
     * same attachments. The recipient is always the authenticated user, so a
     * revoked share removes access on the very next request.
     */
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

        if (
            $requestForm->approvalSteps()
                ->where('approver_id', $user->getKey())
                ->exists()
        ) {
            return true;
        }

        /*
         * Scope the check to the exact request resolved by route binding and
         * to the authenticated user's own key. Never accept a recipient ID
         * from the browser.
         */
        return $requestForm->sharedRecipients()
            ->whereKey($user->getKey())
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

        /*
         * A share is looked up from the database on every request. Revoking
         * the share therefore removes printable access immediately.
         *
         * The recipient identity always comes from the authenticated user,
         * never from a query parameter or request payload.
         */
        if ($requestForm->sharedRecipients()
            ->where('users.id', $user->getKey())
            ->exists()) {
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
