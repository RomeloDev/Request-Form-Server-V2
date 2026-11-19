<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into the Admin Users Page contract.
     *
     * Media files are represented by boolean flags only. The stored
     * profile-picture and signature values must never reach the client.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstname' => $this->firstName,
            'lastname' => $this->lastName,
            'username' => $this->userName,
            'email' => $this->email,
            'employee_id' => $this->employee_id,
            'role' => $this->getRoleNames()->first(),
            'position' => $this->position,
            'contact' => $this->contact,
            'branch_code' => $this->branch_code,
            'branch' => $this->branch,
            'email_verified_at' => $this->email_verified_at,
            'verification_status' => $this->email_verified_at !== null
                ? 'Verified'
                : 'Not Verified',
            'has_profile_picture' => filled($this->profile_picture),
            'has_signature' => filled($this->signature),
        ];
    }
}
