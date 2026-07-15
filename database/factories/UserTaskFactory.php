<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\UserTask;

/**
 * @extends Factory<UserTask>
 */
class UserTaskFactory extends Factory
{
    protected $model = UserTask::class;

    protected static int $documentNumber = 1000;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $documentNumber = ++static::$documentNumber;
        $creator = User::factory();

        return [
            'doc_number' => $documentNumber,
            'doc_num' => 'Task-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
            'title' => fake()->sentence(4),
            'description' => fake()->optional()->paragraph(),
            'type' => UserTask::TypeTask,
            'status' => fake()->randomElement(UserTask::Statuses),
            'is_active' => true,
            'priority' => fake()->randomElement(UserTask::Priorities),
            'color' => fake()->optional()->randomElement(UserTask::Colors),
            'assigned_to' => $creator,
            'assigned_by' => null,
            'created_by' => $creator,
            'updated_by' => null,
            'deleted_by' => null,
            'restored_by' => null,
            'start_at' => null,
            'due_at' => fake()->optional()->dateTimeBetween('+1 day', '+30 days'),
            'completed_at' => null,
            'position' => fake()->numberBetween(0, 20),
            'restored_at' => null,
        ];
    }

    public function note(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => UserTask::TypeNote,
            'priority' => UserTask::PriorityNormal,
        ]);
    }

    public function assignedTo(User $assignee, ?User $assigner = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'assigned_to' => $assignee->getKey(),
            'assigned_by' => ($assigner ?? $assignee)->getKey(),
            'created_by' => ($assigner ?? $assignee)->getKey(),
        ]);
    }
}
