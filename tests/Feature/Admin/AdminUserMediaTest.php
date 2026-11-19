<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminUserMediaTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_media_routes_require_authentication(): void
    {
        $target = $this->user();

        $this->getJson($this->profilePictureUrl($target))->assertUnauthorized();
        $this->getJson($this->signatureUrl($target))->assertUnauthorized();
    }

    public function test_non_admin_cannot_read_another_users_media(): void
    {
        $target = $this->user();

        foreach (['User', 'approver'] as $role) {
            Sanctum::actingAs($this->user($role));

            $this->getJson($this->profilePictureUrl($target))->assertForbidden();
            $this->getJson($this->signatureUrl($target))->assertForbidden();
        }
    }

    public function test_unknown_user_returns_not_found(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        $this->getJson('/api/v1/admin/users/999999/profile-picture')
            ->assertNotFound();
        $this->getJson('/api/v1/admin/users/999999/signature')
            ->assertNotFound();
    }

    public function test_admin_can_stream_a_private_png_profile_picture(): void
    {
        $bytes = UploadedFile::fake()
            ->image('picture.png', 300, 120)
            ->getContent();
        $path = 'profile-pictures/private-picture.png';
        Storage::disk('local')->put($path, $bytes);

        $target = $this->user(attributes: ['profile_picture' => $path]);

        Sanctum::actingAs($this->user('Admin'));

        $response = $this->get($this->profilePictureUrl($target));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringContainsString(
            "user-{$target->id}-profile-picture.png",
            $disposition,
        );
        $this->assertStringNotContainsString($path, $disposition);
        $this->assertStringNotContainsString('storage', $disposition);

        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_admin_can_stream_a_jpeg_profile_picture(): void
    {
        $bytes = UploadedFile::fake()
            ->image('picture.jpg', 300, 120)
            ->getContent();
        $path = 'profile-pictures/private-picture.jpg';
        Storage::disk('local')->put($path, $bytes);

        $target = $this->user(attributes: ['profile_picture' => $path]);

        Sanctum::actingAs($this->user('Admin'));

        $this->get($this->profilePictureUrl($target))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/jpeg');

        $this->assertSame(
            $bytes,
            $this->get($this->profilePictureUrl($target))->streamedContent(),
        );
    }

    public function test_legacy_public_profile_picture_is_supported(): void
    {
        $bytes = UploadedFile::fake()
            ->image('legacy.png', 300, 120)
            ->getContent();
        $path = 'request_form_profile_pictures/legacy-picture.png';
        Storage::disk('public')->put($path, $bytes);

        $target = $this->user(attributes: ['profile_picture' => $path]);

        Sanctum::actingAs($this->user('Admin'));

        $response = $this->get($this->profilePictureUrl($target));

        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($bytes, $response->streamedContent());
    }

    public function test_admin_can_stream_private_and_legacy_signatures(): void
    {
        $privateBytes = UploadedFile::fake()
            ->image('private.png', 300, 120)
            ->getContent();
        $legacyBytes = UploadedFile::fake()
            ->image('legacy.png', 300, 120)
            ->getContent();

        Storage::disk('local')->put('signatures/private.png', $privateBytes);
        Storage::disk('public')->put('signatures/legacy.png', $legacyBytes);

        $private = $this->user(attributes: ['signature' => 'signatures/private.png']);
        $legacy = $this->user(attributes: ['signature' => 'signatures/legacy.png']);

        Sanctum::actingAs($this->user('Admin'));

        $response = $this->get($this->signatureUrl($private));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $cacheControl = (string) $response->headers->get('Cache-Control');
        $disposition = (string) $response->headers->get('Content-Disposition');

        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('inline', $disposition);
        $this->assertStringContainsString(
            "user-{$private->id}-signature.png",
            $disposition,
        );
        $this->assertSame($privateBytes, $response->streamedContent());

        $legacyResponse = $this->get($this->signatureUrl($legacy));

        $legacyResponse->assertOk();
        $this->assertSame($legacyBytes, $legacyResponse->streamedContent());
    }

    public function test_media_responses_never_expose_disk_names_or_storage_paths(): void
    {
        $privatePicture = 'profile-pictures/leak-check.png';
        $privateSignature = 'signatures/leak-check.png';

        Storage::disk('local')->put(
            $privatePicture,
            UploadedFile::fake()->image('leak.png', 200, 80)->getContent(),
        );
        Storage::disk('local')->put(
            $privateSignature,
            UploadedFile::fake()->image('leak.png', 200, 80)->getContent(),
        );

        $target = $this->user(attributes: [
            'profile_picture' => $privatePicture,
            'signature' => $privateSignature,
        ]);

        Sanctum::actingAs($this->user('Admin'));

        foreach ([
            $this->profilePictureUrl($target) => $privatePicture,
            $this->signatureUrl($target) => $privateSignature,
        ] as $url => $storedPath) {
            $response = $this->get($url);

            $response->assertOk();

            $headers = (string) json_encode($response->headers->all());

            $this->assertStringNotContainsString($storedPath, $headers);
            $this->assertStringNotContainsString('profile-pictures/', $headers);
            $this->assertStringNotContainsString('signatures/', $headers);
            $this->assertStringNotContainsString('request_form_profile_pictures/', $headers);
            $this->assertStringNotContainsString('"local"', $headers);
            $this->assertStringNotContainsString('"public"', $headers);
            $this->assertStringNotContainsString(
                $storedPath,
                (string) $response->streamedContent(),
            );
        }
    }

    public function test_missing_or_null_media_returns_not_found(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        $empty = $this->user();

        $this->getJson($this->profilePictureUrl($empty))->assertNotFound();
        $this->getJson($this->signatureUrl($empty))->assertNotFound();

        $missing = $this->user(attributes: [
            'profile_picture' => 'profile-pictures/does-not-exist.png',
            'signature' => 'signatures/does-not-exist.png',
        ]);

        $this->getJson($this->profilePictureUrl($missing))->assertNotFound();
        $this->getJson($this->signatureUrl($missing))->assertNotFound();
    }

    public function test_unsafe_or_malformed_paths_are_indistinguishable_from_missing_files(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        $unsafePaths = [
            '../profile-pictures/secret.png',
            '/profile-pictures/absolute.png',
            'profile-pictures/../../secret.png',
            'profile-pictures/nested/deeper.png',
            'signatures/not-a-picture.png',
            'arbitrary-directory/picture.png',
            'C:/windows/picture.png',
            'profile-pictures//double-slash.png',
            ' profile-pictures/leading-space.png',
        ];

        foreach ($unsafePaths as $path) {
            $target = $this->user(attributes: ['profile_picture' => $path]);

            $this->getJson($this->profilePictureUrl($target))
                ->assertNotFound();
        }

        foreach ($unsafePaths as $path) {
            $target = $this->user(attributes: ['signature' => $path]);

            $this->getJson($this->signatureUrl($target))->assertNotFound();
        }
    }

    public function test_non_image_svg_and_extension_mismatch_content_is_rejected(): void
    {
        Sanctum::actingAs($this->user('Admin'));

        Storage::disk('local')->put(
            'profile-pictures/fake.png',
            'this is not an image',
        );
        Storage::disk('local')->put(
            'profile-pictures/vector.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );
        Storage::disk('local')->put(
            'profile-pictures/mismatched.jpg',
            UploadedFile::fake()->image('real.png', 300, 120)->getContent(),
        );

        foreach (['fake.png', 'vector.svg', 'mismatched.jpg'] as $file) {
            $target = $this->user(attributes: [
                'profile_picture' => "profile-pictures/{$file}",
            ]);

            $this->getJson($this->profilePictureUrl($target))
                ->assertNotFound();
        }

        Storage::disk('local')->put('signatures/vector.svg', '<svg />');

        $target = $this->user(attributes: ['signature' => 'signatures/vector.svg']);

        $this->getJson($this->signatureUrl($target))->assertNotFound();
    }

    public function test_media_routes_are_named_and_protected_by_admin_access(): void
    {
        $this->assertNotNull(
            app('router')->getRoutes()->getByName('admin.users.profile-picture.show'),
        );
        $this->assertNotNull(
            app('router')->getRoutes()->getByName('admin.users.signature.show'),
        );
    }

    private function profilePictureUrl(User $user): string
    {
        return "/api/v1/admin/users/{$user->id}/profile-picture";
    }

    private function signatureUrl(User $user): string
    {
        return "/api/v1/admin/users/{$user->id}/signature";
    }

    /** @param array<string, mixed> $attributes */
    private function user(
        string $role = 'User',
        array $attributes = [],
    ): User {
        $number = ++$this->sequence;

        $user = User::factory()->create([
            'firstName' => "AdminMedia{$number}",
            'lastName' => 'Tester',
            'contact' => "0918000{$number}",
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => "admin-media-{$number}",
            'employee_id' => "ADMIN-MEDIA-{$number}",
            'profile_picture' => null,
            'signature' => null,
            ...$attributes,
        ]);

        $user->assignRole($role);

        return $user;
    }
}
