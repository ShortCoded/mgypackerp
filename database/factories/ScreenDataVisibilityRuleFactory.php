<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Auth\Models\ScreenDataVisibilityRule;
use Modules\Core\Models\Company;

/** @extends Factory<ScreenDataVisibilityRule> */
class ScreenDataVisibilityRuleFactory extends Factory
{
    protected $model = ScreenDataVisibilityRule::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 999999);

        return [
            'doc_number' => $number,
            'doc_num' => 'VisibilityRule-'.str_pad((string) $number, 5, '0', STR_PAD_LEFT),
            'company_id' => Company::factory(),
            'user_id' => User::factory(),
            'screen_key' => 'customers',
            'record_scope' => 'own_records',
            'max_visible_records' => 10,
            'duration_value' => 30,
            'duration_unit' => 'days',
            'is_active' => true,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
