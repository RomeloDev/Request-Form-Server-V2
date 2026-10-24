<?php

namespace Database\Factories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Branch>
 */
class BranchFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'branch_code' => fake()->unique()->bothify('TEST-####-????'),
            'branch_name' => 'Learning Test Branch',
            'branch' => 'Learning Test Company',
            'acronym' => 'LTC',
        ];
    }
}
