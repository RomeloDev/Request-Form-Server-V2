<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use App\Support\BranchAcronyms;
use Override;

class UpdateBranchRequest extends FormRequest
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
        $company = $this->input('branch');

        $allowedAcronyms = BranchAcronyms::allowedFor(
            is_string($company) ? $company : ''
        );

        $branch = $this->route("branch");

        return [
            'branch_code' => ['required', 'string', 'max:255', Rule::unique('branches', 'branch_code')->ignore($branch)],
            'branch_name' => ['required', 'string', 'max:150'],
            'branch'=> ['required', 'string', 'max:255'],
            'acronym' => [
                'sometimes',
                'required',
                'string',
                Rule::in($allowedAcronyms),
            ],
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
