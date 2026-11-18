<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfileSignatureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('manageSignature', $user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'signature' => [
                'required',
                'file',
                'image',
                'mimes:png',
                'max:3072',
                'dimensions:max_width=4096,max_height=2048',
            ],

            // Reject server-owned identity and storage fields explicitly.
            'user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'signature_path' => ['prohibited'],
            'disk' => ['prohibited'],
        ];
    }
}
