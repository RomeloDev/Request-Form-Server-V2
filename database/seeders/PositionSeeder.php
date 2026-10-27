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
            'Web Developer',
            'President',
            'Vice President',
            'HR Consultant',
            'Accounting Staff',
            'Accounting Staff II',
            'Admin Staff',
            'Admin Staff II',
            'Area Manager',
            'Assembler',
            'Auditor II',
            'Assistant Web Developer',
            'Audit Supervisor',
            'Auto Electrician',
            'AVP - Finance',
            'AVP - Sales & Marketing',
            'Branch Manager'
        ];

        foreach ($positions as $position) {
            Position::firstOrCreate(
                ['value' => $position],
                ['label' => $position]
            );
        }
    }
}
