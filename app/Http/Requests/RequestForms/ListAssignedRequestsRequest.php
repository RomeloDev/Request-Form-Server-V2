<?php

namespace App\Http\Requests\RequestForms;

use App\Enums\RequestStatus;
use App\Models\RequestForm;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListAssignedRequestsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewAssigned', RequestForm::class) ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statuses = array_map(
            fn (RequestStatus $status): string => $status->value,
            RequestStatus::cases(),
        );

        return [
            'search' => [
                'nullable',
                'string',
                'max:100',
            ],

            'status' => [
                'nullable',
                'string',
                Rule::in(['ALL', ...$statuses]),
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                'min:1',
                'max:50',
            ],

            /*
             * Identity and assignment fields are always derived by Laravel.
             */
            'user_id' => ['prohibited'],
            'approver_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'approval_step_id' => ['prohibited'],
        ];
    }
}
