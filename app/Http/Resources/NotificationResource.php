<?php

namespace App\Http\Resources;

use App\Support\NotificationPayload;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;
use InvalidArgumentException;

/**
 * Safe API representation of a database notification.
 *
 * Only the Prompt 1 contract plus the notification's own timestamps is
 * returned. The internal type, morph columns, updated_at, and the raw data
 * blob are never exposed.
 *
 * @mixin DatabaseNotification
 */
class NotificationResource extends JsonResource
{
    /**
     * Transform the notification into a stable, safe array.
     *
     * Records that do not follow the complete new contract are normalized to
     * null fields rather than exposing any part of their raw payload.
     *
     * @return array{
     *     id: string,
     *     kind: string|null,
     *     title: string|null,
     *     message: string|null,
     *     target: string|null,
     *     request_id: int|null,
     *     request_code: string|null,
     *     form_type: string|null,
     *     status: string|null,
     *     approval_level: int|null,
     *     approval_step_type: string|null,
     *     actor_name: string|null,
     *     read_at: string|null,
     *     created_at: string|null
     * }
     */
    public function toArray(Request $request): array
    {
        try {
            $data = NotificationPayload::fromArray(
                is_array($this->data) ? $this->data : [],
            )->toArray();
        } catch (InvalidArgumentException) {
            $data = [];
        }

        return [
            'id' => $this->id,
            'kind' => self::string($data, 'kind'),
            'title' => self::string($data, 'title'),
            'message' => self::string($data, 'message'),
            'target' => self::string($data, 'target'),
            'request_id' => self::integer($data, 'request_id'),
            'request_code' => self::string($data, 'request_code'),
            'form_type' => self::string($data, 'form_type'),
            'status' => self::string($data, 'status'),
            'approval_level' => self::integer($data, 'approval_level'),
            'approval_step_type' => self::string($data, 'approval_step_type'),
            'actor_name' => self::string($data, 'actor_name'),
            'read_at' => $this->read_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function string(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function integer(array $data, string $key): ?int
    {
        $value = $data[$key] ?? null;

        return is_int($value) ? $value : null;
    }
}
