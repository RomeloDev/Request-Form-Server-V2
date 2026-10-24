<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Position;

class PositionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $positions = [
            'Accounting Clerk',
            'Branch Manager',
            'Auditor',
        ];

        foreach ($positions as $position) {
            Position::firstOrCreate(
                ['value' => $position],
                ['label' => $position]
            );
        }
    }
}
