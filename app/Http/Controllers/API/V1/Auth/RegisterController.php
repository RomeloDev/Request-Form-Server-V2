<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class RegisterController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        // A. Store the uploaded signature.
        $path = $request->file('signature')->store('signatures', 'public');

        if ($path === false) {
            throw new RuntimeException('Unable to store the signature.');
        }

        try {
            // B. Create the user and token together
            $result = DB::transaction(function () use ($data, $path) {
                $user = User::create([
                    'name' => trim(
                        $data['firstName'].' '.$data['lastName']
                    ),
                    'firstName' => $data['firstName'],
                    'lastName' => $data['lastName'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'contact' => $data['contact'],
                    'branch_code' => $data['branch_code'],
                    'branch' => $data['branch'],
                    'position' => $data['position'],
                    'userName' => $data['userName'],
                    'employee_id' => $data['employee_id'],
                    'signature' => $path,
                ]);

                $user->assignRole('User');

                $token = $user->createToken(
                    'API TOKEN',
                    ['*'],
                    now()->addMinutes(480)
                );

                return [
                    'status' => true,
                    'message' => 'Registered Successfully',
                    'token' => $token->plainTextToken,
                ];
            });
        } catch (Throwable $exception) {
            // D. A database rollback cannot remove filesystem uploads.
            // Keep the original error even if cleanup also fails.

            try {
                $deleted = Storage::disk('public')->delete($path);

                if (! $deleted) {
                    report(new RuntimeException(
                        'Unable to remove signature after failed registration.'
                    ));
                }
            } catch (Throwable $cleanupException) {
                report($cleanupException);
            }

            throw $exception;
        }

        // C. Database work succeeded; return the registration response.
        return response()->json($result, 200);

    }
}
