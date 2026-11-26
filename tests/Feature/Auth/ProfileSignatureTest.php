<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ProfileSignatureTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/profile/signature';

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

    public function test_signature_routes_require_authentication(): void
    {
        $this->getJson(self::ENDPOINT)->assertUnauthorized();
        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->image('signature.png'),
        ])->assertUnauthorized();
        $this->deleteJson(self::ENDPOINT)->assertUnauthorized();
    }

    #[DataProvider('applicationRoles')]
    public function test_every_application_role_can_upload_its_own_signature(
        string $role,
    ): void {
        $user = $this->user($role);
        Sanctum::actingAs($user);

        $response = $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->image(
                "{$role}-original-signature.png",
                300,
                120,
            ),
        ]);

        $response
            ->assertOk()
            ->assertExactJson([
                'message' => 'Signature updated successfully.',
                'data' => ['has_signature' => true],
            ])
            ->assertJsonMissingPath('data.signature')
            ->assertJsonMissingPath('data.path')
            ->assertJsonMissingPath('data.disk');

        $path = $user->fresh()->signature;

        $this->assertIsString($path);
        $this->assertStringStartsWith('signatures/', $path);
        $this->assertStringNotContainsString('original-signature', $path);
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_manage_signature_policy_allows_only_the_same_user(): void
    {
        $user = $this->user('User');
        $otherUser = $this->user('User');

        $this->assertTrue(
            Gate::forUser($user)->allows('manageSignature', $user),
        );
        $this->assertFalse(
            Gate::forUser($user)->allows('manageSignature', $otherUser),
        );
    }

    public function test_upload_rejects_server_owned_identity_and_storage_fields(): void
    {
        $user = $this->user('User');
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->image('signature.png'),
            'user_id' => $this->user('User')->id,
            'role' => 'Admin',
            'signature_path' => 'signatures/forged.png',
            'disk' => 'public',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'role',
                'signature_path',
                'disk',
            ]);

        $this->assertNull($user->fresh()->signature);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_upload_validates_presence_type_and_png_mime(): void
    {
        Sanctum::actingAs($this->user('User'));

        $this->postJson(self::ENDPOINT)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->create(
                'signature.txt',
                10,
                'text/plain',
            ),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->image('signature.jpg'),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');
    }

    public function test_upload_validates_size_and_dimensions(): void
    {
        Sanctum::actingAs($this->user('User'));

        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()
                ->image('too-large.png')
                ->size(3073),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->image(
                'too-wide.png',
                4097,
                120,
            ),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');

        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->image(
                'too-tall.png',
                300,
                2049,
            ),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('signature');
    }

    public function test_replacement_removes_private_and_legacy_copies_after_success(): void
    {
        $oldPath = 'signatures/legacy.png';
        $oldContents = UploadedFile::fake()
            ->image('legacy.png', 300, 120)
            ->getContent();
        Storage::disk('local')->put($oldPath, $oldContents);
        Storage::disk('public')->put($oldPath, $oldContents);
        $user = $this->user('User', ['signature' => $oldPath]);
        Sanctum::actingAs($user);

        $this->postJson(self::ENDPOINT, [
            'signature' => UploadedFile::fake()->image(
                'replacement.png',
                300,
                120,
            ),
        ])->assertOk();

        $newPath = $user->fresh()->signature;

        $this->assertNotSame($oldPath, $newPath);
        Storage::disk('local')->assertExists($newPath);
        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_private_signature_is_streamed_and_query_overrides_are_ignored(): void
    {
        $ownFile = UploadedFile::fake()->image('own.png', 300, 120);
        $otherFile = UploadedFile::fake()->image('other.png', 600, 240);
        $ownPath = $ownFile->store('signatures', 'local');
        $otherPath = $otherFile->store('signatures', 'local');
        $user = $this->user('User', ['signature' => $ownPath]);
        $otherUser = $this->user('User', ['signature' => $otherPath]);
        Sanctum::actingAs($user);

        $response = $this->get(self::ENDPOINT.'?'.http_build_query([
            'user_id' => $otherUser->id,
            'signature_path' => $otherPath,
            'disk' => 'local',
        ]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $disposition = (string) $response->headers->get('Content-Disposition');
        $streamedContent = $response->streamedContent();

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringNotContainsString($ownPath, $disposition);
        $this->assertStringNotContainsString($otherPath, $disposition);
        $this->assertSame($ownFile->getContent(), $streamedContent);
        $this->assertNotSame($otherFile->getContent(), $streamedContent);
    }

    public function test_protected_read_supports_a_valid_legacy_public_signature(): void
    {
        $file = UploadedFile::fake()->image('legacy.png', 300, 120);
        $path = 'signatures/legacy.png';
        Storage::disk('public')->put($path, $file->getContent());
        $user = $this->user('User', ['signature' => $path]);
        Sanctum::actingAs($user);

        $response = $this->get(self::ENDPOINT);

        $response->assertOk();
        $this->assertSame($file->getContent(), $response->streamedContent());
    }

    public function test_read_rejects_missing_unsafe_or_non_png_content(): void
    {
        $user = $this->user('User');
        Sanctum::actingAs($user);

        $this->getJson(self::ENDPOINT)->assertNotFound();

        Storage::disk('local')->put(
            'signatures/not-an-image.png',
            'not png content',
        );
        $user->forceFill([
            'signature' => 'signatures/not-an-image.png',
        ])->save();
        $this->getJson(self::ENDPOINT)->assertNotFound();

        $otherFile = UploadedFile::fake()->image('other.png');
        Storage::disk('local')->put(
            'profile-pictures/other.png',
            $otherFile->getContent(),
        );
        $user->forceFill([
            'signature' => 'profile-pictures/other.png',
        ])->save();
        $this->getJson(self::ENDPOINT)->assertNotFound();
    }

    public function test_delete_removes_only_the_authenticated_users_signature(): void
    {
        $ownPath = 'signatures/own.png';
        $otherPath = 'signatures/other.png';
        $ownContents = UploadedFile::fake()->image('own.png')->getContent();
        $otherContents = UploadedFile::fake()->image('other.png')->getContent();
        Storage::disk('local')->put($ownPath, $ownContents);
        Storage::disk('public')->put($ownPath, $ownContents);
        Storage::disk('local')->put($otherPath, $otherContents);
        $user = $this->user('User', ['signature' => $ownPath]);
        $otherUser = $this->user('User', ['signature' => $otherPath]);
        Sanctum::actingAs($user);

        $this->deleteJson(self::ENDPOINT.'?'.http_build_query([
            'user_id' => $otherUser->id,
            'signature_path' => $otherPath,
            'disk' => 'local',
        ]))
            ->assertOk()
            ->assertExactJson([
                'message' => 'Signature removed successfully.',
                'data' => ['has_signature' => false],
            ]);

        $this->assertNull($user->fresh()->signature);
        $this->assertSame($otherPath, $otherUser->fresh()->signature);
        Storage::disk('local')->assertMissing($ownPath);
        Storage::disk('public')->assertMissing($ownPath);
        Storage::disk('local')->assertExists($otherPath);
    }

    public function test_routes_are_named_protected_and_throttled(): void
    {
        foreach ([
            'profile.signature.show' => null,
            'profile.signature.update' => 'throttle:40,1',
            'profile.signature.destroy' => 'throttle:40,1',
        ] as $name => $throttle) {
            $route = Route::getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertContains('auth:sanctum', $route->gatherMiddleware());

            if ($throttle !== null) {
                $this->assertContains($throttle, $route->gatherMiddleware());
            }
        }
    }

    /** @param array<string, mixed> $attributes */
    private function user(string $role, array $attributes = []): User
    {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "Signature{$number}",
            'lastName' => 'Tester',
            'contact' => "0915000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "profile-signature-{$number}",
            'employee_id' => "PROFILE-SIGNATURE-{$number}",
            'signature' => null,
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }

    /** @return array<string, array{string}> */
    public static function applicationRoles(): array
    {
        return [
            'Admin' => ['Admin'],
            'User' => ['User'],
            'approver' => ['approver'],
        ];
    }
}
