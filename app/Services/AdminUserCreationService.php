<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminUserCreationService
{
    /**
     * Create a verified standard user account from validated Admin input.
     *
     * The input array is an allowlist: only the keys documented below are
     * read. Role, verification state, company, and media fields are always
     * derived here rather than accepted from the request.
     *
     * @param  array{
     *     firstName: string,
     *     lastName: string,
     *     employee_id: string,
     *     contact: string,
     *     userName: string,
     *     email: string,
     *     password: string,
     *     position: string,
     *     branch_code: string
     * }  $data
     */
    public function create(array $data): User
    {
        $user = DB::transaction(
            fn (): User => $this->createInTransaction($data),
        );

        $user->load('roles:id,name');

        return $user;
    }

    /**
     * Create a batch of verified standard users in one transaction.
     *
     * If any row fails, the outer transaction rolls back every user created
     * by this call.
     *
     * @param  list<array{
     *     firstName: string,
     *     lastName: string,
     *     employee_id: string,
     *     contact: string,
     *     userName: string,
     *     email: string,
     *     password: string,
     *     position: string,
     *     branch_code: string
     * }>  $rows
     * @return list<User>
     */
    public function createMany(array $rows): array
    {
        return DB::transaction(function () use ($rows): array {
            return array_map(
                fn (array $data): User => $this->createInTransaction($data),
                $rows,
            );
        });
    }

    /**
     * Shared creation rules used by single and bulk Admin creation.
     *
     * @param  array{
     *     firstName: string,
     *     lastName: string,
     *     employee_id: string,
     *     contact: string,
     *     userName: string,
     *     email: string,
     *     password: string,
     *     position: string,
     *     branch_code: string
     * }  $data
     */
    private function createInTransaction(array $data): User
    {
        $branch = Branch::query()
            ->where('branch_code', $data['branch_code'])
            ->firstOrFail();

        $user = new User;

        // The password is hashed once by the model's "hashed" cast.
        $user->fill([
            'name' => trim($data['firstName'].' '.$data['lastName']),
            'firstName' => $data['firstName'],
            'lastName' => $data['lastName'],
            'employee_id' => $data['employee_id'],
            'contact' => $data['contact'],
            'userName' => $data['userName'],
            'email' => $data['email'],
            'password' => $data['password'],
            'position' => $data['position'],
            'branch_code' => $branch->branch_code,
            'branch' => $branch->branch,
            'signature' => null,
            'profile_picture' => null,
        ]);

        // email_verified_at is not mass assignable, so set it directly.
        $user->email_verified_at = now();
        $user->save();

        // The exact Spatie role is assigned server-side.
        $user->assignRole('User');

        return $user;
    }
}
