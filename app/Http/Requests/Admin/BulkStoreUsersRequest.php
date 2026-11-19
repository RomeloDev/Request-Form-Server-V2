<?php

namespace App\Http\Requests\Admin;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\Attributes\FailOnUnknownFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

#[FailOnUnknownFields]
class BulkStoreUsersRequest extends FormRequest
{
    /**
     * Fields that can safely have surrounding whitespace removed.
     *
     * Passwords are deliberately excluded: whitespace can be meaningful.
     */
    private const TRIMMABLE_FIELDS = [
        'firstName',
        'lastName',
        'employee_id',
        'contact',
        'userName',
        'email',
        'position',
        'branch_code',
    ];

    private const UNIQUE_FIELDS = [
        'email',
        'userName',
        'employee_id',
    ];

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('bulkCreate', User::class) ?? false;
    }

    /**
     * Trim safe string fields before validation.
     */
    protected function prepareForValidation(): void
    {
        $users = $this->input('users');

        if (! is_array($users)) {
            return;
        }

        foreach ($users as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (self::TRIMMABLE_FIELDS as $field) {
                if (array_key_exists($field, $row) && is_string($row[$field])) {
                    $row[$field] = trim($row[$field]);
                }
            }

            $users[$index] = $row;
        }

        $this->merge(['users' => $users]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'users' => ['required', 'array', 'min:1', 'max:500'],
            'users.*' => ['array'],

            'users.*.firstName' => ['required', 'string', 'max:255'],
            'users.*.lastName' => ['required', 'string', 'max:255'],
            'users.*.employee_id' => ['required', 'string', 'max:255'],
            'users.*.contact' => ['required', 'string', 'regex:/^\d{11}$/'],
            'users.*.userName' => [
                'required', 'string', 'min:5', 'max:255',
                'lowercase', 'alpha_dash',
            ],
            'users.*.email' => ['required', 'string', 'email', 'max:255'],
            'users.*.password' => ['required', 'string', 'min:8'],
            'users.*.password_confirmation' => ['sometimes', 'string'],
            'users.*.position' => [
                'required', 'string', 'max:255',
                Rule::exists('positions', 'value'),
            ],
            'users.*.branch_code' => [
                'required', 'string', 'max:255',
                Rule::exists(Branch::class, 'branch_code'),
            ],

            // These values are server-derived or never accepted from the client.
            'users.*.id' => ['prohibited'],
            'users.*.user_id' => ['prohibited'],
            'users.*.name' => ['prohibited'],
            'users.*.role' => ['prohibited'],
            'users.*.branch' => ['prohibited'],
            'users.*.email_verified_at' => ['prohibited'],
            'users.*.profile_picture' => ['prohibited'],
            'users.*.profile_picture_path' => ['prohibited'],
            'users.*.signature' => ['prohibited'],
            'users.*.signature_path' => ['prohibited'],
            'users.*.remember_token' => ['prohibited'],
        ];
    }

    /**
     * Add batch duplicate and database conflict errors after base validation.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validatePasswordConfirmations($validator);
            $this->validateBatchUniqueness($validator);
        });
    }

    /**
     * Rows do not require a password confirmation. If one is supplied anyway,
     * a mismatch must fail instead of being silently ignored.
     */
    private function validatePasswordConfirmations(Validator $validator): void
    {
        $users = $this->input('users');

        if (! is_array($users)) {
            return;
        }

        foreach ($users as $index => $row) {
            if (! is_array($row) || ! array_key_exists('password_confirmation', $row)) {
                continue;
            }

            $password = $row['password'] ?? null;
            $confirmation = $row['password_confirmation'];

            if (! is_string($password) || ! is_string($confirmation) || $password !== $confirmation) {
                $validator->errors()->add(
                    "users.{$index}.password_confirmation",
                    'The password confirmation does not match.',
                );
            }
        }
    }

    private function validateBatchUniqueness(Validator $validator): void
    {
        $users = $this->input('users');

        if (! is_array($users)) {
            return;
        }

        $seen = [
            'email' => [],
            'userName' => [],
            'employee_id' => [],
        ];

        $candidates = [
            'email' => [],
            'userName' => [],
            'employee_id' => [],
        ];

        foreach ($users as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (self::UNIQUE_FIELDS as $field) {
                $path = "users.{$index}.{$field}";

                if ($validator->errors()->has($path)) {
                    continue;
                }

                $value = $row[$field] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $key = $this->comparisonKey($field, $value);

                if (array_key_exists($key, $seen[$field])) {
                    $validator->errors()->add(
                        $path,
                        $this->duplicateMessage($field),
                    );

                    continue;
                }

                $seen[$field][$key] = $index;
                $candidates[$field][$key] = true;
            }
        }

        $existing = [
            'email' => $this->existingEmailKeys(
                array_keys($candidates['email']),
            ),
            'userName' => $this->existingUserNameKeys(
                array_keys($candidates['userName']),
            ),
            'employee_id' => $this->existingEmployeeIdKeys(
                array_keys($candidates['employee_id']),
            ),
        ];

        foreach ($users as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            foreach (self::UNIQUE_FIELDS as $field) {
                $path = "users.{$index}.{$field}";

                if ($validator->errors()->has($path)) {
                    continue;
                }

                $value = $row[$field] ?? null;

                if (! is_string($value) || $value === '') {
                    continue;
                }

                $key = $this->comparisonKey($field, $value);

                if (isset($existing[$field][$key])) {
                    $validator->errors()->add(
                        $path,
                        $this->conflictMessage($field),
                    );
                }
            }
        }
    }

    private function comparisonKey(string $field, string $value): string
    {
        // Employee IDs are opaque identifiers; email and username are
        // deliberately compared case-insensitively.
        return $field === 'employee_id'
            ? $value
            : Str::lower($value);
    }

    /**
     * @param  list<string>  $emails
     * @return array<string, true>
     */
    private function existingEmailKeys(array $emails): array
    {
        if ($emails === []) {
            return [];
        }

        return User::query()
            ->whereIn(DB::raw('LOWER(email)'), $emails)
            ->pluck('email')
            ->mapWithKeys(fn (string $email): array => [
                Str::lower($email) => true,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $userNames
     * @return array<string, true>
     */
    private function existingUserNameKeys(array $userNames): array
    {
        if ($userNames === []) {
            return [];
        }

        return User::query()
            ->whereIn(DB::raw('LOWER(userName)'), $userNames)
            ->pluck('userName')
            ->mapWithKeys(fn (string $userName): array => [
                Str::lower($userName) => true,
            ])
            ->all();
    }

    /**
     * @param  list<string>  $employeeIds
     * @return array<string, true>
     */
    private function existingEmployeeIdKeys(array $employeeIds): array
    {
        if ($employeeIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('employee_id', $employeeIds)
            ->pluck('employee_id')
            ->mapWithKeys(fn (string $employeeId): array => [
                $employeeId => true,
            ])
            ->all();
    }

    private function duplicateMessage(string $field): string
    {
        return match ($field) {
            'email' => 'The email appears more than once in this batch.',
            'userName' => 'The username appears more than once in this batch.',
            'employee_id' => 'The employee ID appears more than once in this batch.',
            default => 'The value appears more than once in this batch.',
        };
    }

    private function conflictMessage(string $field): string
    {
        return match ($field) {
            'email' => 'The email has already been taken.',
            'userName' => 'The username has already been taken.',
            'employee_id' => 'The employee ID has already been taken.',
            default => 'The value has already been taken.',
        };
    }
}
