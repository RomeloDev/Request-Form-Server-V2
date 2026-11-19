<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Give test users a signature that the signature prerequisite treats as usable.
 *
 * The file is written to the legacy public disk on purpose: request tests
 * frequently assert that the private local disk stays empty after a rejected
 * write, so a fixture there would change unrelated assertions.
 */
trait ProvidesUsableSignatures
{
    /**
     * A real 1x1 PNG header, so ProfileSignatureService treats the file as PNG.
     */
    private const TEST_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function giveUsableSignature(User $user): User
    {
        $path = "signatures/test-signature-{$user->getKey()}.png";

        Storage::disk('public')->put($path, $this->usableSignatureBytes());

        $user->forceFill([
            'signature' => $path,
        ])->save();

        return $user;
    }

    protected function usableSignatureBytes(): string
    {
        $bytes = base64_decode(self::TEST_PNG_BASE64, true);

        return is_string($bytes) ? $bytes : '';
    }
}
