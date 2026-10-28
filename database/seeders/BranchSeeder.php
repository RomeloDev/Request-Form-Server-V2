<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Branch;

class BranchSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $branches = [
            [
                'branch_code' => 'ALAD',
                'branch_name' => 'ALANO',
                'branch' => 'Des Appliance Plaza, Inc.',
                'acronym' => 'DAP',
            ],

            // Add a second record using LEARN-003.
            [
                'branch_code' => 'SALA',
                'branch_name' => 'ALANO',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'CALI',
                'branch_name' => 'CALINAN',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'SMCT',
                'branch_name' => 'IPIL',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'MALA',
                'branch_name' => 'MALAYBALAY',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'MOLS',
                'branch_name' => 'MOLAVE',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'SDIP',
                'branch_name' => 'TURNO',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'AKLA',
                'branch_name' => 'AKLAN',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'ALIC',
                'branch_name' => 'ALICIA',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'ALEN',
                'branch_name' => 'ALLEN',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'ANTIP',
                'branch_name' => 'ANTIPOLO',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'ANTI',
                'branch_name' => 'ANTIQUE',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
            [
                'branch_code' => 'SARG',
                'branch_name' => 'ARGAO',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],
        ];

        foreach ($branches as $branch) {
            Branch::firstOrCreate(
                ['branch_code' => $branch['branch_code']],
                [
                    'branch_name' => $branch['branch_name'],
                    'branch' => $branch['branch'],
                    'acronym' => $branch['acronym'],
                ]
            );
        }
    }
}
