<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProfilePictureService
{
    public function replace(User $user, UploadedFile $picture): void
    {
        $oldPath = $user->profile_picture;
        $newPath = $picture->store('profile-pictures', 'local');

        if ($newPath === false) {
            throw new RuntimeException('Unable to store the profile picture.');
        }

        try {
            if (! $user->forceFill([
                'profile_picture' => $newPath,
            ])->save()) {
                throw new RuntimeException('Unable to update the profile picture.');
            }
        } catch (Throwable $exception) {
            try {
                Storage::disk('local')->delete($newPath);
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        if (is_string($oldPath) && $oldPath !== '' && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);
        }
    }

    public function remove(User $user): void
    {
        $oldPath = $user->profile_picture;

        if (! $user->forceFill([
            'profile_picture' => null,
        ])->save()) {
            throw new RuntimeException('Unable to remove the profile picture.');
        }

        if (is_string($oldPath) && $oldPath !== '') {
            Storage::disk('local')->delete($oldPath);
        }
    }
}
