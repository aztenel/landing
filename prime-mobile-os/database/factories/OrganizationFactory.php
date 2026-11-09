<?php

namespace Database\Factories;

use App\Domain\Tenancy\Models\Organization;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bare organization row. For a working organization use OrganizationProvisioner::create().
 *
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'settings' => [],
            'timezone' => 'Asia/Almaty',
        ];
    }
}
