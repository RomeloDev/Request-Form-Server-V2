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
                'branch_code' => 'LEARN-002',
                'branch_name' => 'LEARNING BRANCH TWO',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ],

            // Add a second record using LEARN-003.
            [
                'branch_code' => 'LEARN-003',
                'branch_name' => 'LEARNING BRANCH THREE',
                'branch' => 'Strong Moto Centrum, Inc.',
                'acronym' => 'SMCT',
            ]
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
