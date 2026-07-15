@php
    use Modules\Core\Models\QuickTask;
    use Modules\Core\Services\QuickTaskService;
    use Modules\Core\Services\SettingService;

    $settings = app(SettingService::class);
    $quickTaskService = app(QuickTaskService::class);
    $actor = auth()->user();
    $statusColor = static fn (string $status): string => match ($status) {
        QuickTask::StatusInProgress => 'primary',
        QuickTask::StatusReady => 'info',
        default => 'secondary',
    };
    $priorityColor = static fn (string $priority): string => match ($priority) {
        QuickTask::PriorityUrgent => 'danger',
        QuickTask::PriorityHigh => 'warning',
        QuickTask::PriorityLow => 'secondary',
        default => 'info',
    };
    $nextAction = static fn (string $status): ?array => match ($status) {
        QuickTask::StatusNew => ['status' => QuickTask::StatusInProgress, 'label' => __('quick_tasks.actions.start'), 'icon' => 'fa-play'],
        QuickTask::StatusInProgress => ['status' => QuickTask::StatusReady, 'label' => __('quick_tasks.actions.mark_ready'), 'icon' => 'fa-check'],
        QuickTask::StatusReady => ['status' => QuickTask::StatusDone, 'label' => __('quick_tasks.actions.done'), 'icon' => 'fa-flag-checkered'],
        default => null,
    };
@endphp

<div class="quick-task-board-grid">
    @foreach (QuickTask::ActiveStatuses as $status)
        @php
            $tasks = collect($groups[$status] ?? []);
            $action = $nextAction($status);
        @endphp
        <section class="quick-task-board-column quick-task-board-column-{{ str_replace('_', '-', $status) }}" data-status="{{ $status }}">
            <div class="quick-task-board-column-header">
                <div>
                    <h5 class="mb-0">{{ __("quick_tasks.statuses.{$status}") }}</h5>
                    <span class="text-600 fs-10">{{ $tasks->count() }}</span>
                </div>
                <span class="badge rounded-pill badge-subtle-{{ $statusColor($status) }}">{{ __("quick_tasks.statuses.{$status}") }}</span>
            </div>

            <div class="quick-task-board-list">
                @forelse ($tasks as $task)
                    <article class="quick-task-board-card" data-doc-num="{{ $task->doc_num }}">
                        <div class="d-flex align-items-start justify-content-between gap-2 mb-2">
                            <span class="quick-task-board-doc" dir="ltr">{{ $task->doc_num }}</span>
                            <span class="badge rounded-pill badge-subtle-{{ $priorityColor((string) $task->priority) }}">{{ __("quick_tasks.priorities.{$task->priority}") }}</span>
                        </div>

                        <h3 class="quick-task-board-title">{{ $task->title }}</h3>

                        @if ($task->summary)
                            <p class="quick-task-board-summary">{{ $task->summary }}</p>
                        @endif

                        <div class="quick-task-board-meta">
                            @if ($task->assignedTo)
                                <span><span class="fas fa-user me-1"></span>{{ $task->assignedTo->name }}</span>
                            @endif
                            <span><span class="far fa-clock me-1"></span>{{ $settings->formatDateTime($task->created_at, '') }}</span>
                            @if ((int) ($task->attachments_count ?? 0) > 0)
                                <span><span class="fas fa-paperclip me-1"></span>{{ (int) $task->attachments_count }}</span>
                            @endif
                        </div>

                        @php
                            $permittedAction = $actor && $action
                                ? collect($quickTaskService->statusActionOptions($task, $actor))->firstWhere('status', $action['status'])
                                : null;
                        @endphp

                        @if ($permittedAction)
                            <div class="quick-task-board-actions">
                                <button class="btn btn-falcon-{{ $permittedAction['color'] }} btn-sm js-board-status-action" type="button" data-url="{{ route('admin.quick-tasks.change-status', $task->doc_num) }}" data-status="{{ $permittedAction['status'] }}">
                                    <span class="fas {{ $permittedAction['icon'] }} me-1"></span>{{ $permittedAction['label'] }}
                                </button>
                            </div>
                        @endif
                    </article>
                @empty
                    <div class="quick-task-board-empty">
                        <span class="far fa-clipboard"></span>
                        <span>{{ __('quick_tasks.empty.board') }}</span>
                    </div>
                @endforelse
            </div>
        </section>
    @endforeach
</div>
