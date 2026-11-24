<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class LoginSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/login';

    private const PASSWORD = 'secure-password';

    private const SPA_HOST = 'localhost:3000';

    private const SPA_ORIGIN = 'http://localhost:3000';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', array_values(array_unique([
            ...config('sanctum.stateful', []),
            self::SPA_HOST,
        ])));

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Role::findOrCreate('User', 'web');

        $this->withHeaders([
            'Origin' => self::SPA_ORIGIN,
            'Referer' => self::SPA_ORIGIN.'/login',
        ]);
    }

    public function test_unknown_and_known_accounts_use_the_same_credential_failure(): void
    {
        $user = $this->user();
        $expected = [
            'status' => false,
            'message' => 'The provided credentials are incorrect.',
        ];

        $this->postJson(self::ENDPOINT, [
            'email' => 'missing@example.test',
            'password' => 'incorrect-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson($expected);

        $this->postJson(self::ENDPOINT, [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
            ->assertUnauthorized()
            ->assertExactJson($expected);
    }

    public function test_unverified_state_is_disclosed_only_after_valid_credentials(): void
    {
        $user = $this->user(['email_verified_at' => null]);

        $this->postJson(self::ENDPOINT, [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])
            ->assertUnauthorized()
            ->assertJsonPath(
                'message',
                'The provided credentials are incorrect.',
            );

        $this->postJson(self::ENDPOINT, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Your email has not been verified yet. Please contact the administrator.',
            );

        $this->assertFalse(Auth::guard('web')->check());
    }

    #[DataProvider('loginIdentifiers')]
    public function test_verified_users_can_login_with_each_supported_identifier(
        string $field,
    ): void {
        $user = $this->user();

        $this->postJson(self::ENDPOINT, [
            'email' => $user->{$field},
            'password' => self::PASSWORD,
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('role', 'User');

        $this->assertTrue(Auth::guard('web')->user()->is($user));
    }

    public function test_login_limiter_counts_failures_and_blocks_the_sixth_attempt(): void
    {
        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->postJson(self::ENDPOINT, [
                'email' => 'limited@example.test',
                'password' => 'incorrect-password',
            ])->assertUnauthorized();
        }

        $this->postJson(self::ENDPOINT, [
            'email' => 'limited@example.test',
            'password' => 'incorrect-password',
        ])->assertTooManyRequests();
    }

    public function test_successful_login_clears_prior_failed_attempts(): void
    {
        $ip = '203.0.113.10';
        $user = $this->user();
        $this->withServerVariables(['REMOTE_ADDR' => $ip]);
        $key = 'login:'.hash('sha256', implode('|', [
            Str::lower($user->email),
            $ip,
        ]));

        $this->postJson(self::ENDPOINT, [
            'email' => $user->email,
            'password' => 'incorrect-password',
        ])->assertUnauthorized();

        $this->assertSame(1, RateLimiter::attempts($key));

        $this->postJson(self::ENDPOINT, [
            'email' => $user->email,
            'password' => self::PASSWORD,
        ])->assertOk();

        $this->assertSame(0, RateLimiter::attempts($key));
    }

    public function test_login_rejects_oversized_and_server_owned_fields(): void
    {
        $this->postJson(self::ENDPOINT, [
            'email' => str_repeat('a', 256),
            'password' => str_repeat('p', 256),
            'user_id' => 1,
            'role' => 'Admin',
            'email_verified_at' => now()->toISOString(),
            'remember_token' => 'forged-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'password',
                'user_id',
                'role',
                'email_verified_at',
                'remember_token',
            ]);
    }

    public function test_login_route_is_public_and_throttled(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/login'
                && in_array('POST', $route->methods(), true));

        $this->assertNotNull($route);
        $this->assertNotContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('throttle:10,1', $route->gatherMiddleware());
    }

    /** @return array<string, array{string}> */
    public static function loginIdentifiers(): array
    {
        return [
            'email' => ['email'],
            'username' => ['userName'],
            'employee id' => ['employee_id'],
        ];
    }

    /** @param array<string, mixed> $attributes */
    private function user(array $attributes = []): User
    {
        $user = User::factory()->create([
            'firstName' => 'Login',
            'lastName' => 'Tester',
            'contact' => '09171234567',
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'email' => 'login-user@example.test',
            'password' => self::PASSWORD,
            'email_verified_at' => now(),
            'userName' => 'login-user',
            'employee_id' => 'LOGIN-EMP-001',
            ...$attributes,
        ]);

        $user->assignRole('User');

        return $user;
    }
}
