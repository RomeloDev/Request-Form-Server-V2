<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProfileSignatureService
{
    /**
     * New signatures are written to the private local disk only.
     */
    private const PRIVATE_DISK = 'local';

    /**
     * Older registrations stored signatures on the public disk. The protected
     * readers keep a fallback for those existing records, but this disk is
     * never used for new uploads.
     */
    private const LEGACY_DISK = 'public';

    private const DIRECTORY = 'signatures';

    /**
     * Replace the authenticated user's signature.
     *
     * The new file is stored first, then the database row is updated. If the
     * database update fails, the new file is removed so storage cannot drift
     * away from the database. The obsolete file is removed only after the
     * database write succeeds.
     */
    public function replace(User $user, UploadedFile $signature): void
    {
        $oldPath = $user->signature;
        $newPath = $signature->store(self::DIRECTORY, self::PRIVATE_DISK);

        if (! is_string($newPath) || $newPath === '') {
            throw new RuntimeException('Unable to store the signature.');
        }

        try {
            if (! $user->forceFill([
                'signature' => $newPath,
            ])->save()) {
                throw new RuntimeException('Unable to update the signature.');
            }
        } catch (Throwable $exception) {
            $this->deleteQuietly($newPath);

            throw $exception;
        }

        if ($oldPath !== $newPath) {
            $this->deleteStoredSignature($oldPath);
        }
    }

    /**
     * Clear the user's signature and then remove the obsolete file.
     *
     * The database value is cleared first, so a physically missing file can
     * never block the reset.
     */
    public function remove(User $user): void
    {
        $oldPath = $user->signature;

        if (! $user->forceFill([
            'signature' => null,
        ])->save()) {
            throw new RuntimeException('Unable to remove the signature.');
        }

        $this->deleteStoredSignature($oldPath);
    }

    /**
     * Locate a stored signature for a protected response.
     *
     * The private disk is preferred. The public disk is consulted only so
     * signatures created before this migration remain printable; the caller
     * still streams the bytes itself, so no public URL is exposed.
     *
     * @return array{disk: string, path: string}|null
     */
    public function locate(?string $path): ?array
    {
        if (! is_string($path) || ! $this->isSafeSignaturePath($path)) {
            return null;
        }

        foreach ([self::PRIVATE_DISK, self::LEGACY_DISK] as $diskName) {
            try {
                $disk = Storage::disk($diskName);

                if (! $disk->exists($path) || ! $this->isPngFile($disk, $path)) {
                    continue;
                }

                return [
                    'disk' => $diskName,
                    'path' => $path,
                ];
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return null;
    }

    /**
     * Remove one stored signature from every disk that may hold it.
     *
     * Both the private disk and the legacy public disk are checked because a
     * record created before this change points at the public disk while a
     * replacement points at the private disk.
     */
    private function deleteStoredSignature(mixed $path): void
    {
        if (! is_string($path) || ! $this->isSafeSignaturePath($path)) {
            return;
        }

        foreach ([self::PRIVATE_DISK, self::LEGACY_DISK] as $diskName) {
            try {
                $disk = Storage::disk($diskName);

                if ($disk->exists($path)) {
                    $disk->delete($path);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function deleteQuietly(string $path): void
    {
        try {
            Storage::disk(self::PRIVATE_DISK)->delete($path);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
        }
    }

    /**
     * Verify the stored bytes instead of trusting a filename or MIME guess.
     */
    private function isPngFile(FilesystemAdapter $disk, string $path): bool
    {
        $stream = $disk->readStream($path);

        if (! is_resource($stream)) {
            return false;
        }

        try {
            $header = fread($stream, 65536);
        } finally {
            fclose($stream);
        }

        if (! is_string($header) || $header === '') {
            return false;
        }

        $image = @getimagesizefromstring($header);

        return is_array($image)
            && ($image[2] ?? null) === IMAGETYPE_PNG;
    }

    /**
     * Only server-generated PNG paths are streamed.
     *
     * Rejecting traversal segments and non-PNG extensions keeps a malformed
     * legacy database value from pointing the protected reader at another
     * file type.
     */
    private function isSafeSignaturePath(string $path): bool
    {
        if (! $this->isSafeRelativePath($path)) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);
        $segments = explode('/', $normalized);

        return count($segments) >= 2
            && $segments[0] === self::DIRECTORY
            && ! in_array('', $segments, true)
            && ! in_array('.', $segments, true)
            && strtolower(pathinfo($normalized, PATHINFO_EXTENSION)) === 'png';
    }

    /**
     * Reject absolute or traversal paths before any storage operation.
     */
    private function isSafeRelativePath(string $path): bool
    {
        if ($path === '' || $path !== trim($path)) {
            return false;
        }

        if (str_contains($path, "\0")) {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        return ! str_starts_with($normalized, '/')
            && ! str_contains($normalized, '..');
    }
}
