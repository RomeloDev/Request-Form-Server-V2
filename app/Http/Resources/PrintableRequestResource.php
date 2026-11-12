<?php

namespace App\Http\Resources;

use App\Enums\RequestFormType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrintableRequestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_code' => $this->request_code,
            'form_type' => $this->form_type->value,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'purpose' => data_get($this->form_data, 'purpose', ''),
            'form_data' => $this->when(
                $this->form_type === RequestFormType::PurchaseOrder,
                fn (): array => $this->purchaseOrderFormData(),
            ),
            'grand_total' => $this->grand_total,

            'branch' => [
                'branch_code' => $this->branch->branch_code,
                'branch_name' => $this->branch->branch_name,
                'branch' => $this->branch->branch,
                'acronym' => $this->branch->acronym,
            ],

            'requester' => [
                'id' => $this->requester->id,
                'firstname' => $this->requester->firstName,
                'lastname' => $this->requester->lastName,
                'position' => $this->requester->position,
            ],

            'items' => $this->items->map(
                fn ($item): array => [
                    'line_number' => $item->line_number,
                    'quantity' => $item->quantity,
                    'description' => $item->description,
                    'unit_cost' => $item->unit_cost,
                    'line_total' => $item->line_total,
                    'remarks' => $item->remarks,
                ],
            )->values(),

            'approval_steps' => $this->approvalSteps->map(
                fn ($step): array => [
                    'level' => $step->level,
                    'step_type' => $step->step_type->value,
                    'status' => $step->status->value,
                    'approver' => [
                        'id' => $step->approver->id,
                        'firstname' => $step->approver->firstName,
                        'lastname' => $step->approver->lastName,
                        'position' => $step->approver->position,
                    ],
                ],
            )->values(),

            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * Return only the Purchase Order fields required by its print template.
     *
     * @return array{supplier: string, address: string}
     */
    private function purchaseOrderFormData(): array
    {
        $supplier = data_get($this->form_data, 'supplier');
        $address = data_get($this->form_data, 'address');

        return [
            'supplier' => is_string($supplier) ? trim($supplier) : '',
            'address' => is_string($address) ? trim($address) : '',
        ];
    }
}
