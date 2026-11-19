<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\ProfilePictureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class ProfilePictureServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_replace_saves_the_new_path_before_deleting_the_old_file(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('profile-pictures/old.jpg', 'old picture');
        $user = $this->createUser([
            'profile_picture' => 'profile-pictures/old.jpg',
        ]);

        app(ProfilePictureService::class)->replace(
            $user,
            UploadedFile::fake()->image('new.jpg'),
        );

        $user->refresh();

        $this->assertNotSame('profile-pictures/old.jpg', $user->profile_picture);
        Storage::disk('local')->assertExists($user->profile_picture);
        Storage::disk('local')->assertMissing('profile-pictures/old.jpg');
    }

    public function test_replace_keeps_the_old_picture_and_cleans_up_new_file_when_saving_fails(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('profile-pictures/old.jpg', 'old picture');
        $user = $this->createUser([
            'profile_picture' => 'profile-pictures/old.jpg',
        ]);

        User::saving(function (User $savingUser) use ($user): void {
            if ($savingUser->is($user)) {
                throw new RuntimeException('Simulated database update failure.');
            }
        });

        try {
            app(ProfilePictureService::class)->replace(
                $user,
                UploadedFile::fake()->image('new.jpg'),
            );
            $this->fail('The failed save should be rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated database update failure.', $exception->getMessage());
        }

        $this->assertSame('profile-pictures/old.jpg', $user->fresh()->profile_picture);
        Storage::disk('local')->assertExists('profile-pictures/old.jpg');
        $this->assertSame(['profile-pictures/old.jpg'], Storage::disk('local')->allFiles());
    }

    public function test_remove_clears_the_database_even_when_the_old_file_is_missing(): void
    {
        Storage::fake('local');
        $user = $this->createUser([
            'profile_picture' => 'profile-pictures/missing.jpg',
        ]);

        app(ProfilePictureService::class)->remove($user);

        $this->assertNull($user->fresh()->profile_picture);
    }

    public function test_replace_removes_a_legacy_public_picture_after_the_database_update(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $oldPath = 'request_form_profile_pictures/legacy.jpg';
        Storage::disk('public')->put($oldPath, 'legacy picture');
        $user = $this->createUser([
            'profile_picture' => $oldPath,
        ]);

        app(ProfilePictureService::class)->replace(
            $user,
            UploadedFile::fake()->image('new.jpg'),
        );

        $user->refresh();

        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('local')->assertExists($user->profile_picture);
    }

    public function test_remove_deletes_a_legacy_public_picture(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $oldPath = 'request_form_profile_pictures/legacy.jpg';
        Storage::disk('public')->put($oldPath, 'legacy picture');
        $user = $this->createUser([
            'profile_picture' => $oldPath,
        ]);

        app(ProfilePictureService::class)->remove($user);

        $this->assertNull($user->fresh()->profile_picture);
        Storage::disk('public')->assertMissing($oldPath);
    }

    /** @param array<string, mixed> $attributes */
    private function createUser(array $attributes = []): User
    {
        return User::factory()->create([
            'firstName' => 'Profile',
            'lastName' => 'Picture Tester',
            'contact' => '0918000001',
            'branch_code' => null,
            'branch' => null,
            'position' => 'Employee',
            'userName' => 'profile-picture-user',
            'employee_id' => 'PROFILE-PICTURE-TEST',
            ...$attributes,
        ]);
    }
}
