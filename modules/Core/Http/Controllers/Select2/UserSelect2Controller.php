<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\UserSelect2Service;

class UserSelect2Controller extends Controller
{
    public function __construct(
        private readonly UserSelect2Service $users,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canUseUsers($request), 403);

        return response()->json($this->users->paginated($request));
    }

    private function canUseUsers(Request $request): bool
    {
        $user = $request->user();

        return (bool) $user?->can('my_board.assign')
            || (bool) $user?->can('my_board.view_any')
            || (bool) $user?->can('my_board.manage_any')
            || (bool) $user?->can('my_board.tasks.view_all')
            || (bool) $user?->can('my_board.notes.view_all')
            || (bool) $user?->can('my_board.create')
            || (bool) $user?->can('my_board.edit')
            || (bool) $user?->can('quick_tasks.create')
            || (bool) $user?->can('quick_tasks.update')
            || (bool) $user?->can('quick_tasks.change_status')
            || (bool) $user?->can('task_boards.create')
            || (bool) $user?->can('task_boards.update')
            || (bool) $user?->can('task_boards.public_settings')
            || (bool) $user?->can('quotations.view')
            || (bool) $user?->can('quotations.create')
            || (bool) $user?->can('quotations.edit')
            || (bool) $user?->can('chat.create')
            || (bool) $user?->can('chat.send')
            || (bool) $user?->can('users.view');
    }
}
