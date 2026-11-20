<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FeedbackResource extends JsonResource
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
            'feedback_code' => $this->feedback_code,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'department' => $this->department,
            'opinion' => $this->opinion,
            'other_opinion' => $this->other_opinion,
            'message' => $this->message,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
