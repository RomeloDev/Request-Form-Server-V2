<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRequestShareRequest extends FormRequest
{
    /**
     * Authorization checks ownership only. The controller and service
     * separately enforce Completed status as a 409 business-state conflict.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && ($this->user()?->can(
                'share',
                $requestForm,
            ) ?? false);
    }

    /**
     * Validate the selected recipients.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_ids' => [
                'required',
                'array',
                'min:1',
                'max:50',
            ],
            'user_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('users', 'id'),
            ],

            /*
             * The request, requester, recipient identity, role, and status
             * are derived from the bound request and authenticated session.
             */
            'requester_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
            'request_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_ids.required' => 'Select at least one recipient.',
            'user_ids.min' => 'Select at least one recipient.',
            'user_ids.max' => 'You may share with up to 50 recipients at a time.',
            'user_ids.*.integer' => 'Each recipient must be a valid user ID.',
            'user_ids.*.distinct' => 'Each recipient may only be selected once.',
            'user_ids.*.exists' => 'Each selected recipient must exist.',
        ];
    }
}
