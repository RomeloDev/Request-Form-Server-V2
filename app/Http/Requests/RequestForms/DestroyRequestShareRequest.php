<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class DestroyRequestShareRequest extends FormRequest
{
    /**
     * Authorization checks ownership only. The controller and service
     * separately enforce Completed status as a 409 business-state conflict.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && ($this->user()?->can(
                'revokeShare',
                $requestForm,
            ) ?? false);
    }

    /**
     * The recipient is a nested route identifier, so the body must not
     * override any server-derived field.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'role' => ['prohibited'],
            'status' => ['prohibited'],
        ];
    }
}
