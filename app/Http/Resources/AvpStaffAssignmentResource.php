<?php

namespace App\Http\Resources;

use App\Models\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AvpStaffAssignmentResource extends JsonResource
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

            /*
            * The AvpStaffAssignment model has an avp() relationship.
            * The related object is a User model.
            */
            'avp' => [
                'id' => $this->avp->id,
                'firstname' => $this->avp->firstName,
                'lastname' => $this->avp->lastName,
                'position' => $this->avp->position,
            ],

            /*
            * The AvpStaffAssignment model has a staff() relationship.
            * Use that relationship—not User::avpStaffAssignment().
            */
            'staff' => [
                'id' => $this->staff->id,
                'firstname' => $this->staff->firstName,
                'lastname' => $this->staff->lastName,
                'position' => $this->staff->position,
            ],

            /*
            * One assignment may contain several related branches.
            * map() transforms every Branch model into a safe API array.
            */
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
