<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Core\Models\Company;
use Modules\Core\Models\TaskBoard;

/**
 * @extends Factory<TaskBoard>
 */
class TaskBoardFactory extends Factory
{
    protected $model = TaskBoard::class;

    protected static int $documentNumber = 1000;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $documentNumber = ++static::$documentNumber;
        $creator = User::factory();

        return [
            'company_id' => Company::factory(),
            'branch_id' => null,
            'doc_number' => $documentNumber,
            'doc_num' => 'TB-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
            'name' => fake()->words(3, true),
            'description' => fake()->optional()->sentence(10),
            'is_active' => true,
            'is_public' => false,
            'requires_password' => false,
            'display_theme' => TaskBoard::DisplayThemeLight,
            'public_token' => Str::random(64),
            'public_password_hash' => null,
            'last_public_access_at' => null,
            'created_by' => $creator,
            'updated_by' => null,
            'deleted_by' => null,
            'restored_by' => null,
            'restored_at' => null,
        ];
    }

    public function public(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_public' => true,
            'requires_password' => false,
            'public_password_hash' => null,
        ]);
    }
}
