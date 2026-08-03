<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'plan' => 'cloud',
            'status' => 'active',
            'max_outlets' => null,
            'contact_email' => fake()->safeEmail(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn () => ['status' => 'suspended']);
    }
}
