<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
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
            'contact' => ['required', 'string', 'max:255'],
            'branch_code' => ['required', 'string', 'max:255', 'exists:branches,id'],
            'branch' => ['required', 'string', 'max:255'],
            'userName' => [
                'required', 'string', 'min:5', 'max:255',
                'lowercase', 'alpha_dash', 'unique:users,userName',
            ],
            'employee_id' => ['required', 'string', 'max:255', 'unique:users,employee_id'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'min:8'],
            'position' => [
                'required', 'string', 'max:255',
                Rule::exists('positions', 'value'),
            ],
            'signature' => ['required', 'image', 'mimes:png', 'max:3072'],
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
