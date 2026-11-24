<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfilePictureTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/profile/picture';

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (['Admin', 'User', 'approver'] as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    public function test_picture_routes_require_authentication(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()->image('profile.png'),
        ])->assertUnauthorized();

        $this->deleteJson(self::ENDPOINT)->assertUnauthorized();
    }

    public function test_every_application_role_can_upload_its_own_picture(): void
    {
        foreach (['Admin', 'User', 'approver'] as $role) {
            $user = $this->user($role);
            Sanctum::actingAs($user);

            $response = $this->postJson(self::ENDPOINT, [
                'profile_picture' => UploadedFile::fake()->image(
                    "{$role}-original-name.png",
                    200,
                    200,
                ),
            ]);

            $response
                ->assertOk()
                ->assertExactJson([
                    'message' => 'Profile picture updated successfully.',
                    'data' => [
                        'has_profile_picture' => true,
                    ],
                ]);

            $path = $user->fresh()->profile_picture;

            $this->assertIsString($path);
            $this->assertStringStartsWith('profile-pictures/', $path);
            $this->assertStringNotContainsString('original-name', $path);
            Storage::disk('local')->assertExists($path);
            $response->assertJsonMissingPath('data.profile_picture');
        }
    }

    public function test_manage_picture_policy_allows_only_the_same_user(): void
    {
        $user = $this->user('User');
        $otherUser = $this->user('User');

        $this->assertTrue(
            Gate::forUser($user)->allows('manageProfilePicture', $user),
        );
        $this->assertFalse(
            Gate::forUser($user)->allows('manageProfilePicture', $otherUser),
        );
    }

    public function test_upload_rejects_server_owned_identity_and_path_fields(): void
    {
        $user = $this->user('User');
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()->image('profile.png'),
            'user_id' => $this->user('User')->id,
            'role' => 'Admin',
            'profile_picture_path' => 'profile-pictures/forged.png',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'role',
                'profile_picture_path',
            ]);

        $this->assertNull($user->fresh()->profile_picture);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_validates_presence_type_mime_size_and_dimensions(): void
    {
        Sanctum::actingAs($this->user('User'));

        $this->postJson(self::ENDPOINT)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()->create(
                'not-an-image.txt',
                10,
                'text/plain',
            ),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()->image('profile.gif'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()
                ->image('too-large.png')
                ->size(2049),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()->image(
                'too-wide.png',
                4097,
                100,
            ),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_failed_validation_keeps_the_existing_picture(): void
    {
        Storage::disk('local')->put(
            'profile-pictures/existing.png',
            'existing picture',
        );
        $user = $this->user('User', [
            'profile_picture' => 'profile-pictures/existing.png',
        ]);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()->create(
                'invalid.txt',
                10,
                'text/plain',
            ),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile_picture');

        $this->assertSame(
            'profile-pictures/existing.png',
            $user->fresh()->profile_picture,
        );
        Storage::disk('local')->assertExists(
            'profile-pictures/existing.png',
        );
    }

    public function test_upload_replaces_and_deletes_the_previous_picture(): void
    {
        Storage::disk('local')->put(
            'profile-pictures/previous.png',
            'previous picture',
        );
        $user = $this->user('User', [
            'profile_picture' => 'profile-pictures/previous.png',
        ]);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, [
            'profile_picture' => UploadedFile::fake()->image('replacement.jpg'),
        ])->assertOk();

        $newPath = $user->fresh()->profile_picture;

        $this->assertNotSame('profile-pictures/previous.png', $newPath);
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('local')->assertMissing(
            'profile-pictures/previous.png',
        );
    }

    public function test_picture_is_streamed_privately_for_only_the_authenticated_user(): void
    {
        $user = $this->user('User');
        $otherUser = $this->user('User');
        $ownPicture = UploadedFile::fake()->image('own.png', 120, 120);
        $otherPicture = UploadedFile::fake()->image('other.png', 240, 240);
        $ownPath = $ownPicture->store('profile-pictures', 'local');
        $otherPath = $otherPicture->store('profile-pictures', 'local');
        $user->forceFill(['profile_picture' => $ownPath])->save();
        $otherUser->forceFill(['profile_picture' => $otherPath])->save();
        Sanctum::actingAs($user);

        $response = $this->get(self::ENDPOINT.'?'.http_build_query([
            'user_id' => $otherUser->id,
            'profile_picture_path' => $otherPath,
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = $response->headers->get('Cache-Control', '');
        $disposition = $response->headers->get('Content-Disposition', '');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringNotContainsString($ownPath, $disposition);
        $this->assertStringNotContainsString($otherPath, $disposition);
        $streamedContent = $response->streamedContent();

        $this->assertSame($ownPicture->getContent(), $streamedContent);
        $this->assertNotSame(
            $otherPicture->getContent(),
            $streamedContent,
        );
    }

    public function test_picture_returns_not_found_when_path_is_empty_missing_or_unsupported(): void
    {
        $user = $this->user('User');
        Sanctum::actingAs($user);

        $this->get(self::ENDPOINT)->assertNotFound();

        $user->forceFill([
            'profile_picture' => 'profile-pictures/missing.png',
        ])->save();
        $this->get(self::ENDPOINT)->assertNotFound();

        Storage::disk('local')->put(
            'profile-pictures/unsupported.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>',
        );
        $user->forceFill([
            'profile_picture' => 'profile-pictures/unsupported.svg',
        ])->save();
        $this->get(self::ENDPOINT)->assertNotFound();
    }

    public function test_picture_reader_rejects_an_image_outside_allowlisted_directories(): void
    {
        $file = UploadedFile::fake()->image('private.png');
        Storage::disk('local')->put(
            'signatures/private.png',
            $file->getContent(),
        );
        $user = $this->user('User', [
            'profile_picture' => 'signatures/private.png',
        ]);
        Sanctum::actingAs($user);

        $this->getJson(self::ENDPOINT)->assertNotFound();
    }

    public function test_picture_reader_supports_a_valid_legacy_public_picture(): void
    {
        $file = UploadedFile::fake()->image('legacy.jpg');
        $path = 'request_form_profile_pictures/legacy.jpg';
        Storage::disk('public')->put($path, $file->getContent());
        $user = $this->user('User', ['profile_picture' => $path]);
        Sanctum::actingAs($user);

        $response = $this->get(self::ENDPOINT);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');
        $this->assertSame($file->getContent(), $response->streamedContent());
    }

    public function test_delete_removes_only_the_authenticated_users_picture(): void
    {
        Storage::disk('local')->put('profile-pictures/own.png', 'own picture');
        Storage::disk('local')->put('profile-pictures/other.png', 'other picture');
        $user = $this->user('User', [
            'profile_picture' => 'profile-pictures/own.png',
        ]);
        $otherUser = $this->user('User', [
            'profile_picture' => 'profile-pictures/other.png',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::ENDPOINT.'?'.http_build_query([
            'user_id' => $otherUser->id,
            'profile_picture_path' => 'profile-pictures/other.png',
        ]))
            ->assertOk()
            ->assertExactJson([
                'message' => 'Profile picture removed successfully.',
                'data' => [
                    'has_profile_picture' => false,
                ],
            ]);

        $this->assertNull($user->fresh()->profile_picture);
        $this->assertSame(
            'profile-pictures/other.png',
            $otherUser->fresh()->profile_picture,
        );
        Storage::disk('local')->assertMissing('profile-pictures/own.png');
        Storage::disk('local')->assertExists('profile-pictures/other.png');
    }

    public function test_delete_succeeds_when_the_old_file_is_already_missing(): void
    {
        $user = $this->user('User', [
            'profile_picture' => 'profile-pictures/missing.png',
        ]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::ENDPOINT)
            ->assertOk()
            ->assertJsonPath('data.has_profile_picture', false);

        $this->assertNull($user->fresh()->profile_picture);
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role, array $attributes = []): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Picture{$number}",
            'lastName' => 'Tester',
            'contact' => "0917000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "picture-user-{$number}",
            'employee_id' => "PICTURE-EMP-{$number}",
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
