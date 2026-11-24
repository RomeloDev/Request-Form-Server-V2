<?php

namespace App\Http\Requests\Auth;

use App\Models\Branch;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Override;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $normalized = [];

        if (is_string($this->input('email'))) {
            $normalized['email'] = Str::lower(trim($this->input('email')));
        }

        if (is_string($this->input('userName'))) {
            $normalized['userName'] = Str::lower(trim($this->input('userName')));
        }

        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'contact' => ['required', 'string', 'regex:/^\d{11}$/'],
            'branch_code' => [
                'required',
                'string',
                'max:255',
                Rule::exists(Branch::class, 'branch_code'),
            ],

            /* Laravel derives the company from the selected branch code. */
            'branch' => ['prohibited'],
            'userName' => [
                'required', 'string', 'min:5', 'max:255',
                'lowercase', 'alpha_dash', 'unique:users,userName',
            ],
            'employee_id' => ['required', 'string', 'max:255', 'unique:users,employee_id'],
            'email' => [
                'required',
                'string',
                'email:rfc',
                'max:255',
                Rule::unique('users', 'email'),
            ],
            'password' => ['required', 'string', 'min:8', 'max:255'],
            'position' => [
                'required', 'string', 'max:255',
                Rule::exists('positions', 'value'),
            ],
            'signature' => [
                'required',
                'file',
                'image',
                'mimes:png',
                'max:3072',
                'dimensions:max_width=4096,max_height=2048',
            ],

            // These values are generated or assigned only by the server.
            'id' => ['prohibited'],
            'name' => ['prohibited'],
            'role' => ['prohibited'],
            'roles' => ['prohibited'],
            'user_id' => ['prohibited'],
            'email_verified_at' => ['prohibited'],
            'remember_token' => ['prohibited'],
            'profile_picture' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }

    #[Override]
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
