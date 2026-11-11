<?php

namespace Tests\Feature\RequestForms;

use App\Models\Supplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ListSuppliersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_route_requires_authentication(): void
    {
        $this->getJson('/api/v1/suppliers')
            ->assertUnauthorized();
    }

    public function test_account_without_request_creation_permission_is_forbidden(): void
    {
        Sanctum::actingAs($this->user());

        $this->getJson('/api/v1/suppliers')
            ->assertForbidden();
    }

    public function test_authorized_requester_can_search_sorted_paginated_safe_suppliers(): void
    {
        Supplier::query()->create([
            'name' => 'Zeta Motors',
            'address' => 'Zeta Address',
        ]);

        Supplier::query()->create([
            'name' => 'Alpha Supplies',
            'address' => 'Alpha Address',
        ]);

        Supplier::query()->create([
            'name' => 'Beta Motors',
            'address' => 'Beta Address',
        ]);

        $requester = $this->user('User');

        Sanctum::actingAs($requester);

        $response = $this->getJson(
            '/api/v1/suppliers?search=motors&page=1&per_page=1'
        );

        $response
            ->assertOk()
            ->assertJsonPath(
                'message',
                'Suppliers retrieved successfully.',
            )
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Beta Motors')
            ->assertJsonPath('data.0.address', 'Beta Address')
            ->assertJsonMissingPath('data.0.id')
            ->assertJsonMissingPath('data.0.created_at')
            ->assertJsonMissingPath('data.0.updated_at');
    }

    public function test_invalid_and_identity_query_parameters_are_rejected(): void
    {
        Sanctum::actingAs($this->user('User'));

        $this->getJson(
            '/api/v1/suppliers?search[]=motors&page=0&per_page=51&user_id=1&requester_id=1&branch_id=1&role=Admin&form_type=stock'
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'search',
                'page',
                'per_page',
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
            'firstName' => 'Supplier',
            'lastName' => 'Requester',
            'contact' => '09181234567',
            'position' => 'Employee',
            'userName' => fake()->unique()->userName(),
            'employee_id' => fake()->unique()->numerify('SUPPLIER-#####'),
        ]);

        if ($role !== null) {
            $user->assignRole($role);
        }

        return $user;
    }
}
