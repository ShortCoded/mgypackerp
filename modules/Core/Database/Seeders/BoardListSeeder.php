<?php

namespace Modules\Core\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Core\Models\BoardList;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\DocumentNumberService;

class BoardListSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $position => $definition) {
            $list = BoardList::query()
                ->withTrashed()
                ->firstOrNew([
                    'type' => $definition['type'],
                    'slug' => $definition['slug'],
                ]);

            if ($list->doc_num === null) {
                $document = app(DocumentNumberService::class)->next('board_lists', BoardList::class);
                $list->doc_number = $document['doc_number'];
                $list->doc_num = $document['doc_num'];
            }

            $list->fill([
                'name' => $definition['name'],
                'status' => $definition['status'],
                'color' => $definition['color'],
                'position' => $position,
                'is_system' => true,
                'deleted_by' => null,
            ]);

            if ($list->trashed()) {
                $list->restore();
            }

            $list->save();
        }
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
