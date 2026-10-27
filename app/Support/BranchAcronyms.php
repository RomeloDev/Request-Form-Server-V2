<?php

namespace App\Support;

class BranchAcronyms
{
    public static function allowedFor(string $company): array
    {
        return match ($company) {
            'Strong Moto Centrum, Inc.' => ['SMCT', 'KIA'],
            'Des Strong Motors, Inc.' => ['DSM'],
            'Honda Des, Inc.' => ['HD'],

            'Des Appliance Plaza, Inc.',
            'Des Appliance, Inc.' => ['DAP'],

            default => ['HO'],
        };
    }

    // Call this with data that has already passed validation.
    public static function resolve(
        string $company,
        ?string $requested = null,
        ?string $existingCompany = null,
        ?string $existingAcronym = null,
    ): string {
        $allowed = self::allowedFor($company);

        if ($requested !== null) {
            return $requested;
        }

        if (
            $company === $existingCompany
            && in_array($existingAcronym, $allowed, true)
        ) {
            return $existingAcronym;
        }

        return $allowed[0];
    }
}
