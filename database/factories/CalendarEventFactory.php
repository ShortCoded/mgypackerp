<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Modules\Core\Models\CalendarEvent;

/**
 * @extends Factory<CalendarEvent>
 */
class CalendarEventFactory extends Factory
{
    protected $model = CalendarEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(fake()->numberBetween(1, 20))->setTime(fake()->numberBetween(8, 16), 0);

        return [
            'public_uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'title' => fake()->sentence(3),
            'description' => fake()->optional()->paragraph(),
            'starts_at' => $start,
            'ends_at' => (clone $start)->addHour(),
            'all_day' => false,
            'status' => CalendarEvent::StatusPending,
            'color' => fake()->randomElement(CalendarEvent::Colors),
            'location' => fake()->optional()->city(),
            'meeting_url' => fake()->optional()->url(),
            'reminder_at' => null,
        ];
    }

    public function allDay(): static
    {
        return $this->state(fn (array $attributes): array => [
            'all_day' => true,
            'starts_at' => now()->addDays(3)->startOfDay(),
            'ends_at' => null,
        ]);
    }
}
