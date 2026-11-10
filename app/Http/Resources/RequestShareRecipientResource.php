<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestShareRecipientResource extends JsonResource
{
    /**
     * Return only the fields needed by the shared recipients list.
     *
     * Email, contact details, employee ID, username, roles, verification
     * fields, signature, and authentication fields must never be exposed.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstname' => $this->firstName,
            'lastname' => $this->lastName,
            'position' => $this->position,
            'branch_code' => $this->branch_code,
            'branch_name' => $this->assignedBranch?->branch_name,
            'shared_at' => $this->pivot?->created_at?->toISOString(),
        ];
    }
}
