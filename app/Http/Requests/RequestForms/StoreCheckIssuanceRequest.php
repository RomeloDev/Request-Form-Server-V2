<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use App\Models\User;
use App\Support\RequestKindOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreCheckIssuanceRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'create',
            RequestForm::class,
        ) ?? false;
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
                'required',
                'string',
                'max:100',
                Rule::in(RequestKindOptions::VALUES),
            ],

            'form_data' => [
                'required',
                'array:payee,bank,account_no,swift_code',
            ],

            'form_data.payee' => [
                'required',
                'string',
                'max:255',
            ],

            'form_data.bank' => [
                'required',
                'string',
                'max:255',
            ],

            'form_data.account_no' => [
                'required',
                'string',
                'max:255',
            ],

            'form_data.swift_code' => [
                'nullable',
                'string',
                'max:255',
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
            * Every Check Issuance needs at least one final approver.
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

            'attachments' => [
                'sometimes',
                'array',
                'max:10',
            ],

            'attachments.*' => [
                'required',
                'file',
                'mimes:pdf,jpg,jpeg,png,webp,docx,xlsx',
                'max:5120',
            ],

            'attachment' => ['prohibited'],

            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'request_code' => ['prohibited'],
            'form_type' => ['prohibited'],
            'currency' => ['prohibited'],
            'status' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'date' => ['prohibited'],
            'supplier_id' => ['prohibited'],
            'purpose_id' => ['prohibited'],
            'removed_attachment_ids' => ['prohibited'],

            'items.*.id' => ['prohibited'],
            'items.*.line_number' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],
            'items.*.total_amount' => ['prohibited'],
            'items.*.totalAmount' => ['prohibited'],

            'approval_steps' => ['prohibited'],
            'approver_id' => ['prohibited'],
            'level' => ['prohibited'],
            'step_type' => ['prohibited'],
            'approval_status' => ['prohibited'],
            'acted_at' => ['prohibited'],
        ];
    }

    /** @return array<int, callable> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $notedByIds = $this->input(
                    'noted_by_ids',
                    [],
                );

                $approvedByIds = $this->input(
                    'approved_by_ids',
                    [],
                );

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
