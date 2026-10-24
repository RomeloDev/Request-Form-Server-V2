<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    /**
     * A basic feature test example.
     */

    use RefreshDatabase;

    public function test_empty_registration_requires_a_signature(): void
    {
        $response = $this->postJson('/api/v1/register', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['signature']);
    }
}
