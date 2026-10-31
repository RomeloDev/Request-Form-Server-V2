<?php

namespace App\Http\Requests\Admin;

use App\Models\BranchHeadAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAvailableBranchHeadBranchesRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', BranchHeadAssignment::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
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
                    'id',
                    'branch_code',
                    'branch_name',
                    'branch',
                    'acronym',
                ]),
            ],
            'direction' => [
                'nullable',
                Rule::in([
                    'asc', 'desc',
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
                    10, 20, 50,
                ]),
            ],
        ];
    }
}
