<?php

namespace Tests\Unit\Support;

use App\Enums\NotificationKind;
use App\Enums\NotificationTarget;
use App\Support\NotificationPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use TypeError;

class NotificationPayloadTest extends TestCase
{
    public function test_it_serializes_the_exact_contract_keys_in_order(): void
    {
        $this->assertSame(
            [
                'kind',
                'title',
                'message',
                'target',
                'request_id',
                'request_code',
                'form_type',
                'status',
                'approval_level',
                'approval_step_type',
                'actor_name',
            ],
            array_keys($this->payload()->toArray()),
        );
    }

    public function test_it_serializes_enum_backing_values_as_plain_strings(): void
    {
        $data = $this->payload([
            'kind' => NotificationKind::RequestActionRequired,
            'target' => NotificationTarget::AssignedRequest,
        ])->toArray();

        $this->assertSame('request_action_required', $data['kind']);
        $this->assertSame('assigned_request', $data['target']);
    }

    public function test_it_keeps_absent_nullable_fields_as_null(): void
    {
        $data = $this->payload()->toArray();

        $this->assertNull($data['status']);
        $this->assertNull($data['approval_level']);
        $this->assertNull($data['approval_step_type']);
        $this->assertNull($data['actor_name']);
    }

    public function test_it_serializes_present_nullable_fields(): void
    {
        $data = $this->payload([
            'status' => 'Pending',
            'approvalLevel' => 2,
            'approvalStepType' => 'approved_by',
            'actorName' => 'Jane Doe',
        ])->toArray();

        $this->assertSame('Pending', $data['status']);
        $this->assertSame(2, $data['approval_level']);
        $this->assertSame('approved_by', $data['approval_step_type']);
        $this->assertSame('Jane Doe', $data['actor_name']);
    }

    public function test_it_does_not_serialize_unexpected_or_sensitive_keys(): void
    {
        $data = $this->payload()->toArray();

        foreach ([
            'id',
            'type',
            'notifiable_type',
            'notifiable_id',
            'read_at',
            'created_at',
            'updated_at',
            'user_id',
            'requester_id',
            'approver_id',
            'email',
            'comments',
            'comment',
            'attachments',
            'attachment',
            'storage_path',
            'path',
            'form_data',
            'url',
            'link',
            'data',
        ] as $unexpectedKey) {
            $this->assertArrayNotHasKey($unexpectedKey, $data);
        }
    }

    #[DataProvider('invalidRequestIds')]
    public function test_it_rejects_invalid_request_ids(int $requestId): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payload(['requestId' => $requestId]);
    }

    public static function invalidRequestIds(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
            'large negative' => [-1000],
        ];
    }

    #[DataProvider('emptyRequiredStrings')]
    public function test_it_rejects_empty_required_strings(string $field, string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payload([$field => $value]);
    }

    public static function emptyRequiredStrings(): array
    {
        return [
            'empty title' => ['title', ''],
            'whitespace title' => ['title', "  \t\n"],
            'empty message' => ['message', ''],
            'whitespace message' => ['message', "  \t\n"],
            'empty request code' => ['requestCode', ''],
            'whitespace request code' => ['requestCode', "  \t\n"],
            'empty form type' => ['formType', ''],
            'whitespace form type' => ['formType', "  \t\n"],
        ];
    }

    #[DataProvider('invalidApprovalLevels')]
    public function test_it_rejects_invalid_approval_levels(int $approvalLevel): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payload([
            'approvalLevel' => $approvalLevel,
            'approvalStepType' => 'approved_by',
        ]);
    }

    public static function invalidApprovalLevels(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }

    public function test_it_rejects_an_approval_step_type_without_an_approval_level(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payload(['approvalStepType' => 'approved_by']);
    }

    public function test_it_rejects_an_approval_level_without_an_approval_step_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->payload(['approvalLevel' => 1]);
    }

    #[DataProvider('invalidKindTargetCombinations')]
    public function test_it_rejects_an_invalid_kind_target_combination(
        NotificationKind $kind,
        NotificationTarget $target,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $this->payload([
            'kind' => $kind,
            'target' => $target,
        ]);
    }

    public static function invalidKindTargetCombinations(): array
    {
        return [
            'action required cannot target my requests' => [
                NotificationKind::RequestActionRequired,
                NotificationTarget::MyRequest,
            ],
            'approved cannot target assigned requests' => [
                NotificationKind::RequestApproved,
                NotificationTarget::AssignedRequest,
            ],
            'disapproved cannot target shared requests' => [
                NotificationKind::RequestDisapproved,
                NotificationTarget::SharedRequest,
            ],
            'completed cannot target assigned requests' => [
                NotificationKind::RequestCompleted,
                NotificationTarget::AssignedRequest,
            ],
            'shared cannot target my requests' => [
                NotificationKind::RequestShared,
                NotificationTarget::MyRequest,
            ],
        ];
    }

    #[DataProvider('blankOptionalStrings')]
    public function test_it_rejects_blank_optional_strings(
        string $field,
    ): void {
        $this->expectException(InvalidArgumentException::class);

        $this->payload([$field => " \t\n"]);
    }

    public static function blankOptionalStrings(): array
    {
        return [
            'status' => ['status'],
            'approval step type' => ['approvalStepType'],
            'actor name' => ['actorName'],
        ];
    }

    public function test_it_rebuilds_a_valid_serialized_payload(): void
    {
        $serialized = $this->payload()->toArray();

        $this->assertSame(
            $serialized,
            NotificationPayload::fromArray($serialized)->toArray(),
        );
    }

    public function test_it_rejects_malformed_serialized_field_types(): void
    {
        $serialized = $this->payload()->toArray();
        $serialized['request_id'] = '123';

        $this->expectException(InvalidArgumentException::class);

        NotificationPayload::fromArray($serialized);
    }

    public function test_it_rejects_a_serialized_payload_missing_a_nullable_key(): void
    {
        $serialized = $this->payload()->toArray();
        unset($serialized['actor_name']);

        $this->expectException(InvalidArgumentException::class);

        NotificationPayload::fromArray($serialized);
    }

    public function test_it_rejects_an_unknown_kind_scalar(): void
    {
        $this->expectException(TypeError::class);

        $this->payload(['kind' => 'request_cancelled']);
    }

    public function test_it_rejects_an_unknown_target_scalar(): void
    {
        $this->expectException(TypeError::class);

        $this->payload(['target' => 'owned_request']);
    }

    /**
     * Build a valid payload, overriding only the fields under test.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function payload(array $overrides = []): NotificationPayload
    {
        return new NotificationPayload(...array_merge([
            'kind' => NotificationKind::RequestApproved,
            'title' => 'Request approved',
            'message' => 'Your request has been approved.',
            'target' => NotificationTarget::MyRequest,
            'requestId' => 123,
            'requestCode' => 'REQ-000123',
            'formType' => 'Purchase Order Requisition Slip',
            'status' => null,
            'approvalLevel' => null,
            'approvalStepType' => null,
            'actorName' => null,
        ], $overrides));
    }
}
