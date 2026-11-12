<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestAttachment;
use App\Models\RequestForm;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $requestForm = $this->route('requestForm');

        return $requestForm instanceof RequestForm
            && ($this->user()?->can('update', $requestForm) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $requestForm = $this->route('requestForm');
        $requestFormId = $requestForm instanceof RequestForm
            ? $requestForm->getKey()
            : 0;

        return [
            'updated_at' => ['required', 'date'],
            'kind_of_request' => [
                'required',
                'string',
                'max:100',
                Rule::in(StorePurchaseOrderRequest::KIND_OPTIONS),
            ],
            'form_data' => [
                'required',
                'array:supplier,address',
            ],
            'form_data.supplier' => [
                'required',
                'string',
                'max:255',
            ],
            'form_data.address' => [
                'required',
                'string',
                'max:255',
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
            'removed_attachment_ids' => [
                'sometimes',
                'array',
                'max:10',
            ],
            'removed_attachment_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists(RequestAttachment::class, 'id')->where(
                    fn ($query) => $query->where(
                        'request_form_id',
                        $requestFormId,
                    ),
                ),
            ],

            'attachment' => ['missing'],
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'supplier_id' => ['prohibited'],
            'request_code' => ['prohibited'],
            'form_type' => ['prohibited'],
            'currency' => ['prohibited'],
            'status' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'date' => ['prohibited'],
            'form_data.grand_total' => ['prohibited'],
            'items.*.line_number' => ['prohibited'],
            'items.*.line_total' => ['prohibited'],
            'items.*.total_amount' => ['prohibited'],
            'items.*.totalAmount' => ['prohibited'],
            'approval_steps' => ['missing'],
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
                $notedByIds = $this->input('noted_by_ids', []);
                $approvedByIds = $this->input('approved_by_ids', []);

                if (! is_array($notedByIds) || ! is_array($approvedByIds)) {
                    return;
                }

                if (array_intersect(
                    array_map('intval', $notedByIds),
                    array_map('intval', $approvedByIds),
                ) !== []) {
                    $validator->errors()->add(
                        'approved_by_ids',
                        'A user cannot be both Noted By and Approved By.',
                    );
                }
            },
            function (Validator $validator): void {
                if (
                    $validator->errors()->has('attachments')
                    || $validator->errors()->has('attachments.*')
                    || $validator->errors()->has('removed_attachment_ids')
                    || $validator->errors()->has('removed_attachment_ids.*')
                ) {
                    return;
                }

                $requestForm = $this->route('requestForm');
                $removedIds = $this->input('removed_attachment_ids', []);
                $newFiles = $this->file('attachments', []);

                if (
                    ! $requestForm instanceof RequestForm
                    || ! is_array($removedIds)
                    || ! is_array($newFiles)
                ) {
                    return;
                }

                $retainedCount = $requestForm
                    ->attachments()
                    ->whereNotIn('id', $removedIds)
                    ->count();

                if ($retainedCount + count($newFiles) > 10) {
                    $validator->errors()->add(
                        'attachments',
                        'A request can have no more than 10 attachments.',
                    );
                }
            },
        ];
    }
}
