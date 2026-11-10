<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRequestShareCandidatesRequest extends FormRequest
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
                'listShareCandidates',
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
             * The requester, branch, role, and status are derived from the
             * authenticated session and the bound request, never from the
             * browser.
             */
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
