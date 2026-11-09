<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateStockRequisitionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && ($this->user()?->can('update', $requestForm) ?? false);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'updated_at' => ['required', 'date'],
            'kind_of_request' => [
                'nullable',
                'string',
                'max:100',
            ],
            'form_data' => [
                'required',
                'array:purpose',
            ],
            'form_data.purpose' => [
                'required',
                'string',
                'max:1000',
            ],

            'noted_by_ids' => [
                'sometimes',
                'array',
                'max:10',
            ],

            'noted_by_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(User::class, 'id'),
            ],

            'approved_by_ids' => [
                'required',
                'array',
                'min:1',
                'max:10',
            ],

            'approved_by_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(User::class, 'id'),
            ],
            'items' => [
                'required',
                'array',
                'min:1',
                'max:100',
            ],
            'items.*' => [
                'required',
                'array:quantity,description,unit_cost,remarks',
            ],
            'items.*.quantity' => [
                'required',
                'integer',
                'min:1',
                'max:100000',
            ],
            'items.*.description' => [
                'required',
                'string',
                'max:1000',
            ],
            'items.*.unit_cost' => [
                'required',
                'numeric',
                'min:0',
                'lte:999999',
                'decimal:0,2',
            ],
            'items.*.remarks' => [
                'nullable',
                'string',
                'max:2000',
            ],

            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'request_code' => ['prohibited'],
            'form_type' => ['prohibited'],
            'currency' => ['prohibited'],
            'status' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'items.*.line_number' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],

            'attachments' => ['missing'],
            'removed_attachment_ids' => ['missing'],
            'attachment' => ['missing'],
            'approval_steps' => ['missing'],
            'approver_id' => ['prohibited'],
            'level' => ['prohibited'],
            'step_type' => ['prohibited'],
            'approval_status' => ['prohibited'],
            'acted_at' => ['prohibited'],
        ];
    }

    /**
     * Perform validation that compares multiple input fields.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $notedByIds = $this->input('noted_by_ids', []);
                $approvedByIds = $this->input('approved_by_ids', []);

                if (
                    ! is_array($notedByIds)
                    || ! is_array($approvedByIds)
                ) {
                    return;
                }

                $overlappingIds = array_intersect(
                    array_map('intval', $notedByIds),
                    array_map('intval', $approvedByIds),
                );

                if ($overlappingIds !== []) {
                    $validator->errors()->add(
                        'approved_by_ids',
                        'A user cannot be both Noted By and Approved By.',
                    );
                }
            },
        ];
    }
}
