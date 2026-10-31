<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchHeadAssignmentResource extends JsonResource
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
            'user' => [
                'id' => $this->user->id,
                'firstname' => $this->user->firstName,
                'lastname' => $this->user->lastName,
                'position' => $this->user->position,
                'branch_code' => $this->user->branch_code,
                'branch' => $this->user->branch,
            ],
            'branches' => $this->branches
                ->map(function (Branch $branch): array {
                    return [
                        'id' => $branch->id,
                        'branch_code' => $branch->branch_code,
                        'branch_name' => $branch->branch_name,
                        'branch' => $branch->branch,
                        'acronym' => $branch->acronym,
                    ];
                })
                ->values(),

            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
