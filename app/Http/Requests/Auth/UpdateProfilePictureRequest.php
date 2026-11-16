<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProfilePictureRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can('manageProfilePicture', $user);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'profile_picture' => [
                'required',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                'max:2048',
                'dimensions:max_width=4096,max_height=4096',
            ],

            // Reject server-owned identity fields explicitly.
            'user_id' => ['prohibited'],
            'role' => ['prohibited'],
            'profile_picture_path' => ['prohibited'],
        ];
    }
}
