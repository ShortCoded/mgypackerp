<?php

namespace Modules\Core\Http\Controllers;

use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Models\ArchiveFile;
use Modules\Core\Models\QuickTask;
use Modules\Core\Models\QuickTaskAttachment;
use Modules\Core\Models\TaskBoard;
use Modules\Core\Models\UserTask;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\ActivityLogProperties;
use Modules\Core\Services\SettingService;
use Modules\Core\Services\TaskBoardService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PublicTaskBoardDisplayController extends Controller
{
    private const DefaultPollingInterval = 5000;

    private const MinimumPollingInterval = 3000;

    private const DefaultUserDisplayRefreshInterval = 45000;

    private const MinimumUserDisplayRefreshInterval = 30000;

    private const MaximumUserDisplayRefreshInterval = 60000;

    private const UserDisplayRotationInterval = 3000;

    public function __construct(
        private readonly TaskBoardService $taskBoards,
        private readonly SettingService $settings,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function show(Request $request, string $publicToken): View
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            return view('modules.core.task-boards.public-unavailable');
        }

        if ($this->requiresAccessCode($request, $board)) {
            return view('modules.core.task-boards.public-access', [
                'board' => $board,
            ]);
        }

        $this->taskBoards->recordPublicAccess($board);

        return view('modules.core.task-boards.public-display', [
            'board' => $board,
            'branding' => $this->taskBoards->publicDisplayBranding($board),
            'displayTheme' => in_array($board->display_theme, TaskBoard::DisplayThemes, true) ? $board->display_theme : TaskBoard::DisplayThemeLight,
            'pollingInterval' => $this->pollingInterval(),
            'rotationInterval' => self::UserDisplayRotationInterval,
        ]);
    }

    public function userDisplay(Request $request, string $publicToken): View
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            return view('modules.core.task-boards.public-unavailable');
        }

        if ($this->requiresAccessCode($request, $board)) {
            return view('modules.core.task-boards.public-access', [
                'board' => $board,
                'intendedDisplay' => 'user-display',
            ]);
        }

        $this->taskBoards->recordPublicAccess($board);

        return view('modules.core.task-boards.public-user-display', [
            'board' => $board,
            'branding' => $this->taskBoards->publicDisplayBranding($board),
            'displayTheme' => in_array($board->display_theme, TaskBoard::DisplayThemes, true) ? $board->display_theme : TaskBoard::DisplayThemeLight,
            'refreshInterval' => $this->userDisplayRefreshInterval(),
            'rotationInterval' => self::UserDisplayRotationInterval,
        ]);
    }

    public function authenticate(Request $request, string $publicToken): RedirectResponse
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            return redirect()->route('public.task-boards.display', $publicToken);
        }

        $validated = $request->validate([
            'access_code' => ['required', 'string', 'max:100'],
            'intended_display' => ['nullable', 'string', 'in:display,user-display'],
        ], [], [
            'access_code' => __('task_boards.attributes.access_code'),
        ]);

        if (! $board->public_password_hash || ! Hash::check((string) $validated['access_code'], (string) $board->public_password_hash)) {
            return back()
                ->withInput()
                ->withErrors(['access_code' => __('task_boards.public.invalid_access_code')]);
        }

        $request->session()->put($this->sessionKey($board), true);
        $this->taskBoards->recordPublicAccess($board);

        $route = ($validated['intended_display'] ?? null) === 'user-display'
            ? 'public.task-boards.user-display'
            : 'public.task-boards.display';

        return redirect()->route($route, $board->public_token);
    }

    public function data(Request $request, string $publicToken): JsonResponse
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            return response()->json([
                'success' => false,
                'message' => __('task_boards.public.unavailable'),
            ], 404);
        }

        if ($this->requiresAccessCode($request, $board)) {
            return response()->json([
                'success' => false,
                'message' => __('task_boards.public.requires_access_code'),
            ], 403);
        }

        $this->taskBoards->recordPublicAccess($board);

        return response()->json([
            'success' => true,
            ...$this->taskBoards->publicDisplayPayload($board, $request),
        ]);
    }

    public function userDisplayData(Request $request, string $publicToken): JsonResponse
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            return response()->json([
                'success' => false,
                'message' => __('task_boards.public.unavailable'),
            ], 404);
        }

        if ($this->requiresAccessCode($request, $board)) {
            return response()->json([
                'success' => false,
                'message' => __('task_boards.public.requires_access_code'),
            ], 403);
        }

        $this->taskBoards->recordPublicAccess($board);

        return response()->json([
            'success' => true,
            ...$this->taskBoards->publicUserDisplayPayload($board),
        ]);
    }

    public function changeStatus(Request $request, string $publicToken): JsonResponse
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            return response()->json([
                'success' => false,
                'message' => __('task_boards.public.unavailable'),
            ], 404);
        }

        if ($this->requiresAccessCode($request, $board)) {
            return response()->json([
                'success' => false,
                'message' => __('task_boards.public.requires_access_code'),
            ], 403);
        }

        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => __('quick_tasks.messages.status_permission_denied'),
            ], 401);
        }

        $validated = $request->validate([
            'doc_num' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string'],
        ]);

        try {
            $result = $this->taskBoards->changeTaskStatus(
                $board,
                (string) $validated['doc_num'],
                (string) $validated['status'],
                $user,
                $request,
            );
        } catch (DomainException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $record = $result['record'];

        $this->activityLogger->log($request, 'core', 'quick_tasks.status_change', 'success', [
            'properties_only' => true,
            'properties' => ActivityLogProperties::crudUpdated(
                'quick_tasks',
                $record->title,
                $record->doc_num,
                ['status' => ['old' => $record->getOriginal('status'), 'new' => $record->status]],
            ),
        ]);

        return response()->json([
            'success' => true,
            'message' => __('quick_tasks.messages.status_changed'),
            'data' => [
                'doc_num' => $record->doc_num,
                'status' => $record->status,
            ],
        ]);
    }

    public function showAttachment(Request $request, string $publicToken, QuickTaskAttachment $attachment): StreamedResponse
    {
        return $this->attachmentResponse($request, $publicToken, $attachment, inline: true);
    }

    public function downloadAttachment(Request $request, string $publicToken, QuickTaskAttachment $attachment): StreamedResponse
    {
        return $this->attachmentResponse($request, $publicToken, $attachment, inline: false);
    }

    public function showUserTaskAttachment(Request $request, string $publicToken, UserTask $userTask, ArchiveFile $file): StreamedResponse
    {
        return $this->userTaskAttachmentResponse($request, $publicToken, $userTask, $file, inline: true);
    }

    public function downloadUserTaskAttachment(Request $request, string $publicToken, UserTask $userTask, ArchiveFile $file): StreamedResponse
    {
        return $this->userTaskAttachmentResponse($request, $publicToken, $userTask, $file, inline: false);
    }

    private function attachmentResponse(Request $request, string $publicToken, QuickTaskAttachment $attachment, bool $inline): StreamedResponse
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            abort(404);
        }

        if ($this->requiresAccessCode($request, $board)) {
            abort(403);
        }

        $attachment->loadMissing('quickTask');

        if (
            $attachment->quickTask === null
            || (int) $attachment->quickTask->task_board_id !== (int) $board->getKey()
            || ! in_array((string) $attachment->quickTask->status, QuickTask::ActiveStatuses, true)
        ) {
            abort(404);
        }

        abort_unless(Storage::disk($attachment->disk)->exists($attachment->path), 404);

        if ($inline && $attachment->isPreviewable()) {
            return response()->stream(function () use ($attachment): void {
                echo Storage::disk($attachment->disk)->get($attachment->path);
            }, 200, array_filter([
                'Content-Type' => $attachment->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_name).'"',
            ]));
        }

        return Storage::disk($attachment->disk)->download(
            $attachment->path,
            $attachment->original_name,
            array_filter(['Content-Type' => $attachment->mime_type ?: 'application/octet-stream']),
        );
    }

    private function userTaskAttachmentResponse(Request $request, string $publicToken, UserTask $userTask, ArchiveFile $file, bool $inline): StreamedResponse
    {
        $board = $this->taskBoards->publicBoardByToken($publicToken);

        if (! $board instanceof TaskBoard) {
            abort(404);
        }

        if ($this->requiresAccessCode($request, $board)) {
            abort(403);
        }

        $allowedUserIds = $this->taskBoards->publicDisplayUserIds($board);

        abort_unless(
            $userTask->type === UserTask::TypeTask
            && (bool) $userTask->is_active
            && in_array((string) $userTask->status, [UserTask::StatusTodo, UserTask::StatusInProgress, UserTask::StatusWaiting], true)
            && in_array((int) $userTask->assigned_to, $allowedUserIds, true),
            404,
        );

        $isAttached = $userTask->attachmentUsages()
            ->where('archive_file_id', $file->getKey())
            ->exists();

        abort_unless($isAttached, 404);
        abort_unless(Storage::disk($file->disk)->exists($file->path), 404);

        if ($inline && $file->isPreviewable()) {
            return response()->stream(function () use ($file): void {
                echo Storage::disk($file->disk)->get($file->path);
            }, 200, array_filter([
                'Content-Type' => $file->mime_type ?: 'application/octet-stream',
                'Content-Disposition' => 'inline; filename="'.addslashes($file->original_name).'"',
            ]));
        }

        return Storage::disk($file->disk)->download(
            $file->path,
            $file->original_name,
            array_filter(['Content-Type' => $file->mime_type ?: 'application/octet-stream']),
        );
    }

    private function requiresAccessCode(Request $request, TaskBoard $board): bool
    {
        return (bool) $board->requires_password
            && ! (bool) $request->session()->get($this->sessionKey($board), false);
    }

    private function sessionKey(TaskBoard $board): string
    {
        return 'task_board_display_access.'.sha1((string) $board->public_token);
    }

    private function pollingInterval(): int
    {
        $interval = (int) $this->settings->get('task_boards.display_polling_interval_ms', self::DefaultPollingInterval);

        return max(self::MinimumPollingInterval, $interval);
    }

    private function userDisplayRefreshInterval(): int
    {
        $interval = (int) $this->settings->get('task_boards.display_polling_interval_ms', self::DefaultUserDisplayRefreshInterval);

        return min(
            self::MaximumUserDisplayRefreshInterval,
            max(self::MinimumUserDisplayRefreshInterval, $interval),
        );
    }
}
