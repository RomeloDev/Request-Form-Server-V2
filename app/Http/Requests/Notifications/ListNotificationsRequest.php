<?php

namespace App\Http\Requests\Notifications;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Validation\Rule;

class ListNotificationsRequest extends FormRequest
{
    /**
     * Any authenticated account may list its own notifications.
     */
    public function authorize(): bool
    {
        return $this->user()?->can(
            'viewAny',
            DatabaseNotification::class,
        ) ?? false;
    }

    /**
     * Validate the list filters.
     *
     * The recipient is always the authenticated user. Identity and query
     * overrides must never come from the browser.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'unread_only' => [
                'nullable',
                Rule::in([
                    0,
                    1,
                    'true',
                    'false',
                ]),
            ],
            'page' => [
                'nullable',
                'integer',
                'min:1',
            ],
            'per_page' => [
                'nullable',
                'integer',
                Rule::in([
                    10,
                    20,
                    50,
                ]),
            ],

            'user_id' => ['prohibited'],
            'notifiable_id' => ['prohibited'],
            'notifiable_type' => ['prohibited'],
            'role' => ['prohibited'],
            'type' => ['prohibited'],
        ];
    }
}
