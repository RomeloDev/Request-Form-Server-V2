<?php

namespace App\Http\Requests\Dashboard;

use App\Models\RequestForm;
use Illuminate\Foundation\Http\FormRequest;

class ShowDashboardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * The dashboard identity always comes from the authenticated session, so
     * authorization is based on the policy ability rather than request input.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewDashboard', RequestForm::class) ?? false;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            /*
             * The dashboard needs no query filters. Reject identity,
             * authorization, scoping, and financial parameters outright so a
             * hostile client cannot pretend to be another user or request an
             * organization-wide view.
             */
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'approver_id' => ['prohibited'],
            'role' => ['prohibited'],
            'roles' => ['prohibited'],
            'permission' => ['prohibited'],
            'permissions' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'status' => ['prohibited'],
            'total' => ['prohibited'],

            /*
             * The dashboard periods are derived from the server clock, never
             * from the browser. Reject date-range and period selectors so a
             * client cannot request an arbitrary window or a different year.
             */
            'date_from' => ['prohibited'],
            'date_to' => ['prohibited'],
            'from' => ['prohibited'],
            'to' => ['prohibited'],
            'year' => ['prohibited'],
            'month' => ['prohibited'],
        ];
    }
}
