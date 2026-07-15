<?php

namespace Modules\Core\Http\Controllers\Select2;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\TaskBoardSelect2Service;

class TaskBoardSelect2Controller extends Controller
{
    public function __construct(
        private readonly TaskBoardSelect2Service $taskBoards,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user();

        abort_unless($user instanceof User && $this->canUseTaskBoards($user), 403);

        return response()->json($this->taskBoards->paginated($request, $user));
    }

    private function canUseTaskBoards(User $user): bool
    {
        return (bool) $user->can('task_boards.view')
            || (bool) $user->can('quick_tasks.create')
            || (bool) $user->can('quick_tasks.update');
    }
}
