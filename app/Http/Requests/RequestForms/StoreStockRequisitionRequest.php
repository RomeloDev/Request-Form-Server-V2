<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreStockRequisitionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', RequestForm::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
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
            /*
            * Noted By is optional. If supplied, it must contain a small,
            * ordered list of unique, existing user IDs.
            */
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

            /*
            * Every Stock Requisition needs at least one final approver.
            *
            * The order of this array becomes the approval-chain order.
            */
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
                'array:quantity,unit,description,unit_cost,remarks',
            ],
            'items.*.quantity' => [
                'required',
                'numeric',
                'gt:0',
                'lte:100000',
                'decimal:0,3',
            ],
            'items.*.unit' => [
                'nullable',
                'string',
                'max:50',
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

            /*
             * These values are controlled by Laravel.
             *
             * Sending any of them produces a 422 validation response
             * instead of silently accepting a dangerous value.
             */
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

            'attachments' => [
                'sometimes',
                'array',
                'max:10',
            ],

            'attachments.*' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx',
                'max:5120', // 5 MB
            ],

            /*
            * Reject the singular field so the API has one unambiguous contract.
            */
            'attachment' => ['prohibited'],

            /*
            * The browser selects eligible users, but it must never construct
            * approval-step database records or choose their status and level.
            */
            'approval_steps' => ['prohibited'],
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

                /*
                * Leave malformed values to the normal array rules.
                * This callback only handles valid array-shaped input.
                */
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
