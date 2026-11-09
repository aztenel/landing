<?php

namespace Database\Factories;

use App\Domain\Counterparties\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Requires CurrentOrganization to be set (tenant model).
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'phone' => '+7701'.fake()->unique()->numerify('#######'),
            'name' => fake()->firstName(),
            'source' => 'pos',
        ];
    }
}
