<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;

class UnreadNotificationCountRequest extends FormRequest
{
    /**
     * Any authenticated account may read its own unread count.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'viewUnreadCount',
            DatabaseNotification::class,
        ) ?? false;
    }

    /**
     * The count needs no pagination or filtering input. Identity selectors
     * are prohibited so the count can only ever describe the current user.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'user_id' => ['prohibited'],
            'notifiable_id' => ['prohibited'],
            'notifiable_type' => ['prohibited'],
            'role' => ['prohibited'],
            'type' => ['prohibited'],
        ];
    }
}
