<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\BoardList;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\DocumentNumberService;

class BoardListSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            foreach ($this->defaults() as $position => $definition) {
                if (BoardList::withTrashed()->where('type', $definition['type'])->where('slug', $definition['slug'])->exists()) {
                    continue;
                }

                BoardList::query()->create([
                    ...app(DocumentNumberService::class)->next('board_lists', BoardList::class),
                    'type' => $definition['type'],
                    'slug' => $definition['slug'],
                    'name' => $definition['name'],
                    'status' => $definition['status'],
                    'color' => $definition['color'],
                    'position' => $position,
                    'is_system' => true,
                ]);
            }
        });
    }

    /**
     * @return list<array{type: string, slug: string, name: string, status: string, color: string}>
     */
    private function defaults(): array
    {
        $names = app()->getLocale() === 'ar'
            ? [
                UserTask::StatusTodo => 'للبدء',
                UserTask::StatusInProgress => 'قيد التنفيذ',
                UserTask::StatusWaiting => 'انتظار',
                UserTask::StatusDone => 'مكتملة',
            ]
            : [
                UserTask::StatusTodo => 'To Do',
                UserTask::StatusInProgress => 'In Progress',
                UserTask::StatusWaiting => 'Waiting',
                UserTask::StatusDone => 'Done',
            ];
        $statuses = [
            UserTask::StatusTodo => [$names[UserTask::StatusTodo], 'primary'],
            UserTask::StatusInProgress => [$names[UserTask::StatusInProgress], 'info'],
            UserTask::StatusWaiting => [$names[UserTask::StatusWaiting], 'warning'],
            UserTask::StatusDone => [$names[UserTask::StatusDone], 'success'],
        ];

        $defaults = [];

        foreach ([UserTask::TypeTask, UserTask::TypeNote] as $type) {
            foreach ($statuses as $status => [$name, $color]) {
                $defaults[] = [
                    'type' => $type,
                    'slug' => $status,
                    'name' => $name,
                    'status' => $status,
                    'color' => $color,
                ];
            }
        }

        return $defaults;
    }
}
