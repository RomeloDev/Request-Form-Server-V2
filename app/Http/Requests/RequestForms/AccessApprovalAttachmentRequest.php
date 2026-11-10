<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestApprovalAttachment;
use App\Models\RequestApprovalStep;
use App\Models\RequestForm;
use Illuminate\Foundation\Http\FormRequest;

class AccessApprovalAttachmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');
        $approvalStep = $this->route('approvalStep');
        $attachment = $this->route('approvalAttachment');

        if (
            ! $requestForm instanceof RequestForm
            || ! $approvalStep instanceof RequestApprovalStep
            || ! $attachment instanceof RequestApprovalAttachment
        ) {
            return false;
        }

        if (
            (int) $approvalStep->request_form_id !== (int) $requestForm->getKey()
            || (int) $attachment->request_approval_step_id !== (int) $approvalStep->getKey()
        ) {
            return false;
        }

        return $this->user()?->can('viewAttachment', $requestForm) ?? false;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'approver_id' => ['prohibited'],
            'path' => ['prohibited'],
        ];
    }
}
