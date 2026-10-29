<?php

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user()->can('update', $target) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $target = $this->route('user');

        return [
            'firstName' => ['required', 'string'],
            'lastName' => ['required', 'string'],
            'email' => ['required', 'email', Rule::unique('users', 'email')->ignore($target)],
            'contact' => ['required', 'string'],
            'userName' => ['required', 'string', Rule::unique('users', 'userName')->ignore($target)],
            'employee_id' => ['required', 'string', Rule::unique('users', 'employee_id')->ignore($target)],
            'position' => ['required', 'string'],

            'branch_code' => ['nullable', 'string'],
            'branch' => ['nullable', 'string'],
            'password' => ['nullable', 'string', 'min:6'],
            'profile_picture' => ['nullable', 'image'],
        ];
    }
}
