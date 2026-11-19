<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProfilePictureService
{
    /**
     * New profile pictures are written to the private local disk only.
     */
    private const PRIVATE_DISK = 'local';

    /**
     * Legacy Admin edits stored pictures on the public disk. The protected
     * reader keeps a fallback for those records, but this disk is never used
     * for new uploads.
     */
    private const LEGACY_DISK = 'public';

    private const PRIVATE_DIRECTORY = 'profile-pictures';

    private const LEGACY_DIRECTORY = 'request_form_profile_pictures';

    /**
     * Only the formats approved by UpdateProfilePictureRequest are readable.
     *
     * @var array<int, array{mime_type: string, extension: string}>
     */
    private const ALLOWED_TYPES = [
        IMAGETYPE_PNG => ['mime_type' => 'image/png', 'extension' => 'png'],
        IMAGETYPE_JPEG => ['mime_type' => 'image/jpeg', 'extension' => 'jpg'],
    ];

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

        if ($oldPath !== $newPath) {
            $this->deleteStoredPicture($oldPath);
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

        $this->deleteStoredPicture($oldPath);
    }

    /**
     * Locate a stored profile picture for a protected response.
     *
     * Only the two known directories are consulted, and the stored bytes are
     * inspected instead of trusting the database path or the file extension.
     * A missing file and an unsafe path deliberately look the same (null), so
     * the caller can return one indistinguishable 404.
     *
     * @return array{disk: string, path: string, mime_type: string, extension: string}|null
     */
    public function locate(?string $path): ?array
    {
        if (! is_string($path) || ! $this->isSafePicturePath($path)) {
            return null;
        }

        $normalized = str_replace('\\', '/', $path);

        $diskName = match (true) {
            str_starts_with($normalized, self::PRIVATE_DIRECTORY.'/') => self::PRIVATE_DISK,
            str_starts_with($normalized, self::LEGACY_DIRECTORY.'/') => self::LEGACY_DISK,
            default => null,
        };

        if ($diskName === null) {
            return null;
        }

        try {
            $disk = Storage::disk($diskName);

            if (! $disk->exists($path)) {
                return null;
            }

            $imageType = $this->detectImageType($disk, $path);
            $allowed = $imageType === null
                ? null
                : (self::ALLOWED_TYPES[$imageType] ?? null);

            if ($allowed === null || ! $this->extensionMatches($normalized, $imageType)) {
                return null;
            }

            return [
                'disk' => $diskName,
                'path' => $path,
                'mime_type' => $allowed['mime_type'],
                'extension' => $allowed['extension'],
            ];
        } catch (Throwable $exception) {
            report($exception);
        }

        return null;
    }

    /**
     * Read the real image type from the stored bytes.
     */
    private function detectImageType(FilesystemAdapter $disk, string $path): ?int
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $header = fread($stream, 65536);
        } finally {
            fclose($stream);
        }

        if (! is_string($header) || $header === '') {
            return null;
        }

        $image = @getimagesizefromstring($header);

        return is_array($image) && isset($image[2])
            ? (int) $image[2]
            : null;
    }

    /**
     * The extension must agree with the detected bytes.
     */
    private function extensionMatches(string $path, int $imageType): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($imageType) {
            IMAGETYPE_PNG => $extension === 'png',
            IMAGETYPE_JPEG => in_array($extension, ['jpg', 'jpeg'], true),
            default => false,
        };
    }

    /**
     * Remove a known profile-picture path from its owning disk.
     *
     * Older Admin edits used the public disk, while current Profile uploads
     * use the private local disk. Deleting from the disk selected by the
     * allowlisted directory avoids leaving a legacy public file behind after
     * its database reference has been replaced or cleared.
     */
    private function deleteStoredPicture(mixed $path): void
    {
        if (! is_string($path) || ! $this->isSafePicturePath($path)) {
            return;
        }

        $normalized = str_replace('\\', '/', $path);

        $diskName = match (true) {
            str_starts_with($normalized, self::PRIVATE_DIRECTORY.'/') => self::PRIVATE_DISK,
            str_starts_with($normalized, self::LEGACY_DIRECTORY.'/') => self::LEGACY_DISK,
            default => null,
        };

        if ($diskName === null) {
            return;
        }

        try {
            Storage::disk($diskName)->delete($path);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Accept only a flat file inside one of the two known directories.
     *
     * Rejects absolute paths, Windows drive prefixes, traversal segments,
     * empty segments, and nested directories.
     */
    private function isSafePicturePath(string $path): bool
    {
        if ($path === '' || $path !== trim($path) || str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        if (str_starts_with($normalized, '/') || str_contains($normalized, '..')) {
            return false;
        }

        $segments = explode('/', $normalized);

        return count($segments) === 2
            && ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && ! str_contains($segments[0], ':');
    }
}
