<?php

namespace App\Http\Requests\RequestForms;

use App\Enums\RequestFormType;
use App\Enums\RequestStatus;
use App\Models\RequestForm;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListRequestReportsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('viewReports', RequestForm::class) ?? false;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $statuses = array_map(
            static fn (RequestStatus $status): string => $status->value,
            RequestStatus::cases(),
        );

        $formTypes = array_map(
            static fn (RequestFormType $formtype): string => $formtype->value,
            RequestFormType::cases(),
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

            'form_type' => [
                'nullable',
                'string',
                Rule::in(['ALL', ...$formTypes]),
            ],

            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id'),
            ],

            'date_from' => [
                'nullable',
                'required_with:date_to',
                'date_format:Y-m-d',
            ],

            'date_to' => [
                'nullable',
                'required_with:date_from',
                'date_format:Y-m-d',
                'after_or_equal:date_from',
            ],

            'sort' => [
                'nullable',
                'string',
                Rule::in([
                    'id',
                    'request_code',
                    'form_type',
                    'status',
                    'grand_total',
                    'created_at',
                ]),
            ],

            'direction' => [
                'nullable',
                'string',
                Rule::in(['asc', 'desc']),
            ],

            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'per_page' => [
                'nullable',
                'integer',
                Rule::in([10, 20, 50]),
            ],

            /*
            * The report is organization-wide because the policy grants that
            * capability. The browser must not choose an identity or bypass the
            * normal soft-delete scope.
            */
            'user_id' => ['prohibited'],
            'requester_id' => ['prohibited'],
            'role' => ['prohibited'],
            'include_deleted' => ['prohibited'],
            'deleted_at' => ['prohibited'],
        ];
    }
}
