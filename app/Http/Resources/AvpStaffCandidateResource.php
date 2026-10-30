<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvpStaffCandidateResource extends JsonResource
{
    /**
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
            'branch' => $this->branch,
        ];
    }
}
