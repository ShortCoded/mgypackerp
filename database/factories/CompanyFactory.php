<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Company;

/**
 * @extends Factory<Company>
 */
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    protected static int $documentNumber = 1000;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $documentNumber = ++static::$documentNumber;

        return [
            'doc_number' => $documentNumber,
            'doc_num' => 'Company-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
            'name' => fake()->unique()->company(),
            'legal_name' => fake()->company().' LLC',
            'commercial_name' => fake()->company(),
            'status' => 'active',
            'is_main' => false,
            'country' => 'Egypt',
            'city' => fake()->city(),
            'phone' => fake()->unique()->numerify('+2010########'),
            'email' => fake()->unique()->safeEmail(),
        ];
    }

    public function main(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'active',
            'is_main' => true,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'inactive',
            'is_main' => false,
        ]);
    }
}
