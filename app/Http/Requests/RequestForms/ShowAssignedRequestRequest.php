<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ShowAssignedRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && ($this->user()?->can('viewAssignedRequest', $requestForm) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'approver_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'approval_step_id' => ['prohibited'],
        ];
    }
}
