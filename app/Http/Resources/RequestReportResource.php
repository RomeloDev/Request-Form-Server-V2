<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestReportResource extends JsonResource
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
            'kind_of_request' => $this->kind_of_request,
            'currency' => $this->currency,
            'status' => $this->status->value,
            'grand_total' => $this->grand_total,
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
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
