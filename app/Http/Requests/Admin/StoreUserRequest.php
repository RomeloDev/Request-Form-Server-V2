<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

#[FailOnUnknownFields]
class StoreUserRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', User::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Only the fields listed here can reach the creation service. Privileged
     * and storage-related fields are explicitly prohibited so a forged value
     * cannot be mistaken for server-derived data.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'employee_id' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'employee_id'),
            ],
            'contact' => ['required', 'string', 'regex:/^\d{11}$/'],
            'userName' => [
                'required', 'string', 'min:5', 'max:255',
                'lowercase', 'alpha_dash',
                Rule::unique('users', 'userName'),
            ],
            'email' => [
                'required', 'string', 'email', 'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
            'position' => [
                'required', 'string', 'max:255',
                Rule::exists('positions', 'value'),
            ],
            'branch_code' => [
                'required', 'string', 'max:255',
                Rule::exists(Branch::class, 'branch_code'),
            ],

            // These values are server-derived or never accepted from the client.
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'name' => ['prohibited'],
            'role' => ['prohibited'],
            'branch' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'profile_picture' => ['prohibited'],
            'profile_picture_path' => ['prohibited'],
            'signature' => ['prohibited'],
            'signature_path' => ['prohibited'],
            'remember_token' => ['prohibited'],
        ];
    }
}
