<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationKind;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationKindTest extends TestCase
{
    #[DataProvider('allowedKinds')]
    public function test_it_defines_an_allowed_notification_kind(string $value): void
    {
        $this->assertSame($value, NotificationKind::from($value)->value);
    }

    public static function allowedKinds(): array
    {
        return [
            'request action required' => ['request_action_required'],
            'request approved' => ['request_approved'],
            'request disapproved' => ['request_disapproved'],
            'request completed' => ['request_completed'],
            'request shared' => ['request_shared'],
        ];
    }

    public function test_it_defines_exactly_the_allowed_notification_kinds(): void
    {
        $this->assertSame(
            [
                'request_action_required',
                'request_approved',
                'request_disapproved',
                'request_completed',
                'request_shared',
            ],
            array_column(NotificationKind::cases(), 'value'),
        );
    }

    public function test_it_rejects_an_unknown_backing_value(): void
    {
        $this->assertNull(NotificationKind::tryFrom('request_cancelled'));
    }
}
