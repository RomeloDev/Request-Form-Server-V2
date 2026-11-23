<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class MarkNotificationReadRequest extends FormRequest
{
    /**
     * The route is behind auth:sanctum. Ownership is enforced after the
     * controller resolves the record through the authenticated user's
     * relationship, where the markAsRead policy ability runs.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * This endpoint takes no body input. Ownership and state fields are
     * prohibited so a caller can never choose whose notification changes or
     * backdate read_at.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'notification_id' => ['prohibited'],
            'notifiable_id' => ['prohibited'],
            'notifiable_type' => ['prohibited'],
            'read_at' => ['prohibited'],
            'kind' => ['prohibited'],
            'type' => ['prohibited'],
            'data' => ['prohibited'],
            'role' => ['prohibited'],
        ];
    }
}
