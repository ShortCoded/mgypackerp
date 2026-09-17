<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Core\Models\UserTask;

class PersonalDashboardService
{
    public function __construct(
        private readonly OperatingContextService $operatingContext,
        private readonly NotificationService $notifications,
        private readonly DateFormatService $dates,
        private readonly PendingDecisionService $pendingDecisions,
        private readonly ChatService $chat,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $context = $this->operatingContext->snapshot($request);
        $canViewTasks = (bool) $user->can('my_board.view');
        $canViewChat = (bool) $user->can('chat.view');
        $tasks = $this->taskQuery($user);
        $taskSummary = $canViewTasks
            ? (clone $tasks)
                ->selectRaw('COALESCE(SUM(CASE WHEN status != ? THEN 1 ELSE 0 END), 0) as open_count', [UserTask::StatusDone])
                ->selectRaw('COALESCE(SUM(CASE WHEN status != ? AND due_at >= ? AND due_at < ? THEN 1 ELSE 0 END), 0) as due_today_count', [UserTask::StatusDone, now()->startOfDay(), now()->addDay()->startOfDay()])
                ->selectRaw('COALESCE(SUM(CASE WHEN status != ? AND due_at < ? THEN 1 ELSE 0 END), 0) as overdue_count', [UserTask::StatusDone, now()])
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) as in_progress_count', [UserTask::StatusInProgress])
                ->selectRaw('COALESCE(SUM(CASE WHEN status = ? AND completed_at >= ? AND completed_at < ? THEN 1 ELSE 0 END), 0) as completed_today_count', [UserTask::StatusDone, now()->startOfDay(), now()->addDay()->startOfDay()])
                ->first()
            : null;
        $approval = $this->pendingDecisions->summary($user, $context);
        $taskItems = $canViewTasks
            ? (clone $tasks)
                ->where('status', '!=', UserTask::StatusDone)
                ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('due_at')
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
                ->limit(12)
                ->get()
                ->map(fn (UserTask $task): array => $this->taskItem($task))
            : collect();
        $workItems = $taskItems
            ->concat($approval['items'])
            ->sortBy(fn (array $item): string => sprintf('%02d:%s', $item['rank'], $item['sort_at']))
            ->take(16)
            ->values();
        $openTasks = (int) ($taskSummary?->open_count ?? 0);
        $dueToday = (int) ($taskSummary?->due_today_count ?? 0);
        $overdue = (int) ($taskSummary?->overdue_count ?? 0);
        $inProgress = (int) ($taskSummary?->in_progress_count ?? 0);
        $completedToday = (int) ($taskSummary?->completed_today_count ?? 0);
        $unread = $this->notifications->unreadCount($user);
        $cards = collect();

        if ($canViewTasks) {
            $cards->push(
                $this->card('open_tasks', __('dashboard.personal.cards.open_tasks'), $openTasks, __('dashboard.personal.meta.open_tasks'), 'tasks', $overdue > 0 ? 'danger' : 'primary', route('admin.my-board.index', ['focus' => 'open'], false)),
                $this->card('due_today', __('dashboard.personal.cards.due_today'), $dueToday, __('dashboard.personal.meta.due_today'), 'calendar-day', $dueToday > 0 ? 'warning' : 'secondary', route('admin.my-board.index', ['focus' => 'due_today'], false)),
                $this->card('overdue', __('dashboard.personal.cards.overdue'), $overdue, __('dashboard.personal.meta.overdue'), 'exclamation-circle', $overdue > 0 ? 'danger' : 'secondary', route('admin.my-board.index', ['focus' => 'overdue'], false)),
            );
        }

        if ($approval['has_sources']) {
            $cards->push($this->card('approvals', __('dashboard.personal.cards.approvals'), $approval['count'], $approval['meta'], 'clipboard-check', $approval['count'] > 0 ? 'warning' : 'secondary', route('dashboard.pending-decisions', [], false)));
        }

        $cards->push($this->card('unread', __('dashboard.personal.cards.unread'), $unread, __('dashboard.personal.meta.unread'), 'bell', $unread > 0 ? 'info' : 'secondary', route('admin.notifications.index', ['state' => 'unread'], false)));

        if ($canViewChat) {
            $unreadConversations = $this->chat->unreadConversationCount($user);
            $cards->push($this->card('unread_conversations', __('dashboard.personal.cards.unread_conversations'), $unreadConversations, __('dashboard.personal.meta.unread_conversations'), 'comments', $unreadConversations > 0 ? 'info' : 'secondary', route('admin.chat.index', ['filter' => 'unread'], false)));
        }

        if ($canViewTasks) {
            $cards->push(
                $this->card('completed_today', __('dashboard.personal.cards.completed_today'), $completedToday, __('dashboard.personal.meta.completed_today'), 'check-circle', $completedToday > 0 ? 'success' : 'secondary', route('admin.my-board.index', ['focus' => 'completed_today'], false)),
                $this->card('in_progress', __('dashboard.personal.cards.in_progress'), $inProgress, __('dashboard.personal.meta.in_progress'), 'spinner', $inProgress > 0 ? 'primary' : 'secondary', route('admin.my-board.index', ['focus' => 'in_progress'], false)),
            );
        }

        return [
            'summary' => [
                'required_count' => $openTasks + $approval['count'],
                'overdue_count' => $overdue,
                'approval_count' => $approval['count'],
                'updated_at' => now()->toIso8601String(),
                'updated_at_label' => $this->dates->formatDateTime(now()),
            ],
            'cards' => $cards->values()->all(),
            'work_items' => $workItems->map(fn (array $item): array => collect($item)->except(['rank', 'sort_at'])->all())->all(),
            'recent_updates' => $this->notifications->latestFor($user, 8)
                ->map(fn ($notification): array => [
                    'id' => $notification->public_uuid,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'severity' => $notification->severity,
                    'url' => route('admin.notifications.open', $notification, false),
                    'time' => $this->dates->formatDateTime($notification->delivered_at ?: $notification->created_at, ''),
                ])->all(),
            'limitations' => $approval['has_sources'] && (! $context['company_id'] || ! $context['branch_id'])
                ? [__('dashboard.personal.context_required')]
                : [],
        ];
    }

    /**
     * @return Builder<UserTask>
     */
    private function taskQuery(User $user): Builder
    {
        return UserTask::query()
            ->where('type', UserTask::TypeTask)
            ->where('is_active', true)
            ->where(function (Builder $query) use ($user): void {
                $query->where('assigned_to', $user->getKey())
                    ->orWhereHas('assignees', fn (Builder $assignees): Builder => $assignees->whereKey($user->getKey()));
            });
    }

    /** @return array<string, mixed> */
    private function taskItem(UserTask $task): array
    {
        $overdue = $task->due_at?->isPast() ?? false;

        return [
            'key' => "task:{$task->getKey()}",
            'title' => $task->title,
            'body' => __('dashboard.personal.work.task'),
            'meta' => $task->due_at
                ? __('dashboard.personal.work.due', ['time' => $this->dates->formatDateTime($task->due_at, '')])
                : __('dashboard.personal.work.no_due_date'),
            'severity' => $overdue ? 'urgent' : 'action',
            'url' => route('admin.my-board.index', ['task' => $task->doc_num], false),
            'rank' => $overdue ? 0 : 2,
            'sort_at' => $task->due_at?->toIso8601String() ?? '9999-12-31',
        ];
    }

    /** @return array<string, mixed> */
    private function card(string $key, string $title, int $value, string $meta, string $icon, string $color, string $url): array
    {
        return compact('key', 'title', 'value', 'meta', 'icon', 'color', 'url');
    }
}
