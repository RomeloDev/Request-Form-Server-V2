<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Notifications\PasswordResetNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const FORGOT_ENDPOINT = '/api/v1/forgot-password';

    private const RESET_ENDPOINT = '/api/v1/reset-password';

    private const GENERIC_MESSAGE = 'If an account exists for that email, a password reset link has been sent.';

    public function test_known_and_unknown_emails_receive_the_same_response(): void
    {
        Notification::fake();
        $user = $this->user(['email' => 'known@example.test']);

        $known = $this->postJson(self::FORGOT_ENDPOINT, [
            'email' => '  KNOWN@example.test ',
        ])->assertOk()->json();

        $unknown = $this->postJson(self::FORGOT_ENDPOINT, [
            'email' => 'missing@example.test',
        ])->assertOk()->json();

        $this->assertSame(['message' => self::GENERIC_MESSAGE], $known);
        $this->assertSame($known, $unknown);
        Notification::assertSentTo($user, PasswordResetNotification::class);
        Notification::assertCount(1);
        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);
        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => 'missing@example.test',
        ]);
    }

    public function test_recent_link_request_returns_generic_success_without_queueing_twice(): void
    {
        Notification::fake();
        $user = $this->user();

        $this->postJson(self::FORGOT_ENDPOINT, ['email' => $user->email])
            ->assertOk();
        $this->postJson(self::FORGOT_ENDPOINT, ['email' => $user->email])
            ->assertOk()
            ->assertExactJson(['message' => self::GENERIC_MESSAGE]);

        Notification::assertSentToTimes($user, PasswordResetNotification::class, 1);
    }

    public function test_forgot_password_validates_email_and_rejects_extra_fields(): void
    {
        $this->postJson(self::FORGOT_ENDPOINT, [
            'email' => 'not-an-email',
            'token' => 'forged',
            'password' => 'forged-password',
            'user_id' => 1,
            'role' => 'Admin',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'token',
                'password',
                'user_id',
                'role',
            ]);
    }

    public function test_forgot_password_route_blocks_the_sixth_request(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.50']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(self::FORGOT_ENDPOINT, [
                'email' => "missing-{$attempt}@example.test",
            ])->assertOk();
        }

        $this->postJson(self::FORGOT_ENDPOINT, [
            'email' => 'missing-6@example.test',
        ])->assertTooManyRequests();
    }

    public function test_valid_token_resets_password_and_revokes_authentication_state(): void
    {
        config()->set('session.driver', 'database');
        $user = $this->user([
            'email' => 'reset@example.test',
            'password' => 'old-password',
            'remember_token' => 'old-remember-token',
        ]);
        $token = Password::createToken($user);
        $accessToken = $user->createToken('compromised-device')->accessToken;

        DB::table('sessions')->insert([
            'id' => 'compromised-session',
            'user_id' => $user->id,
            'ip_address' => '203.0.113.10',
            'user_agent' => 'Test browser',
            'payload' => 'test-payload',
            'last_activity' => now()->timestamp,
        ]);

        $this->postJson(self::RESET_ENDPOINT, [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
            ->assertOk()
            ->assertExactJson([
                'message' => 'Your password has been reset successfully.',
            ]);

        $user->refresh();
        $this->assertTrue(Hash::check('new-secure-password', $user->password));
        $this->assertFalse(Hash::check('old-password', $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $accessToken->id]);
        $this->assertDatabaseMissing('sessions', ['id' => 'compromised-session']);
    }

    public function test_invalid_expired_and_reused_tokens_are_rejected(): void
    {
        $user = $this->user();

        $this->resetRequest($user, 'invalid-token')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('token');

        $expiredToken = Password::createToken($user);
        DB::table('password_reset_tokens')
            ->where('email', $user->email)
            ->update(['created_at' => now()->subMinutes(61)]);
        $this->resetRequest($user, $expiredToken)->assertUnprocessable();

        $validToken = Password::createToken($user);
        $this->resetRequest($user, $validToken)->assertOk();
        $this->resetRequest($user, $validToken)->assertUnprocessable();
    }

    public function test_reset_validates_confirmation_and_rejects_server_owned_fields(): void
    {
        $this->postJson(self::RESET_ENDPOINT, [
            'email' => 'user@example.test',
            'token' => 'token',
            'password' => 'new-password',
            'password_confirmation' => 'different-password',
            'user_id' => 1,
            'role' => 'Admin',
            'email_verified_at' => now()->toISOString(),
            'remember_token' => 'forged',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'password',
                'user_id',
                'role',
                'email_verified_at',
                'remember_token',
            ]);
    }

    public function test_password_reset_routes_are_public_and_throttled(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        foreach ([
            'api/v1/forgot-password' => 'throttle:5,1,forgot-password',
            'api/v1/reset-password' => 'throttle:10,1,reset-password',
        ] as $uri => $throttle) {
            $route = $routes->first(fn ($route) => $route->uri() === $uri
                && in_array('POST', $route->methods(), true));

            $this->assertNotNull($route);
            $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
            $this->assertContains($throttle, $route->gatherMiddleware());
        }
    }

    private function resetRequest(User $user, string $token)
    {
        return $this->postJson(self::RESET_ENDPOINT, [
            'email' => $user->email,
            'token' => $token,
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function user(array $attributes = []): User
    {
        return User::factory()->create([
            'firstName' => 'Password',
            'lastName' => 'Tester',
            'contact' => '09171234567',
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => 'password-tester',
            'employee_id' => 'PASSWORD-TEST-001',
            ...$attributes,
        ]);
    }
}
