<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ChangeProfilePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const CURRENT_PASSWORD = 'current-password';

    private const ENDPOINT = '/api/v1/profile/password';

    private const SPA_HOST = 'localhost:3000';

    private const SPA_ORIGIN = 'http://localhost:3000';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sanctum.stateful', array_values(array_unique([
            ...config('sanctum.stateful', []),
            self::SPA_HOST,
        ])));

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_authentication(): void
    {
        $this->putJson(self::ENDPOINT, $this->payload())
            ->assertUnauthorized();
    }

    #[DataProvider('applicationRoles')]
    public function test_every_application_role_can_change_its_own_password(
        string $role,
    ): void {
        $user = $this->user($role);
        $newPassword = "new-{$role}-password";
        $this->actingAs($user, 'web');

        $response = $this->putJson(
            self::ENDPOINT,
            $this->payload($newPassword),
        );

        $response
            ->assertOk()
            ->assertExactJson([
                'message' => 'Password changed successfully.',
            ])
            ->assertJsonMissingPath('password')
            ->assertJsonMissingPath('password_hash');

        $storedPassword = $user->fresh()->password;

        $this->assertNotSame($newPassword, $storedPassword);
        $this->assertTrue(
            Hash::check($newPassword, $storedPassword),
            "The {$role} password was not stored with the expected hash.",
        );
    }

    public function test_password_policy_allows_only_the_same_user(): void
    {
        $user = $this->user('User');
        $otherUser = $this->user('User');

        $this->assertTrue(
            Gate::forUser($user)->allows('changePassword', $user),
        );
        $this->assertFalse(
            Gate::forUser($user)->allows('changePassword', $otherUser),
        );
    }

    public function test_incorrect_current_password_is_rejected_without_changing_hash(): void
    {
        $user = $this->user('User');
        $oldHash = $user->password;
        $this->actingAs($user, 'web');

        $this->putJson(self::ENDPOINT, [
            ...$this->payload(),
            'current_password' => 'incorrect-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $this->assertSame($oldHash, $user->fresh()->password);
    }

    public function test_current_password_is_required(): void
    {
        $user = $this->user('User');
        $this->actingAs($user, 'web');
        $payload = $this->payload();
        unset($payload['current_password']);

        $this->putJson(self::ENDPOINT, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');
    }

    public function test_new_password_must_be_at_least_eight_characters(): void
    {
        $user = $this->user('User');
        $this->actingAs($user, 'web');

        $this->putJson(self::ENDPOINT, $this->payload('short'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_password');
    }

    public function test_new_password_requires_matching_confirmation(): void
    {
        $user = $this->user('User');
        $this->actingAs($user, 'web');

        $this->putJson(self::ENDPOINT, [
            ...$this->payload(),
            'new_password_confirmation' => 'different-password',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_password');
    }

    public function test_new_password_must_differ_from_current_password(): void
    {
        $user = $this->user('User');
        $this->actingAs($user, 'web');

        $this->putJson(
            self::ENDPOINT,
            $this->payload(self::CURRENT_PASSWORD),
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('new_password');
    }

    public function test_server_owned_and_alternative_password_fields_are_rejected(): void
    {
        $user = $this->user('User');
        $otherUser = $this->user('User');
        $oldHash = $user->password;
        $this->actingAs($user, 'web');

        $this->putJson(self::ENDPOINT, [
            ...$this->payload(),
            'password' => 'forged-password',
            'id' => $otherUser->id,
            'user_id' => $otherUser->id,
            'role' => 'Admin',
            'email' => 'forged@example.test',
            'email_verified_at' => now()->toISOString(),
            'remember_token' => 'forged-token',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'password',
                'id',
                'user_id',
                'role',
                'email',
                'email_verified_at',
                'remember_token',
            ]);

        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertTrue(Hash::check(self::CURRENT_PASSWORD, $oldHash));
    }

    public function test_successful_change_regenerates_session_and_keeps_user_authenticated(): void
    {
        $user = $this->user('User');
        $this->actingAs($user, 'web')->withSession([
            'profile-password-marker' => 'preserved',
        ]);
        $oldSessionId = session()->getId();

        /*
         * Sanctum only attaches the session middleware for stateful
         * domains. The test registers its simulated SPA host in setUp so the
         * result does not depend on a developer's local environment file.
         */
        $this->withHeaders([
            'Origin' => self::SPA_ORIGIN,
            'Referer' => self::SPA_ORIGIN.'/profile',
        ])->putJson(self::ENDPOINT, $this->payload())
            ->assertOk();

        $this->assertNotSame($oldSessionId, session()->getId());
        $this->assertTrue(Auth::guard('web')->check());
        $this->assertTrue(Auth::guard('web')->user()->is($user));
        $this->assertSame(
            'preserved',
            session()->get('profile-password-marker'),
        );
    }

    public function test_old_credentials_fail_and_new_credentials_succeed(): void
    {
        $user = $this->user('User');
        $newPassword = 'replacement-password';
        $this->actingAs($user, 'web');

        $this->putJson(
            self::ENDPOINT,
            $this->payload($newPassword),
        )->assertOk();

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        /*
         * LoginController regenerates a session, which only exists when the
         * request comes from a Sanctum stateful domain. setUp explicitly
         * registers this simulated SPA host for every test environment.
         */
        $this->withHeaders([
            'Origin' => self::SPA_ORIGIN,
            'Referer' => self::SPA_ORIGIN.'/login',
        ]);

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => self::CURRENT_PASSWORD,
        ])->assertUnauthorized();

        $this->postJson('/api/v1/login', [
            'email' => $user->email,
            'password' => $newPassword,
        ])
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('role', 'User');
    }

    public function test_successful_change_revokes_personal_access_tokens(): void
    {
        $user = $this->user('User');
        $token = $user->createToken('compromised-device');
        $this->actingAs($user, 'web');

        $this->assertDatabaseHas('personal_access_tokens', [
            'id' => $token->accessToken->id,
            'tokenable_id' => $user->id,
        ]);

        $this->putJson(self::ENDPOINT, $this->payload())
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $token->accessToken->id,
        ]);
    }

    public function test_route_uses_the_dedicated_controller_and_strict_throttle(): void
    {
        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/profile/password'
                && in_array('PUT', $route->methods(), true));

        $this->assertNotNull($route);
        $this->assertSame(
            'App\\Http\\Controllers\\API\\V1\\Auth\\ProfilePasswordController@update',
            $route->getActionName(),
        );
        $this->assertContains('auth:sanctum', $route->gatherMiddleware());
        $this->assertContains('throttle:30,1', $route->gatherMiddleware());
    }

    /** @return array<string, string> */
    private function payload(string $newPassword = 'new-secure-password'): array
    {
        return [
            'current_password' => self::CURRENT_PASSWORD,
            'new_password' => $newPassword,
            'new_password_confirmation' => $newPassword,
        ];
    }

    private function user(string $role): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'password' => self::CURRENT_PASSWORD,
            'firstName' => "Password{$number}",
            'lastName' => 'Tester',
            'contact' => "0916000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "password-user-{$number}",
            'employee_id' => "PASSWORD-EMP-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array{string}> */
    public static function applicationRoles(): array
    {
        return [
            'Admin' => ['Admin'],
            'User' => ['User'],
            'approver' => ['approver'],
        ];
    }
}
