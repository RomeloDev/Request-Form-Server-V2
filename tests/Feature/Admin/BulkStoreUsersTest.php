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

class BulkStoreUsersTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/users/bulk';

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

    public function test_non_admin_cannot_bulk_create_users(): void
    {
        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->postJson(self::ENDPOINT, [])
                ->assertForbidden();
        }

        $this->assertDatabaseCount('users', 2);
    }

    public function test_admin_can_create_multiple_users_in_one_batch(): void
    {
        $branch = $this->branch('BULK-1', 'Bulk Derived Company');
        $position = $this->position('assembler');

        Sanctum::actingAs($this->user('Admin'));

        $response = $this->postJson(self::ENDPOINT, [
            'users' => [
                $this->row($branch, $position, [
                    'firstName' => '  Albert  ',
                    'lastName' => '  Einstein  ',
                    'employee_id' => '  EMP-BULK-1  ',
                    'contact' => ' 09124578965 ',
                    'userName' => 'albert_einstein',
                    'email' => 'albert@example.test',
                ]),
                $this->row($branch, $position, [
                    'firstName' => 'Marie',
                    'lastName' => 'Curie',
                    'employee_id' => 'EMP-BULK-2',
                    'userName' => 'marie_curie',
                    'email' => 'marie@example.test',
                ]),
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('message', 'Users created successfully.')
            ->assertJsonPath('created_count', 2)
            ->assertJsonMissingPath('data')
            ->assertJsonMissingPath('users');

        $users = User::query()
            ->whereIn('email', ['albert@example.test', 'marie@example.test'])
            ->orderBy('email')
            ->get();

        $this->assertCount(2, $users);

        foreach ($users as $user) {
            $this->assertTrue($user->hasRole('User'));
            $this->assertNotNull($user->email_verified_at);
            $this->assertNull($user->signature);
            $this->assertNull($user->profile_picture);
            $this->assertSame($branch->branch_code, $user->branch_code);
            $this->assertSame($branch->branch, $user->branch);
            $this->assertSame($position->value, $user->position);
            $this->assertTrue(Hash::check('password123', $user->password));
        }

        $albert = $users->firstWhere('email', 'albert@example.test');

        $this->assertSame('Albert', $albert->firstName);
        $this->assertSame('Einstein', $albert->lastName);
        $this->assertSame('Albert Einstein', $albert->name);
        $this->assertSame('EMP-BULK-1', $albert->employee_id);
        $this->assertSame('09124578965', $albert->contact);
        $this->assertIsString($albert->employee_id);
        $this->assertIsString($albert->contact);
        $this->assertStringNotContainsString(
            'signature',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'profile-pictures',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            'password123',
            $response->getContent(),
        );
        $this->assertStringNotContainsString(
            '$2y$',
            $response->getContent(),
        );
    }

    public function test_rows_missing_required_fields_are_row_addressable_and_create_nothing(): void
    {
        $branch = $this->branch('BULK-MISSING', 'Missing Company');
        $position = $this->position('missing-check');

        Sanctum::actingAs($this->user('Admin'));

        $valid = $this->row($branch, $position, [
            'employee_id' => 'BULK-MISSING-1',
            'userName' => 'bulk_missing_valid',
            'email' => 'bulk.missing.valid@example.test',
        ]);

        $this->postJson(self::ENDPOINT, [
            'users' => [
                $valid,
                // An empty row must be reported as row 1, not silently filled
                // with placeholder values that could create a ghost account.
                [],
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'users.1.firstName',
                'users.1.lastName',
                'users.1.employee_id',
                'users.1.contact',
                'users.1.userName',
                'users.1.email',
                'users.1.password',
                'users.1.position',
                'users.1.branch_code',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => $valid['email'],
        ]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('positions', 1);
    }

    public function test_one_invalid_row_prevents_every_valid_row_from_being_created(): void
    {
        $branch = $this->branch('BULK-MIXED', 'Mixed Company');
        $position = $this->position('mixed-check');

        Sanctum::actingAs($this->user('Admin'));

        $validA = $this->row($branch, $position, [
            'employee_id' => 'BULK-MIXED-1',
            'userName' => 'bulk_mixed_one',
            'email' => 'bulk.mixed.one@example.test',
        ]);
        $validB = $this->row($branch, $position, [
            'employee_id' => 'BULK-MIXED-2',
            'userName' => 'bulk_mixed_two',
            'email' => 'bulk.mixed.two@example.test',
        ]);
        $invalid = $this->row($branch, $position, [
            'employee_id' => 'BULK-MIXED-3',
            'userName' => 'bulk_mixed_three',
            'email' => 'bulk.mixed.three@example.test',
            'position' => 'no-such-position',
            'branch_code' => 'NO-SUCH-BRANCH',
        ]);

        $this->postJson(self::ENDPOINT, [
            'users' => [$validA, $validB, $invalid],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'users.2.position',
                'users.2.branch_code',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => $validA['email'],
        ]);
        $this->assertDatabaseMissing('users', [
            'email' => $validB['email'],
        ]);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('positions', 1);
    }

    public function test_duplicates_within_payload_are_row_addressable_and_create_nothing(): void
    {
        $branch = $this->branch('BULK-2', 'Duplicate Company');
        $position = $this->position('duplicate-check');

        Sanctum::actingAs($this->user('Admin'));

        $response = $this->postJson(self::ENDPOINT, [
            'users' => [
                $this->row($branch, $position, [
                    'employee_id' => 'BULK-DUP-1',
                    'userName' => 'bulk_duplicate',
                    'email' => 'bulk.duplicate@example.test',
                ]),
                $this->row($branch, $position, [
                    'employee_id' => 'BULK-DUP-1',
                    'userName' => 'bulk_duplicate',
                    'email' => 'BULK.DUPLICATE@EXAMPLE.TEST',
                ]),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'users.1.email',
                'users.1.userName',
                'users.1.employee_id',
            ])
            ->assertJsonMissingValidationErrors([
                'users.0.email',
                'users.0.userName',
                'users.0.employee_id',
            ]);

        $this->assertStringContainsString(
            'appears more than once in this batch',
            $response->getContent(),
        );

        $this->assertDatabaseCount('users', 1);
    }

    public function test_existing_database_conflicts_are_row_addressable(): void
    {
        $branch = $this->branch('BULK-3', 'Existing Company');
        $position = $this->position('existing-check');

        $existing = $this->user('User', [
            'email' => 'Existing@Example.Test',
            'userName' => 'Existing_User',
            'employee_id' => 'EXISTING-BULK-EMP-1',
        ]);

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, [
            'users' => [
                $this->row($branch, $position, [
                    'employee_id' => $existing->employee_id,
                    'userName' => 'existing_user',
                    'email' => 'existing@example.test',
                ]),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'users.0.email',
                'users.0.userName',
                'users.0.employee_id',
            ]);

        $this->assertDatabaseCount('users', 2);
    }

    public function test_invalid_branch_and_position_are_rejected_without_creating_anything(): void
    {
        $branch = $this->branch('BULK-4', 'Valid Company');
        $position = $this->position('valid-position');

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, [
            'users' => [
                $this->row($branch, $position, [
                    'branch_code' => 'MISSING-BULK-BRANCH',
                    'position' => 'missing-bulk-position',
                ]),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'users.0.branch_code',
                'users.0.position',
            ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('branches', 1);
        $this->assertDatabaseCount('positions', 1);
    }

    public function test_forged_and_unknown_fields_inside_rows_are_rejected(): void
    {
        $branch = $this->branch('BULK-5', 'Forgery Company');
        $position = $this->position('forgery-check');

        Sanctum::actingAs($this->user('Admin'));

        $row = $this->row($branch, $position, [
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
            'is_admin' => true,
        ]);

        $this->postJson(self::ENDPOINT, [
            'users' => [$row],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'users.0.id',
                'users.0.user_id',
                'users.0.name',
                'users.0.role',
                'users.0.branch',
                'users.0.email_verified_at',
                'users.0.profile_picture',
                'users.0.profile_picture_path',
                'users.0.signature',
                'users.0.signature_path',
                'users.0.remember_token',
                'users.0.is_admin',
            ]);

        $this->assertDatabaseMissing('users', [
            'email' => $row['email'],
        ]);
    }

    public function test_optional_password_confirmation_must_match_when_present(): void
    {
        $branch = $this->branch('BULK-7', 'Confirmation Company');
        $position = $this->position('confirmation-check');

        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, [
            'users' => [
                $this->row($branch, $position, [
                    'password' => 'password123',
                    'password_confirmation' => 'different-password',
                ]),
            ],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['users.0.password_confirmation']);

        $this->assertDatabaseCount('users', 1);

        // Omitting the confirmation is allowed, and so is sending a matching one.
        $this->postJson(self::ENDPOINT, [
            'users' => [
                $this->row($branch, $position, [
                    'employee_id' => 'BULK-CONFIRM-1',
                    'userName' => 'bulk_confirm_one',
                    'email' => 'bulk.confirm.one@example.test',
                    'password_confirmation' => 'password123',
                ]),
                $this->row($branch, $position, [
                    'employee_id' => 'BULK-CONFIRM-2',
                    'userName' => 'bulk_confirm_two',
                    'email' => 'bulk.confirm.two@example.test',
                ]),
            ],
        ])
            ->assertCreated()
            ->assertJsonPath('created_count', 2);
    }

    public function test_payload_shape_and_size_are_validated(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        $this->postJson(self::ENDPOINT, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['users']);

        $this->postJson(self::ENDPOINT, ['users' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['users']);

        $this->postJson(self::ENDPOINT, [
            'users' => array_fill(0, 501, []),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['users']);

        $this->assertDatabaseCount('users', 1);
    }

    public function test_batch_rolls_back_every_user_when_role_assignment_fails(): void
    {
        $branch = $this->branch('BULK-6', 'Rollback Company');
        $position = $this->position('rollback-check');

        Sanctum::actingAs($this->user('Admin'));

        Role::query()
            ->where('name', 'User')
            ->where('guard_name', 'web')
            ->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->withoutExceptionHandling();

        try {
            $this->postJson(self::ENDPOINT, [
                'users' => [
                    $this->row($branch, $position, [
                        'employee_id' => 'ROLLBACK-EMP-1',
                        'userName' => 'rollback_user_one',
                        'email' => 'rollback-one@example.test',
                    ]),
                    $this->row($branch, $position, [
                        'employee_id' => 'ROLLBACK-EMP-2',
                        'userName' => 'rollback_user_two',
                        'email' => 'rollback-two@example.test',
                    ]),
                ],
            ]);

            $this->fail('Expected role assignment to fail.');
        } catch (RoleDoesNotExist) {
            // Expected: the outer transaction must roll back both rows.
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('model_has_roles', 1);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(
        Branch $branch,
        Position $position,
        array $overrides = [],
    ): array {
        $number = ++$this->sequence;

        return [
            'firstName' => "Bulk{$number}",
            'lastName' => 'Employee',
            'employee_id' => "BULK-EMP-{$number}",
            'contact' => '09171234567',
            'userName' => "bulk_employee_{$number}",
            'email' => "bulk.employee.{$number}@example.test",
            'password' => 'password123',
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
            'firstName' => "BulkUser{$number}",
            'lastName' => 'Tester',
            'contact' => "0917000{$number}",
            'branch_code' => 'SAFE',
            'branch' => 'Safe Test Company',
            'position' => 'Employee',
            'userName' => "bulk-user-{$number}",
            'employee_id' => "BULK-USER-EMP-{$number}",
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
