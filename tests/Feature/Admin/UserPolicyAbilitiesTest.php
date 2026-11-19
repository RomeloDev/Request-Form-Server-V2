<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class UserPolicyAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_admin_can_use_admin_user_abilities(): void
    {
        $admin = $this->user('Admin');
        $target = $this->user('User');

        $this->assertTrue($admin->can('create', User::class));
        $this->assertTrue($admin->can('bulkCreate', User::class));
        $this->assertTrue($admin->can('verify', $target));
        $this->assertTrue($admin->can('viewProfilePicture', $target));
        $this->assertTrue($admin->can('viewSignature', $target));
    }

    public function test_non_admin_cannot_use_admin_user_abilities(): void
    {
        $member = $this->user('User');
        $target = $this->user('User');

        $this->assertFalse($member->can('create', User::class));
        $this->assertFalse($member->can('bulkCreate', User::class));
        $this->assertFalse($member->can('verify', $target));
        $this->assertFalse($member->can('viewProfilePicture', $target));
        $this->assertFalse($member->can('viewSignature', $target));
    }

    private function user(string $role): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Policy{$number}",
            'lastName' => 'Tester',
            'contact' => "0917000{$number}",
            'branch_code' => 'SAFE',
            'branch' => 'Safe Test Company',
            'position' => 'Employee',
            'userName' => "policy-{$number}",
            'employee_id' => "POLICY-{$number}",
        ]);

        $user->assignRole($role);

        return $user;
    }
}
