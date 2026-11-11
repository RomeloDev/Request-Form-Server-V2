<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowSharedRequestRequest extends FormRequest
{
    /**
     * Only a user in the request's sharedRecipients relationship may view
     * it through the recipient-side endpoint.
     *
     * The policy reads the authenticated user and the bound request; no
     * recipient ID from the browser is trusted.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && ($this->user()?->can(
                'viewSharedRequest',
                $requestForm,
            ) ?? false);
    }

    /**
     * Reject any client attempt to override server-derived identity or
     * state.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
