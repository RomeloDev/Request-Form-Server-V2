<?php

namespace Tests\Feature\Admin;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UpdateUserTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        foreach (['Admin', 'User'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_authentication(): void
    {
        $target = $this->user('User');

        $this->postJson(
            "/api/v1/update-profile/{$target->id}",
            $this->payload($target),
        )->assertUnauthorized();
    }

    public function test_non_admin_cannot_update_another_user(): void
    {
        $target = $this->user('User');
        Sanctum::actingAs($this->user('User'));

        $this->postJson(
            "/api/v1/update-profile/{$target->id}",
            $this->payload($target),
        )->assertForbidden();
    }

    public function test_admin_update_stores_the_code_and_derives_the_company(): void
    {
        $target = $this->user('User');
        $branch = $this->branch('CALI', 'SMCT Group of Companies');
        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(
            "/api/v1/update-profile/{$target->id}",
            [
                ...$this->payload($target),
                'branch_code' => $branch->branch_code,
            ],
        )->assertOk();

        $target->refresh();

        $this->assertSame($branch->branch_code, $target->branch_code);
        $this->assertSame($branch->branch, $target->branch);
    }

    public function test_admin_update_rejects_a_branch_id_and_client_company(): void
    {
        $target = $this->user('User');
        $branch = $this->branch('CALI', 'SMCT Group of Companies');
        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(
            "/api/v1/update-profile/{$target->id}",
            [
                ...$this->payload($target),
                'branch_code' => (string) $branch->id,
                'branch' => 'Forged Company',
            ],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'branch_code',
                'branch',
            ]);
    }

    /** @return array<string, mixed> */
    private function payload(User $user): array
    {
        return [
            'firstName' => $user->firstName,
            'lastName' => $user->lastName,
            'email' => $user->email,
            'contact' => $user->contact,
            'userName' => $user->userName,
            'employee_id' => $user->employee_id,
            'position' => $user->position,
            'branch_code' => null,
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

    private function user(string $role): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "User{$number}",
            'lastName' => 'Example',
            'contact' => "0917000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "update-user-{$number}",
            'employee_id' => "UPDATE-EMP-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }
}
