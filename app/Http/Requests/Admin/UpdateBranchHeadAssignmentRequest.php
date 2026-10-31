<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Models\BranchHeadAssignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBranchHeadAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof BranchHeadAssignment && ($this->user()?->can('update', $assignment) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'branch_ids' => [
                'required',
                'array',
                'min:1',
            ],
            'branch_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(Branch::class, 'id'),
            ],
            // Branch Head identity is immutable during editing.
            'user_id' => ['prohibited'],

            // The browser cannot choose these fields.
            'id' => ['prohibited'],
            'role' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ];
    }
}
