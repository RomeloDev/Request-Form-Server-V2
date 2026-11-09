<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DecideAssignedRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && (
                $this->user()?->can(
                    'decideAssignedRequest',
                    $requestForm,
                ) ?? false
            );
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => [
                'required',
                'string',
                Rule::in([
                    'approve',
                    'disapprove',
                ]),
            ],

            'comment' => [
                Rule::requiredIf(
                    fn (): bool => $this->input('action') === 'disapprove',
                ),
                'nullable',
                'string',
                'max:2000',
            ],

            'attachment' => ['prohibited'],
            'attachments' => ['prohibited'],

            'user_id' => ['prohibited'],
            'approver_id' => ['prohibited'],
            'approval_step_id' => ['prohibited'],
            'level' => ['prohibited'],
            'status' => ['prohibited'],
            'request_status' => ['prohibited'],
            'acted_at' => ['prohibited'],
        ];
    }
}
