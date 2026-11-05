<?php

namespace App\Http\Controllers\API\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Branch;
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

        $branch = Branch::query()
            ->where('branch_code', $data['branch_code'])
            ->firstOrFail();

        // A. Store the uploaded signature.
        $path = $request->file('signature')->store('signatures', 'public');

        if ($path === false) {
            throw new RuntimeException('Unable to store the signature.');
        }

        try {
            // B. Create the user with server-derived branch information.
            $result = DB::transaction(function () use ($data, $path, $branch) {
                $user = User::create([
                    'name' => trim(
                        $data['firstName'].' '.$data['lastName']
                    ),
                    'firstName' => $data['firstName'],
                    'lastName' => $data['lastName'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'contact' => $data['contact'],
                    'branch_code' => $branch->branch_code,
                    'branch' => $branch->branch,
                    'position' => $data['position'],
                    'userName' => $data['userName'],
                    'employee_id' => $data['employee_id'],
                    'signature' => $path,
                ]);

                $user->assignRole('User');

                return [
                    'status' => true,
                    'message' => 'Registered Successfully',
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
