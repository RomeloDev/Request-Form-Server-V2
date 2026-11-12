<?php

namespace Database\Seeders;

use App\Models\Supplier;
use Illuminate\Database\Seeder;

class SupplierSeeder extends Seeder
{
    /**
     * Seed suppliers used for local development and manual testing.
     */
    public function run(): void
    {
        $suppliers = [
            [
                'name' => 'Metro Office Supplies',
                'address' => '123 Rizal Avenue, Davao City',
            ],
            [
                'name' => 'Prime Hardware Trading',
                'address' => '45 National Highway, Digos City',
            ],
            [
                'name' => 'Southern Computer Solutions',
                'address' => '78 J.P. Laurel Avenue, Davao City',
            ],
            [
                'name' => 'Mindanao Equipment Center',
                'address' => '90 MacArthur Highway, Matina, Davao City',
            ],
            [
                'name' => 'Pacific General Merchandise',
                'address' => '21 Quezon Boulevard, Kidapawan City',
            ],
        ];

        foreach ($suppliers as $supplier) {
            Supplier::updateOrCreate(
                [
                    'name' => $supplier['name'],
                ],
                [
                    'address' => $supplier['address'],
                ],
            );
        }
    }
}
