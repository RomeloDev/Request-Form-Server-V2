<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;

class MarkAllNotificationsReadRequest extends FormRequest
{
    /**
     * Any authenticated account may mark its own unread notifications read.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'markAllAsRead',
            DatabaseNotification::class,
        ) ?? false;
    }

    /**
     * This endpoint takes no body input. Ownership and state fields are
     * prohibited so a caller can never choose whose notifications change or
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
