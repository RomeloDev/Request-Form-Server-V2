<?php

namespace Tests\Feature\RequestForms;

use App\Models\Bank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListBanksTest extends TestCase
{
    use RefreshDatabase;

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
        $this->getJson('/api/v1/banks')
            ->assertUnauthorized();
    }

    public function test_account_without_request_creation_permission_is_forbidden(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/banks')
            ->assertForbidden();
    }

    public function test_authorized_requester_can_search_sorted_paginated_safe_banks(): void
    {
        Bank::query()->create([
            'name' => 'Zeta Savings',
        ]);

        Bank::query()->create([
            'name' => 'Alpha Bank',
        ]);

        Bank::query()->create([
            'name' => 'Beta Bank',
        ]);

        Sanctum::actingAs($this->user('User'));

        $response = $this->getJson(
            '/api/v1/banks?search=bank&page=1&per_page=1'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Banks retrieved successfully.',
            )
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Alpha Bank')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.created_at')
            ->assertJsonMissingPath('data.0.updated_at');
    }

    public function test_invalid_and_identity_query_parameters_are_rejected(): void
    {
        Sanctum::actingAs($this->user('User'));

        $this->getJson(
            '/api/v1/banks?search[]=bank&page=0&per_page=51&id=1&bank_id=1&user_id=1&requester_id=1&branch_id=1&role=Admin&form_type=check'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'search',
                'page',
                'per_page',
                'id',
                'bank_id',
                'user_id',
                'requester_id',
                'branch_id',
                'role',
                'form_type',
            ]);
    }

    private function user(?string $role = null): User
    {
        $user = User::factory()->create([
            'firstName' => 'Bank',
            'lastName' => 'Requester',
            'contact' => '09181234567',
            'position' => 'Employee',
            'userName' => fake()->unique()->userName(),
            'employee_id' => fake()->unique()->numerify('BANK-#####'),
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
