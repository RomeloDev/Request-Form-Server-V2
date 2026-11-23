<?php

namespace Tests\Unit\Enums;

use App\Enums\NotificationTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationTargetTest extends TestCase
{
    #[DataProvider('allowedTargets')]
    public function test_it_defines_an_allowed_notification_target(string $value): void
    {
        $this->assertSame($value, NotificationTarget::from($value)->value);
    }

    public static function allowedTargets(): array
    {
        return [
            'my request' => ['my_request'],
            'assigned request' => ['assigned_request'],
            'shared request' => ['shared_request'],
        ];
    }

    public function test_it_defines_exactly_the_allowed_notification_targets(): void
    {
        $this->assertSame(
            [
                'my_request',
                'assigned_request',
                'shared_request',
            ],
            array_column(NotificationTarget::cases(), 'value'),
        );
    }

    public function test_it_rejects_an_unknown_backing_value(): void
    {
        $this->assertNull(NotificationTarget::tryFrom('owned_request'));
    }
}
