<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListSharedRequestsRequest extends FormRequest
{
    /**
     * Any approved authenticated role may list the requests shared with
     * their own account.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'viewSharedRequests',
            RequestForm::class,
        ) ?? false;
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
             * The recipient is always the authenticated user. Identity and
             * status filters must never come from the browser.
             */
            'user_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
