<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Models\MyBoardLabel;

/**
 * @extends Factory<MyBoardLabel>
 */
class MyBoardLabelFactory extends Factory
{
    protected $model = MyBoardLabel::class;

    protected static int $documentNumber = 1000;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $documentNumber = ++static::$documentNumber;

        return [
            'doc_number' => $documentNumber,
            'doc_num' => 'BoardLabel-'.str_pad((string) $documentNumber, 5, '0', STR_PAD_LEFT),
            'name' => fake()->unique()->word(),
            'color' => fake()->randomElement(MyBoardLabel::Colors),
            'status' => MyBoardLabel::StatusActive,
            'created_by' => User::factory(),
            'updated_by' => null,
            'deleted_by' => null,
            'restored_by' => null,
            'restored_at' => null,
        ];
    }
}
