<?php

namespace Tests\Feature\Feedback;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StoreFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/send-feedback';

    public function test_message_column_is_text_and_accepts_the_validated_limit(): void
    {
        $message = str_repeat('a', 2000);

        $this->assertSame(
            'text',
            Schema::getColumnType('feedback', 'message'),
        );

        $this->postJson(self::ENDPOINT, [
            ...$this->payload(),
            'message' => $message,
        ])
            ->assertCreated()
            ->assertJsonPath(
                'message',
                'Your feedback has been submitted successfully. We will contact you soon.',
            );

        $this->assertDatabaseHas('feedback', ['message' => $message]);
    }

    public function test_server_owned_fields_are_rejected_and_never_persisted(): void
    {
        $this->postJson(self::ENDPOINT, [
            ...$this->payload(),
            'id' => 99,
            'feedback_code' => 'FORGED-CODE',
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'feedback_code',
                'created_at',
                'updated_at',
            ]);

        $this->assertDatabaseCount('feedback', 0);
    }

    public function test_feedback_code_is_generated_only_by_the_server(): void
    {
        $response = $this->postJson(self::ENDPOINT, $this->payload())
            ->assertCreated();

        $code = $response->json('feedback_code');

        $this->assertIsString($code);
        $this->assertStringStartsWith('SMCT-', $code);
        $this->assertDatabaseHas('feedback', ['feedback_code' => $code]);
    }

    public function test_opinion_is_allowlisted_and_other_details_are_contextual(): void
    {
        $this->postJson(self::ENDPOINT, [
            ...$this->payload(),
            'opinion' => 'unsupported',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('opinion');

        $this->postJson(self::ENDPOINT, [
            ...$this->payload(),
            'opinion' => 'suggestion',
            'other_opinion' => 'Forged other value',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('other_opinion');

        $this->postJson(self::ENDPOINT, [
            ...$this->payload(),
            'opinion' => 'other',
            'other_opinion' => null,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('other_opinion');
    }

    /** @return array<string, string|null> */
    private function payload(): array
    {
        return [
            'name' => 'Feedback Tester',
            'email' => 'feedback@example.test',
            'phone' => '09171234567',
            'department' => 'Accounting',
            'opinion' => 'suggestion',
            'other_opinion' => null,
            'message' => 'Please improve the approval workflow.',
        ];
    }
}
