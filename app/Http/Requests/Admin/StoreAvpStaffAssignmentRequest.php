<?php

namespace App\Http\Requests\Admin;

use App\Models\AvpStaffAssignment;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAvpStaffAssignmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', AvpStaffAssignment::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'avp_user_id' => [
                'required',
                'integer',
                Rule::exists(User::class, 'id'),
            ],
            'staff_user_id' => [
                'required',
                'integer',
                'different:avp_user_id',
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
