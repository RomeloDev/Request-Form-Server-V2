<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\ProfileSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProfileSignatureServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_failed_database_save_removes_new_file_and_keeps_old_signature(): void
    {
        $oldPath = 'signatures/old.png';
        $oldContents = UploadedFile::fake()->image('old.png')->getContent();
        Storage::disk('local')->put($oldPath, $oldContents);
        $user = $this->user(['signature' => $oldPath]);

        User::saving(function (User $savingUser) use ($user): void {
            if ($savingUser->is($user)) {
                throw new RuntimeException('Simulated signature save failure.');
            }
        });

        try {
            app(ProfileSignatureService::class)->replace(
                $user,
                UploadedFile::fake()->image('new.png'),
            );
            $this->fail('The simulated database failure should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Simulated signature save failure.',
                $exception->getMessage(),
            );
        }

        $this->assertSame($oldPath, $user->fresh()->signature);
        $this->assertSame([$oldPath], Storage::disk('local')->allFiles());
    }

    public function test_cleanup_never_deletes_a_path_outside_signature_directory(): void
    {
        $unsafePath = 'profile-pictures/avatar.png';
        $contents = UploadedFile::fake()->image('avatar.png')->getContent();
        Storage::disk('local')->put($unsafePath, $contents);
        Storage::disk('public')->put($unsafePath, $contents);
        $user = $this->user(['signature' => $unsafePath]);

        app(ProfileSignatureService::class)->remove($user);

        $this->assertNull($user->fresh()->signature);
        Storage::disk('local')->assertExists($unsafePath);
        Storage::disk('public')->assertExists($unsafePath);
    }

    public function test_locator_rejects_wrong_content_and_unsafe_paths(): void
    {
        $service = app(ProfileSignatureService::class);
        Storage::disk('local')->put('signatures/fake.png', 'not png');
        $file = UploadedFile::fake()->image('avatar.png');
        Storage::disk('local')->put(
            'profile-pictures/avatar.png',
            $file->getContent(),
        );

        $this->assertNull($service->locate('signatures/fake.png'));
        $this->assertNull($service->locate('profile-pictures/avatar.png'));
        $this->assertNull($service->locate('../signatures/avatar.png'));
        $this->assertNull($service->locate('/signatures/avatar.png'));
    }

    /** @param array<string, mixed> $attributes */
    private function user(array $attributes = []): User
    {
        return User::factory()->create([
            'firstName' => 'Signature',
            'lastName' => 'Service Tester',
            'contact' => '0915000001',
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => 'signature-service-user',
            'employee_id' => 'SIGNATURE-SERVICE-USER',
            ...$attributes,
        ]);
    }
}
