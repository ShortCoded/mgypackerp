<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\Company;
use Modules\Core\Models\QuickTask;

/**
 * @extends Factory<QuickTask>
 */
class QuickTaskFactory extends Factory
{
    protected $model = QuickTask::class;

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
            'task_board_id' => null,
            'doc_number' => $documentNumber,
            'doc_num' => 'QT-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
            'title' => fake()->sentence(4),
            'summary' => fake()->optional()->sentence(8),
            'details' => fake()->optional()->paragraph(),
            'status' => fake()->randomElement(QuickTask::Statuses),
            'priority' => fake()->randomElement(QuickTask::Priorities),
            'assigned_to' => null,
            'created_by' => $creator,
            'updated_by' => null,
            'deleted_by' => null,
            'restored_by' => null,
            'restored_at' => null,
        ];
    }

    public function activeBoardTask(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => fake()->randomElement(QuickTask::ActiveStatuses),
        ]);
    }
}
