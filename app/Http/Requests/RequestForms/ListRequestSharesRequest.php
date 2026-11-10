<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRequestSharesRequest extends FormRequest
{
    /**
     * Authorization checks ownership only. The controller separately
     * enforces Completed status as a 409 business-state conflict.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && ($this->user()?->can(
                'listShares',
                $requestForm,
            ) ?? false);
    }

    /**
     * Validate search and pagination input.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'nullable',
                'integer',
                Rule::in([
                    10,
                    20,
                    50,
                ]),
            ],

            /*
             * The request, requester, recipient identity, role, and status
             * are derived from the bound request and authenticated session.
             */
            'requester_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'request_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
