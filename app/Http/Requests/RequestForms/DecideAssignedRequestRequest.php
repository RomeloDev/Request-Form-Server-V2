<?php

namespace App\Http\Requests\RequestForms;

use App\Enums\ApprovalStepType;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'attachments' => [
                'sometimes',
                'array',
                'max:10',
            ],
            'attachments.*' => [
                'required',
                'file',
                'mimes:png,jpg,jpeg,webp,gif,ico',
                'max:5120',
            ],

            'user_id' => ['prohibited'],
            'approver_id' => ['prohibited'],
            'approval_step_id' => ['prohibited'],
            'level' => ['prohibited'],
            'status' => ['prohibited'],
            'request_status' => ['prohibited'],
            'acted_at' => ['prohibited'],
        ];
    }

    /**
     * Restrict approved attachments to delegated staff approvals.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (
                    ! $this->hasFile('attachments')
                    || $validator->errors()->has('attachments')
                    || $validator->errors()->has('attachments.*')
                ) {
                    return;
                }

                if ($this->input('action') !== 'approve') {
                    $validator->errors()->add(
                        'attachments',
                        'Approved attachments may only be uploaded with an approval.',
                    );

                    return;
                }

                $requestForm = $this->route('requestForm');
                $approverId = $this->user()?->getKey();

                if (! $requestForm instanceof RequestForm || $approverId === null) {
                    return;
                }

                $assignedStep = RequestApprovalStep::query()
                    ->where('request_form_id', $requestForm->getKey())
                    ->where('approver_id', $approverId)
                    ->first();

                if (
                    $assignedStep?->step_type !==
                    ApprovalStepType::DelegatedApproval
                ) {
                    $validator->errors()->add(
                        'attachments',
                        'Only delegated approval staff may upload approved attachments.',
                    );
                }
            },
        ];
    }
}
