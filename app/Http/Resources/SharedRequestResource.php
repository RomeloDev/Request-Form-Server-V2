<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SharedRequestResource extends JsonResource
{
    /**
     * Return only the fields needed by the recipient's shared requests list.
     *
     * Form data, items, attachments, approvals, signatures, and sensitive
     * requester fields must never be exposed here.
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
            'status' => $this->status->value,

            'requester' => [
                'id' => $this->requester->id,
                'firstname' => $this->requester->firstName,
                'lastname' => $this->requester->lastName,
                'position' => $this->requester->position,
            ],

            'branch' => [
                'branch_code' => $this->branch->branch_code,
                'branch_name' => $this->branch->branch_name,
                'branch' => $this->branch->branch,
            ],

            'shared_at' => $this->pivot?->created_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
