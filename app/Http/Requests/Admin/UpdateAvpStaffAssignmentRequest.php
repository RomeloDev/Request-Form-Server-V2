<?php

namespace App\Http\Requests\Admin;

use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAvpStaffAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $assignment = $this->route('assignment');

        return $assignment instanceof AvpStaffAssignment
            && ($this->user()?->can('update', $assignment) ?? false);
    }

    /**
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
            'avp_user_id' => ['prohibited'],
            'staff_user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'is_admin' => ['prohibited'],
        ];
    }
}
