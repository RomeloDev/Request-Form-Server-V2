<?php

namespace App\Http\Resources;

use App\Models\RequestApprovalStep;
use App\Models\RequestAttachment;
use App\Models\RequestItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestFormResource extends JsonResource
{
    /**
     * Transform a request form into a safe API response.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_code' => $this->request_code,
            'form_type' => $this->form_type->value,
            'kind_of_request' => $this->kind_of_request,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'form_data' => $this->form_data,
            'grand_total' => $this->grand_total,

            /*
             * Only include relationships that the query has explicitly
             * loaded. This helps avoid accidental N+1 queries.
             */
            'requester' => $this->whenLoaded(
                'requester',
                fn (): array => [
                    'id' => $this->requester->id,
                    'firstname' => $this->requester->firstName,
                    'lastname' => $this->requester->lastName,
                    'position' => $this->requester->position,
                ],
            ),

            'branch' => $this->whenLoaded(
                'branch',
                fn (): array => [
                    'id' => $this->branch->id,
                    'branch_code' => $this->branch->branch_code,
                    'branch_name' => $this->branch->branch_name,
                    'branch' => $this->branch->branch,
                    'acronym' => $this->branch->acronym,
                ],
            ),

            'items' => $this->whenLoaded(
                'items',
                fn () => $this->items
                    ->map(
                        fn (RequestItem $item): array => [
                            'id' => $item->id,
                            'line_number' => $item->line_number,
                            'quantity' => $item->quantity,
                            'unit' => $item->unit,
                            'description' => $item->description,
                            'unit_cost' => $item->unit_cost,
                            'line_total' => $item->line_total,
                            'remarks' => $item->remarks,
                            'item_data' => $item->item_data,
                        ]
                    )
                    ->values(),
            ),

            'attachments' => $this->whenLoaded(
                'attachments',
                fn () => $this->attachments
                    ->map(
                        fn (RequestAttachment $attachment): array => [
                            'id' => $attachment->id,
                            'original_name' => $attachment->original_name,
                            'mime_type' => $attachment->mime_type,
                            'size_bytes' => $attachment->size_bytes,
                        ]
                    )
                    ->values(),
            ),

            'approval_steps' => $this->whenLoaded(
                'approvalSteps',
                fn () => $this->approvalSteps
                    ->map(
                        fn (RequestApprovalStep $step): array => [
                            'id' => $step->id,
                            'level' => $step->level,
                            'step_type' => $step->step_type->value,
                            'status' => $step->status->value,
                            'comment' => $step->comment,
                            'acted_at' => $step->acted_at?->toISOString(),

                            /*
                            * Return only the approver information needed by the
                            * request details screen.
                            */
                            'approver' => [
                                'id' => $step->approver->id,
                                'firstname' => $step->approver->firstName,
                                'lastname' => $step->approver->lastName,
                                'position' => $step->approver->position,
                            ],
                        ],
                    )
                    ->values(),
            ),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
