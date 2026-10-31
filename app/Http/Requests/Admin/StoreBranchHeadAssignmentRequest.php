<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Models\BranchHeadAssignment;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBranchHeadAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', BranchHeadAssignment::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id'),
            ],
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
            'id' => ['prohibited'],
            'role' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ];
    }
}
