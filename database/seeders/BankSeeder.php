<?php

namespace Database\Seeders;

use App\Models\Bank;
use Illuminate\Database\Seeder;

class BankSeeder extends Seeder
{
    public function run(): void
    {
        $banks = [
            'BDO Unibank',
            'Bank of the Philippine Islands',
            'Metrobank',
            'Land Bank of the Philippines',
            'Philippine National Bank',
            'Security Bank',
            'UnionBank of the Philippines',
            'China Banking Corporation',
        ];

        foreach ($banks as $name) {
            Bank::firstOrCreate([
                'name' => $name,
            ]);
        }
    }
}
