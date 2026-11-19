<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StoreUserTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/users';

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
        $this->postJson(self::ENDPOINT, [])
            ->assertUnauthorized();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_non_admin_cannot_create_a_user(): void
    {
        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->postJson(self::ENDPOINT, [])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('users', 2);
    }

    public function test_client_supplied_role_cannot_create_privileged_accounts(): void
    {
        $branch = $this->branch('CREATE-ROLE', 'Privilege Company');
        $position = $this->position('privilege-position');

        Sanctum::actingAs($this->user('Admin'));

        foreach (['Admin', 'approver'] as $forgedRole) {
            $payload = $this->payload($branch, $position, [
                'role' => $forgedRole,
            ]);

            $this->postJson(self::ENDPOINT, $payload)
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['role']);

            $this->assertDatabaseMissing('users', [
                'email' => $payload['email'],
            ]);
        }

        /*
         * No privileged placeholder account was invented: only the acting
         * Admin exists, and no approver account was created by the request.
         */
        $this->assertSame(1, User::role('Admin')->count());
        $this->assertSame(0, User::role('approver')->count());
    }

    public function test_admin_can_create_a_user_with_server_derived_fields(): void
    {
        $branch = $this->branch('CREATE-1', 'Derived Company');
        $position = $this->position('accounting-clerk');
        $payload = $this->payload($branch, $position);

        Sanctum::actingAs($this->user('Admin'));

        $response = $this->postJson(self::ENDPOINT, $payload)
            ->assertCreated()
            ->assertJsonPath('message', 'User created successfully.')
            ->assertJsonPath('data.firstname', $payload['firstName'])
            ->assertJsonPath('data.lastname', $payload['lastName'])
            ->assertJsonPath('data.username', $payload['userName'])
            ->assertJsonPath('data.email', $payload['email'])
            ->assertJsonPath('data.employee_id', $payload['employee_id'])
            ->assertJsonPath('data.role', 'User')
            ->assertJsonPath('data.position', $position->value)
            ->assertJsonPath('data.branch_code', $branch->branch_code)
            ->assertJsonPath('data.branch', $branch->branch)
            ->assertJsonPath('data.verification_status', 'Verified')
            ->assertJsonPath('data.has_profile_picture', false)
            ->assertJsonPath('data.has_signature', false)
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.profile_picture')
            ->assertJsonMissingPath('data.signature');

        $user = User::query()
            ->where('email', $payload['email'])
            ->sole();

        $this->assertSame(
            $payload['firstName'].' '.$payload['lastName'],
            $user->name,
        );
        $this->assertSame($branch->branch_code, $user->branch_code);
        $this->assertSame($branch->branch, $user->branch);
        $this->assertSame($position->value, $user->position);
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->signature);
        $this->assertNull($user->profile_picture);

        $this->assertStringNotContainsString(
            'profile-pictures',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'signatures',
            $response->getContent(),
        );
    }

    public function test_created_user_receives_only_the_user_role_server_side(): void
    {
        $branch = $this->branch('CREATE-2', 'Role Company');
        $position = $this->position('encoder');
        $payload = $this->payload($branch, $position);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, $payload)
            ->assertCreated();

        $user = User::query()
            ->where('email', $payload['email'])
            ->sole();

        $this->assertTrue($user->hasRole('User'));
        $this->assertFalse($user->hasRole('Admin'));
        $this->assertSame(['User'], $user->getRoleNames()->all());
        $this->assertSame('User', $user->getRoleNames()->first());
    }

    public function test_created_password_is_hashed_once(): void
    {
        $branch = $this->branch('CREATE-3', 'Password Company');
        $position = $this->position('clerk');
        $payload = $this->payload($branch, $position);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, $payload)
            ->assertCreated();

        $user = User::query()
            ->where('email', $payload['email'])
            ->sole();

        $this->assertNotSame($payload['password'], $user->password);
        $this->assertTrue(Hash::check($payload['password'], $user->password));
    }

    public function test_invalid_branch_and_position_are_rejected(): void
    {
        $branch = $this->branch('CREATE-4', 'Valid Company');
        $position = $this->position('valid-position');

        $payload = $this->payload($branch, $position, [
            'branch_code' => 'MISSING-BRANCH',
            'position' => 'missing-position',
        ]);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'branch_code',
                'position',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => $payload['email'],
        ]);
    }

    public function test_duplicate_email_username_and_employee_id_are_rejected(): void
    {
        $branch = $this->branch('CREATE-5', 'Duplicate Company');
        $position = $this->position('duplicate-position');

        $existing = $this->user('User', [
            'email' => 'existing@example.test',
            'userName' => 'existing_user',
            'employee_id' => 'EXISTING-EMP-1',
        ]);

        $payload = $this->payload($branch, $position, [
            'email' => $existing->email,
            'userName' => $existing->userName,
            'employee_id' => $existing->employee_id,
        ]);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'email',
                'userName',
                'employee_id',
            ]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_forged_privileged_and_media_fields_are_rejected(): void
    {
        $branch = $this->branch('CREATE-6', 'Forgery Company');
        $position = $this->position('forgery-position');

        $payload = $this->payload($branch, $position, [
            'id' => 999,
            'user_id' => 999,
            'name' => 'Forged Name',
            'role' => 'Admin',
            'branch' => 'Forged Company',
            'email_verified_at' => now()->toIso8601String(),
            'profile_picture' => 'profile-pictures/forged.jpg',
            'profile_picture_path' => 'profile-pictures/forged.jpg',
            'signature' => 'signatures/forged.png',
            'signature_path' => 'signatures/forged.png',
            'remember_token' => 'forged-token',
        ]);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'user_id',
                'name',
                'role',
                'branch',
                'email_verified_at',
                'profile_picture',
                'profile_picture_path',
                'signature',
                'signature_path',
                'remember_token',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => $payload['email'],
        ]);
    }

    public function test_unknown_fields_are_rejected(): void
    {
        $branch = $this->branch('CREATE-UNKNOWN', 'Unknown Field Company');
        $position = $this->position('unknown-field-position');
        $payload = $this->payload($branch, $position, [
            'unexpected_field' => 'must not be ignored',
        ]);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['unexpected_field']);

        $this->assertDatabaseMissing('users', [
            'email' => $payload['email'],
        ]);
    }

    public function test_contact_username_and_password_rules_are_enforced(): void
    {
        $branch = $this->branch('CREATE-7', 'Validation Company');
        $position = $this->position('validation-position');
        $payload = $this->payload($branch, $position, [
            'contact' => '0917',
            'userName' => 'Not Lowercase',
            'password' => 'short',
            'password_confirmation' => 'different',
        ]);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'contact',
                'userName',
                'password',
            ]);
    }

    public function test_creation_rolls_back_when_role_assignment_fails(): void
    {
        $branch = $this->branch('CREATE-8', 'Rollback Company');
        $position = $this->position('rollback-position');
        $payload = $this->payload($branch, $position);

        Sanctum::actingAs($this->user('Admin'));

        Role::query()
            ->where('name', 'User')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutExceptionHandling();

        try {
            $this->postJson(self::ENDPOINT, $payload);
            $this->fail('Expected role assignment to fail.');
        } catch (RoleDoesNotExist) {
            // Expected: the transaction must roll back the created user.
        }

        $this->assertDatabaseMissing('users', [
            'email' => $payload['email'],
        ]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('model_has_roles', 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(
        Branch $branch,
        Position $position,
        array $overrides = [],
    ): array {
        $number = ++$this->sequence;

        return [
            'firstName' => "New{$number}",
            'lastName' => 'Employee',
            'employee_id' => "NEW-EMP-{$number}",
            'contact' => '09171234567',
            'userName' => "new_employee_{$number}",
            'email' => "new.employee.{$number}@example.test",
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'position' => $position->value,
            'branch_code' => $branch->branch_code,
            ...$overrides,
        ];
    }

    private function branch(string $code, string $company): Branch
    {
        return Branch::factory()->create([
            'branch_code' => $code,
            'branch_name' => "{$code} Test Branch",
            'branch' => $company,
            'acronym' => $code,
        ]);
    }

    private function position(string $value): Position
    {
        return Position::query()->create([
            'label' => ucfirst($value),
            'value' => $value,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role, array $attributes = []): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "StoreUser{$number}",
            'lastName' => 'Tester',
            'contact' => "0917000{$number}",
            'branch_code' => 'SAFE',
            'branch' => 'Safe Test Company',
            'position' => 'Employee',
            'userName' => "store-user-{$number}",
            'employee_id' => "STORE-EMP-{$number}",
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
