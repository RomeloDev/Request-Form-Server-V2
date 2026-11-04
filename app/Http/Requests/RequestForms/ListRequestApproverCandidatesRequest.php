<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRequestApproverCandidatesRequest extends FormRequest
{
    /**
     * The same policy ability used to create requests controls access
     * to its supporting candidate endpoint.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            RequestForm::class
        ) ?? false;
    }

    /**
     * Validate search, sorting, and pagination input.
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
            'sort' => [
                'nullable',
                Rule::in([
                    'firstname',
                    'lastname',
                    'position',
                    'branch',
                ]),
            ],
            'direction' => [
                'nullable',
                Rule::in([
                    'asc',
                    'desc',
                ]),
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
             * The server derives the requester and branch from the
             * authenticated Sanctum session.
             */
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
