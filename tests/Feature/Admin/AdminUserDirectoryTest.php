<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminUserDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private const DIRECTORY_ENDPOINT = '/api/v1/admin/users';

    private const LEGACY_DIRECTORY_ENDPOINT = '/api/v1/view-users';

    private const VERIFY_ENDPOINT = '/api/v1/admin/users/%d/verify';

    private const LEGACY_VERIFY_ENDPOINT = '/api/v1/verified-user/%d';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_directory_requires_authentication(): void
    {
        foreach ([self::DIRECTORY_ENDPOINT, self::LEGACY_DIRECTORY_ENDPOINT] as $endpoint) {
            $this->getJson($endpoint)->assertUnauthorized();
        }
    }

    public function test_non_admin_cannot_list_users(): void
    {
        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($role));

            foreach ([self::DIRECTORY_ENDPOINT, self::LEGACY_DIRECTORY_ENDPOINT] as $endpoint) {
                $this->getJson($endpoint)->assertForbidden();
            }
        }
    }

    public function test_admin_can_list_users_using_the_safe_contract(): void
    {
        $admin = $this->user('Admin');
        $target = $this->user('User', [
            'email_verified_at' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::DIRECTORY_ENDPOINT)
            ->assertOk()
            ->assertJsonPath('message', 'Users retrieved successfully');

        $users = collect($response->json('data'));
        $entry = $users->firstWhere('id', $target->id);

        $this->assertNotNull($entry);
        $this->assertSame([
            'id',
            'firstname',
            'lastname',
            'username',
            'email',
            'employee_id',
            'role',
            'position',
            'contact',
            'branch_code',
            'branch',
            'email_verified_at',
            'verification_status',
            'has_profile_picture',
            'has_signature',
        ], array_keys($entry));

        $this->assertSame($target->firstName, $entry['firstname']);
        $this->assertSame($target->lastName, $entry['lastname']);
        $this->assertSame($target->userName, $entry['username']);
        $this->assertSame('User', $entry['role']);
        $this->assertSame('Not Verified', $entry['verification_status']);

        // Admin accounts are managed outside this directory.
        $this->assertNull($users->firstWhere('id', $admin->id));
    }

    public function test_directory_reports_media_flags_without_exposing_storage_paths(): void
    {
        $admin = $this->user('Admin');
        $withMedia = $this->user('User', [
            'profile_picture' => 'profile-pictures/secret-profile.jpg',
            'signature' => 'signatures/secret-signature.png',
        ]);
        $withoutMedia = $this->user('User', [
            'profile_picture' => null,
            'signature' => null,
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson(self::DIRECTORY_ENDPOINT)->assertOk();
        $users = collect($response->json('data'));

        $withMediaEntry = $users->firstWhere('id', $withMedia->id);
        $withoutMediaEntry = $users->firstWhere('id', $withoutMedia->id);

        $this->assertTrue($withMediaEntry['has_profile_picture']);
        $this->assertTrue($withMediaEntry['has_signature']);
        $this->assertFalse($withoutMediaEntry['has_profile_picture']);
        $this->assertFalse($withoutMediaEntry['has_signature']);

        $index = $users->search(
            fn (array $user): bool => $user['id'] === $withMedia->id
        );

        $this->assertIsInt($index);

        $response
            ->assertJsonMissingPath("data.{$index}.profile_picture")
            ->assertJsonMissingPath("data.{$index}.signature")
            ->assertJsonMissingPath("data.{$index}.password")
            ->assertJsonMissingPath("data.{$index}.remember_token");

        $this->assertStringNotContainsString('secret-profile.jpg', $response->getContent());
        $this->assertStringNotContainsString('secret-signature.png', $response->getContent());
    }

    public function test_verify_requires_authentication(): void
    {
        $target = $this->user('User', [
            'email_verified_at' => null,
        ]);

        foreach ([self::VERIFY_ENDPOINT, self::LEGACY_VERIFY_ENDPOINT] as $endpoint) {
            $this->postJson(sprintf($endpoint, $target->id))
                ->assertUnauthorized();
        }

        $this->assertNull($target->fresh()->email_verified_at);
    }

    public function test_non_admin_cannot_verify_a_user(): void
    {
        $target = $this->user('User', [
            'email_verified_at' => null,
        ]);

        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($role));

            foreach ([self::VERIFY_ENDPOINT, self::LEGACY_VERIFY_ENDPOINT] as $endpoint) {
                $this->postJson(sprintf($endpoint, $target->id))
                    ->assertForbidden();
            }
        }

        $this->assertNull($target->fresh()->email_verified_at);
    }

    public function test_non_admin_cannot_probe_missing_user_ids_through_verification(): void
    {
        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($role));

            foreach ([self::VERIFY_ENDPOINT, self::LEGACY_VERIFY_ENDPOINT] as $endpoint) {
                $this->postJson(sprintf($endpoint, 999999))
                    ->assertForbidden();
            }
        }
    }

    public function test_admin_can_verify_a_user(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        foreach ([self::VERIFY_ENDPOINT, self::LEGACY_VERIFY_ENDPOINT] as $endpoint) {
            $target = $this->user('User', [
                'email_verified_at' => null,
            ]);

            $this->postJson(sprintf($endpoint, $target->id))
                ->assertOk()
                ->assertJsonPath(
                    'message',
                    "User {$target->firstName} verified successfully.",
                );

            $this->assertNotNull($target->fresh()->email_verified_at);
        }
    }

    public function test_verify_preserves_not_found_and_already_verified_responses(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        $missingId = 999999;

        foreach ([self::VERIFY_ENDPOINT, self::LEGACY_VERIFY_ENDPOINT] as $endpoint) {
            $this->postJson(sprintf($endpoint, $missingId))
                ->assertNotFound()
                ->assertJsonPath('message', 'User not found or already deleted.');
        }

        $verified = $this->user('User');

        foreach ([self::VERIFY_ENDPOINT, self::LEGACY_VERIFY_ENDPOINT] as $endpoint) {
            $this->postJson(sprintf($endpoint, $verified->id))
                ->assertStatus(409)
                ->assertJsonPath('message', 'User is already verified.');
        }
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role, array $attributes = []): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "AdminDirectory{$number}",
            'lastName' => 'Tester',
            'contact' => "0917000{$number}",
            'branch_code' => 'SAFE',
            'branch' => 'Safe Test Company',
            'position' => 'Employee',
            'userName' => "admin-directory-{$number}",
            'employee_id' => "ADMIN-DIR-{$number}",
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
