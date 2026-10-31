<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchHeadBranchResource extends JsonResource
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
            'branch_code' => $this->branch_code,
            'branch_name' => $this->branch_name,
            'branch' => $this->branch,
            'acronym' => $this->acronym,
        ];
    }
}
