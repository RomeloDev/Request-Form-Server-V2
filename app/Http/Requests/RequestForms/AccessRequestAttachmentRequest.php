<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestAttachment;
use App\Models\RequestForm;
use Illuminate\Foundation\Http\FormRequest;

class AccessRequestAttachmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');
        $attachment = $this->route('attachment');

        if (! $requestForm instanceof RequestForm || ! $attachment instanceof RequestAttachment) {
            return false;
        }

        if ((int) $attachment->request_form_id !== (int) $requestForm->getKey()) {
            return false;
        }

        return $this->user()?->can('viewAttachment', $requestForm) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
