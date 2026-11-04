<?php

namespace App\Http\Requests\RequestForms;

use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
