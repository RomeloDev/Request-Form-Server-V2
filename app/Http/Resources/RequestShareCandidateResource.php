<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RequestShareCandidateResource extends JsonResource
{
    /**
     * Return only the fields needed by the share recipient selector.
     *
     * Email, contact details, employee ID, signature, and roles must never
     * be exposed by this endpoint.
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
        ];
    }
}
