<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Foundation\Http\FormRequest;

class ShowPrintableRequestRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && $this->user()?->can(
                'viewPrintable',
                $requestForm
            ) === true;
    }

    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'recipient_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
        ];
    }
}
