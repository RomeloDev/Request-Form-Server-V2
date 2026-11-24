<?php

namespace Tests\Feature\Auth;

use App\Models\Branch;
use App\Models\Position;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)
            ->forgetCachedPermissions();

        Role::findOrCreate('User', 'web');
    }

    public function test_empty_registration_requires_a_signature(): void
    {
        $response = $this->postJson('/api/v1/register', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['signature']);
    }

    public function test_registration_stores_the_real_branch_code_and_returns_no_token(): void
    {
        Storage::fake('local');

        $branch = $this->branch();
        $position = Position::query()->create([
            'label' => 'Employee',
            'value' => 'Employee',
        ]);

        $response = $this->post(
            '/api/v1/register',
            $this->validPayload(
                branchCode: $branch->branch_code,
                position: $position->value,
            ),
            ['Accept' => 'application/json'],
        );

        $response
            ->assertOk()
            ->assertJsonPath('status', true)
            ->assertJsonMissingPath('token');

        $user = User::query()
            ->where('userName', 'new_employee')
            ->sole();

        $this->assertSame(
            $branch->branch_code,
            $user->branch_code,
        );
        $this->assertSame($branch->branch, $user->branch);
        $this->assertTrue($user->hasRole('User'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        Storage::disk('local')->assertExists($user->signature);
    }

    public function test_registration_rejects_a_branch_id_and_client_supplied_company(): void
    {
        Storage::fake('local');

        $branch = $this->branch();
        $position = Position::query()->create([
            'label' => 'Employee',
            'value' => 'Employee',
        ]);

        $payload = $this->validPayload(
            branchCode: (string) $branch->id,
            position: $position->value,
        );
        $payload['branch'] = 'Forged Company';

        $this->post(
            '/api/v1/register',
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'branch_code',
                'branch',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertSame(
            [],
            Storage::disk('local')->allFiles(),
        );
    }

    public function test_registration_normalizes_login_fields(): void
    {
        Storage::fake('local');

        $branch = $this->branch();
        $position = Position::query()->create([
            'label' => 'Employee',
            'value' => 'Employee',
        ]);
        $payload = $this->validPayload(
            branchCode: $branch->branch_code,
            position: $position->value,
        );
        $payload['email'] = '  NEW.EMPLOYEE@EXAMPLE.TEST  ';
        $payload['userName'] = '  NEW_EMPLOYEE  ';

        $this->post(
            '/api/v1/register',
            $payload,
            ['Accept' => 'application/json'],
        )->assertOk();

        $this->assertDatabaseHas('users', [
            'email' => 'new.employee@example.test',
            'userName' => 'new_employee',
        ]);
    }

    public function test_registration_rejects_server_owned_account_fields(): void
    {
        Storage::fake('local');

        $branch = $this->branch();
        $position = Position::query()->create([
            'label' => 'Employee',
            'value' => 'Employee',
        ]);
        $payload = [
            ...$this->validPayload(
                branchCode: $branch->branch_code,
                position: $position->value,
            ),
            'id' => 99,
            'name' => 'Forged Name',
            'role' => 'Admin',
            'roles' => ['Admin'],
            'user_id' => 99,
            'email_verified_at' => now()->toISOString(),
            'remember_token' => 'forged-token',
            'profile_picture' => 'profile-pictures/forged.png',
            'created_at' => now()->toISOString(),
            'updated_at' => now()->toISOString(),
        ];

        $this->post(
            '/api/v1/register',
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'id',
                'name',
                'role',
                'roles',
                'user_id',
                'email_verified_at',
                'remember_token',
                'profile_picture',
                'created_at',
                'updated_at',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_registration_rejects_invalid_contact_and_password_types(): void
    {
        Storage::fake('local');

        $branch = $this->branch();
        $position = Position::query()->create([
            'label' => 'Employee',
            'value' => 'Employee',
        ]);
        $payload = $this->validPayload(
            branchCode: $branch->branch_code,
            position: $position->value,
        );
        $payload['contact'] = 'not-a-phone';
        $payload['password'] = array_fill(0, 8, 'password');

        $this->post(
            '/api/v1/register',
            $payload,
            ['Accept' => 'application/json'],
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['contact', 'password']);

        $this->assertDatabaseCount('users', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function branch(): Branch
    {
        return Branch::factory()->create([
            'branch_code' => 'CALI',
            'branch_name' => 'Caloocan Branch',
            'branch' => 'SMCT Group of Companies',
            'acronym' => 'CALI',
        ]);
    }

    /** @return array<string, mixed> */
    private function validPayload(
        string $branchCode,
        string $position,
    ): array {
        return [
            'firstName' => 'New',
            'lastName' => 'Employee',
            'contact' => '09171234567',
            'branch_code' => $branchCode,
            'userName' => 'new_employee',
            'employee_id' => 'EMP-NEW-001',
            'email' => 'new.employee@example.test',
            'password' => 'secure-password',
            'position' => $position,
            'signature' => UploadedFile::fake()->image(
                'signature.png',
                300,
                120,
            ),
        ];
    }
}
