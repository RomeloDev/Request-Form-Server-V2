<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;
use Illuminate\Validation\Rule;

class AssignApproversRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can("viewAny", User::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
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
                Rule::exists('users', 'id')->where(
                    fn ($query) => $query->where('role', 'User')
                ),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_ids.required' =>
                'Select at least one user.',
            'user_ids.min' =>
                'Select at least one user.',
            'user_ids.max' =>
                'You may assign up to 50 users at a time.',
            'user_ids.*.distinct' =>
                'Each user may only be selected once.',
            'user_ids.*.exists' =>
                'Each selected account must exist and currently have the User role.',
        ];
    }
}
