<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Modules\Core\Models\TaskBoard;

class TaskBoardSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
        private readonly TaskBoardService $taskBoards,
        private readonly TaskBoardAccessService $access,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(Request $request, User $actor): array
    {
        $search = $request->input('q', $request->input('term'));

        $query = $this->taskBoards->scopeToCurrentContext(TaskBoard::query(), $request)
            ->where('task_boards.is_active', true)
            ->select([
                'task_boards.doc_num',
                'task_boards.name',
                'task_boards.doc_number',
            ])
            ->orderBy('task_boards.name')
            ->orderBy('task_boards.doc_number');

        $this->access->scopeVisibleBoards($query, $actor, 'task_boards.view');

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'task_boards.doc_num',
                    'task_boards.doc_number',
                    'task_boards.name',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (TaskBoard $board): array => $this->item($board));
    }

    /**
     * @return array{id: string, text: string}
     */
    public function item(TaskBoard $board): array
    {
        return [
            'id' => (string) $board->doc_num,
            'text' => $this->label($board),
        ];
    }

    public function label(TaskBoard $board): string
    {
        return trim(implode(' / ', array_filter([
            $board->name,
            $board->doc_num,
        ])));
    }
}
