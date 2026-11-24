<?php

namespace App\Http\Requests\Feedback;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
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
        if (is_string($this->email)) {
            $this->merge(['email' => Str::lower(trim($this->email))]);
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
            'name' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],
            'email' => [
                'required',
                'email',
                'max:255',
            ],
            'phone' => [
                'nullable',
                'digits:11',
            ],
            'department' => [
                'required',
                'string',
                'min:2',
                'max:255',
            ],
            'opinion' => [
                'required',
                'string',
                'max:255',
                Rule::in([
                    'suggestion',
                    'complaint',
                    'compliment',
                    'other',
                ]),
            ],
            'other_opinion' => [
                'nullable',
                'required_if:opinion,other',
                'prohibited_unless:opinion,other',
                'string',
                'min:2',
                'max:255',
            ],
            'message' => [
                'required',
                'string',
                'min:2',
                'max:2000',
            ],

            'id' => ['prohibited'],
            'feedback_code' => ['prohibited'],
            'created_at' => ['prohibited'],
            'updated_at' => ['prohibited'],
        ];
    }
}
