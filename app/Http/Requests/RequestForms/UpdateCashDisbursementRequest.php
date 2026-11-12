<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestAttachment;
use App\Models\RequestForm;
use App\Models\User;
use App\Support\RequestCurrencies;
use App\Support\RequestKindOptions;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateCashDisbursementRequest extends FormRequest
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
        $requestForm = $this->route('requestForm');
        $requestFormId = $requestForm instanceof RequestForm
            ? $requestForm->getKey()
            : 0;

        return [
            /*
            * The browser sends the version it loaded. The service compares
            * this against the locked row to reject stale updates.
            */
            'updated_at' => ['required', 'date'],

            'kind_of_request' => [
                'required',
                'string',
                'max:100',
                Rule::in(RequestKindOptions::VALUES),
            ],

            /*
            * Cash Disbursement currency stays requester-editable on update,
            * matching the reference edit workflow. The allowlist keeps the
            * stored value to ISO-style codes the rest of the system knows.
            */
            'currency' => [
                'required',
                'string',
                Rule::in(RequestCurrencies::VALUES),
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
            * Every Cash Disbursement needs at least one final approver.
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

            /*
            * A removed attachment must belong to the exact request resolved
            * by route binding. Without this scoped exists rule, a user could
            * send another request's attachment ID and delete it.
            */
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

            /*
            * Cash Disbursement has no form-specific header data.
            * Rejecting form_data prevents arbitrary JSON from being stored.
            */
            'form_data' => ['prohibited'],

            /*
            * Reject the singular field so the API has one unambiguous
            * attachments[] contract.
            */
            'attachment' => ['prohibited'],

            /*
            * These values are controlled by Laravel or calculated from the
            * validated items. Sending any of them produces a 422 validation
            * response instead of silently accepting a dangerous value.
            *
            * currency, updated_at, attachments, and removed_attachment_ids
            * are intentionally editable on update.
            */
            'id' => ['prohibited'],
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'request_code' => ['prohibited'],
            'form_type' => ['prohibited'],
            'status' => ['prohibited'],
            'grand_total' => ['prohibited'],
            'date' => ['prohibited'],
            'supplier_id' => ['prohibited'],

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

                /*
                * Existing files not selected for removal are retained.
                * Removals plus new uploads must never exceed ten.
                */
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
