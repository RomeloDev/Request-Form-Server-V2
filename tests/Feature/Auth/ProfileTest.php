<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/profile';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_authentication(): void
    {
        $this->getJson(self::ENDPOINT)
            ->assertUnauthorized();
    }

    public function test_every_application_role_can_read_its_own_profile(): void
    {
        foreach (['Admin', 'User', 'approver'] as $role) {
            $user = $this->user($role);
            Sanctum::actingAs($user);

            $this->getJson(self::ENDPOINT)
                ->assertOk()
                ->assertJsonPath('status', true)
                ->assertJsonPath('data.id', $user->id)
                ->assertJsonPath('data.role', $role);
        }
    }

    public function test_profile_policy_allows_only_the_same_user(): void
    {
        $user = $this->user('User');
        $otherUser = $this->user('User');

        $this->assertTrue(
            Gate::forUser($user)->allows('viewProfile', $user),
        );
        $this->assertFalse(
            Gate::forUser($user)->allows('viewProfile', $otherUser),
        );
    }

    public function test_client_cannot_select_another_users_profile(): void
    {
        $user = $this->user('User');
        $otherUser = $this->user('User');
        Sanctum::actingAs($user);

        $this->getJson(self::ENDPOINT.'?'.http_build_query([
            'user_id' => $otherUser->id,
            'role' => 'Admin',
        ]))
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.role', 'User');
    }

    public function test_response_uses_the_safe_profile_contract(): void
    {
        $branch = Branch::factory()->create([
            'branch_code' => 'SAFE',
            'branch_name' => 'Safe Test Branch',
            'branch' => 'Safe Test Company',
            'acronym' => 'STB',
        ]);
        Position::query()->create([
            'value' => 'accounting-clerk',
            'label' => 'Accounting Clerk',
        ]);
        $user = $this->user('User', [
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'position' => 'accounting-clerk',
            'profile_picture' => 'profile-pictures/private-profile.jpg',
            'signature' => 'signatures/private-signature.png',
        ]);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::ENDPOINT);

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.firstName', $user->firstName)
            ->assertJsonPath('data.lastName', $user->lastName)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.contact', $user->contact)
            ->assertJsonPath('data.userName', $user->userName)
            ->assertJsonPath('data.employee_id', $user->employee_id)
            ->assertJsonPath('data.role', 'User')
            ->assertJsonPath('data.position', 'accounting-clerk')
            ->assertJsonPath('data.position_label', 'Accounting Clerk')
            ->assertJsonPath('data.branch_code', $branch->branch_code)
            ->assertJsonPath('data.branch', $branch->branch)
            ->assertJsonPath('data.branch_name', $branch->branch_name)
            ->assertJsonPath('data.has_profile_picture', true)
            ->assertJsonPath('data.has_signature', true)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token')
            ->assertJsonMissingPath('data.profile_picture')
            ->assertJsonMissingPath('data.signature')
            ->assertJsonMissingPath('data.roles')
            ->assertJsonMissingPath('data.position_record')
            ->assertJsonMissingPath('data.assigned_branch');

        $this->assertSame([
            'id',
            'name',
            'firstName',
            'lastName',
            'email',
            'contact',
            'userName',
            'employee_id',
            'role',
            'position',
            'position_label',
            'branch_code',
            'branch',
            'branch_name',
            'has_profile_picture',
            'has_signature',
        ], array_keys($response->json('data')));
    }

    public function test_missing_lookup_records_use_safe_fallback_values(): void
    {
        $user = $this->user('User', [
            'branch_code' => null,
            'branch' => null,
            'position' => 'Legacy Position',
            'profile_picture' => null,
            'signature' => null,
        ]);
        Sanctum::actingAs($user);

        $this->getJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.position', 'Legacy Position')
            ->assertJsonPath('data.position_label', 'Legacy Position')
            ->assertJsonPath('data.branch_name', null)
            ->assertJsonPath('data.has_profile_picture', false)
            ->assertJsonPath('data.has_signature', false);
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role, array $attributes = []): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Profile{$number}",
            'lastName' => 'Tester',
            'contact' => "0918000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "profile-user-{$number}",
            'employee_id' => "PROFILE-EMP-{$number}",
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
