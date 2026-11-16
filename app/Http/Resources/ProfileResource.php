<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
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
            'name' => $this->name,
            'firstName' => $this->firstName,
            'lastName' => $this->lastName,
            'email' => $this->email,
            'contact' => $this->contact,
            'userName' => $this->userName,
            'employee_id' => $this->employee_id,
            'role' => $this->whenLoaded(
                'roles',
                fn (): ?string => $this->roles->first()?->name,
            ),
            'position' => $this->position,
            'position_label' => $this->when(
                $this->relationLoaded('positionRecord'),
                fn (): string => $this->positionRecord?->label ?? $this->position,
                $this->position,
            ),
            'branch_code' => $this->branch_code,
            'branch' => $this->branch,
            'branch_name' => $this->whenLoaded(
                'assignedBranch',
                fn (): ?string => $this->assignedBranch?->branch_name,
            ),
            'has_profile_picture' => filled($this->profile_picture),
            'has_signature' => filled($this->signature),
        ];
    }
}
