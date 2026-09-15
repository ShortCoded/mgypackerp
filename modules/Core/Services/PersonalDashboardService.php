<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Modules\Core\Models\UserTask;

class PersonalDashboardService
{
    public function __construct(
        private readonly OperatingContextService $operatingContext,
        private readonly NotificationService $notifications,
        private readonly DateFormatService $dates,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function forRequest(Request $request): array
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        $context = $this->operatingContext->snapshot($request);
        $tasks = $this->taskQuery($user);
        $taskSummary = (clone $tasks)
            ->selectRaw('COUNT(*) as open_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN due_at >= ? AND due_at < ? THEN 1 ELSE 0 END), 0) as due_today_count', [now()->startOfDay(), now()->addDay()->startOfDay()])
            ->selectRaw('COALESCE(SUM(CASE WHEN due_at < ? THEN 1 ELSE 0 END), 0) as overdue_count', [now()])
            ->first();
        $approval = $this->approvalSummary($user, $context);
        $taskItems = (clone $tasks)
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END")
            ->limit(12)
            ->get()
            ->map(fn (UserTask $task): array => $this->taskItem($task));
        $workItems = $taskItems
            ->concat($approval['items'])
            ->sortBy(fn (array $item): string => sprintf('%02d:%s', $item['rank'], $item['sort_at']))
            ->take(16)
            ->values();
        $openTasks = (int) ($taskSummary?->open_count ?? 0);
        $dueToday = (int) ($taskSummary?->due_today_count ?? 0);
        $overdue = (int) ($taskSummary?->overdue_count ?? 0);
        $unread = $this->notifications->unreadCount($user);

        return [
            'summary' => [
                'required_count' => $openTasks + $approval['count'],
                'overdue_count' => $overdue,
                'approval_count' => $approval['count'],
                'updated_at' => now()->toIso8601String(),
                'updated_at_label' => $this->dates->formatDateTime(now()),
            ],
            'cards' => [
                $this->card('open_tasks', __('dashboard.personal.cards.open_tasks'), $openTasks, __('dashboard.personal.meta.open_tasks'), 'tasks', $overdue > 0 ? 'danger' : 'primary', route('admin.my-board.index', [], false)),
                $this->card('due_today', __('dashboard.personal.cards.due_today'), $dueToday, __('dashboard.personal.meta.due_today'), 'calendar-day', $dueToday > 0 ? 'warning' : 'secondary', route('admin.my-board.index', ['due' => 'today'], false)),
                $this->card('overdue', __('dashboard.personal.cards.overdue'), $overdue, __('dashboard.personal.meta.overdue'), 'exclamation-circle', $overdue > 0 ? 'danger' : 'secondary', route('admin.my-board.index', ['due' => 'overdue'], false)),
                $this->card('approvals', __('dashboard.personal.cards.approvals'), $approval['count'], $approval['meta'], 'clipboard-check', $approval['count'] > 0 ? 'warning' : 'secondary', $approval['url']),
                $this->card('unread', __('dashboard.personal.cards.unread'), $unread, __('dashboard.personal.meta.unread'), 'bell', $unread > 0 ? 'info' : 'secondary', route('admin.notifications.index', ['state' => 'unread'], false)),
            ],
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
            'limitations' => $approval['limitations'],
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
            ->where('status', '!=', UserTask::StatusDone)
            ->where(function (Builder $query) use ($user): void {
                $query->where('assigned_to', $user->getKey())
                    ->orWhereHas('assignees', fn (Builder $assignees): Builder => $assignees->whereKey($user->getKey()));
            });
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{count: int, items: Collection<int, array<string, mixed>>, meta: string, url: string, limitations: list<string>}
     */
    private function approvalSummary(User $user, array $context): array
    {
        $sources = collect($this->approvalSources())
            ->filter(fn (array $source): bool => $user->can($source['permission']))
            ->values();
        $queries = collect();
        $documentCastType = DB::connection()->getDriverName() === 'mysql' ? 'CHAR' : 'TEXT';

        foreach ($sources as $index => $source) {
            $query = DB::table($source['table'])->whereIn('status', $source['statuses']);

            if ($source['soft_deletes']) {
                $query->whereNull('deleted_at');
            }

            if ($context['company_id'] && $source['company_scoped']) {
                $query->where('company_id', $context['company_id']);
            }

            if ($context['branch_id'] && $source['branch_scoped']) {
                $query->where('branch_id', $context['branch_id']);
            }

            $queries->push($query
                ->selectRaw('? as source_index', [$index])
                ->addSelect(['id', 'status', 'created_at'])
                ->selectRaw("CAST({$source['document_column']} AS {$documentCastType}) as doc_num"));
        }

        if ($queries->isEmpty()) {
            return [
                'count' => 0,
                'items' => collect(),
                'meta' => __('dashboard.personal.meta.approvals'),
                'url' => route('dashboard', [], false),
                'limitations' => [],
            ];
        }

        $union = $queries->shift();
        $queries->each(fn ($query) => $union->unionAll($query));
        $records = DB::query()
            ->fromSub($union, 'approval_work')
            ->select(['source_index', 'id', 'doc_num', 'status', 'created_at'])
            ->selectRaw('COUNT(*) OVER () as total_count')
            ->selectRaw('MIN(created_at) OVER () as oldest_at')
            ->oldest('created_at')
            ->limit(16)
            ->get();
        $first = $records->first();
        $count = (int) ($first?->total_count ?? 0);
        $oldest = $first?->oldest_at;
        $firstSource = $first ? $sources->get((int) $first->source_index) : null;
        $items = $records->map(function (object $record) use ($sources): array {
            $source = $sources->get((int) $record->source_index);

            return [
                'key' => "{$source['table']}:{$record->id}:{$record->status}",
                'title' => $source['title'],
                'body' => __('dashboard.personal.work.document', ['document' => $record->doc_num]),
                'meta' => __('dashboard.personal.work.waiting_since', ['time' => Carbon::parse($record->created_at)->diffForHumans()]),
                'severity' => 'action',
                'url' => $source['url'].(str_contains($source['url'], '?') ? '&' : '?').http_build_query([
                    'status' => $record->status,
                    'search' => $record->doc_num,
                ]),
                'rank' => 1,
                'sort_at' => (string) $record->created_at,
            ];
        });

        return [
            'count' => $count,
            'items' => $items,
            'meta' => $oldest
                ? __('dashboard.personal.meta.approvals_oldest', ['time' => Carbon::parse($oldest)->diffForHumans()])
                : __('dashboard.personal.meta.approvals'),
            'url' => $firstSource['url'] ?? route('dashboard', [], false),
            'limitations' => [],
        ];
    }

    /**
     * @return list<array{table: string, permission: string, statuses: list<string>, title: string, url: string, document_column: string, soft_deletes: bool, company_scoped: bool, branch_scoped: bool}>
     */
    private function approvalSources(): array
    {
        return [
            $this->source('purchase_requisitions', 'purchases.purchase_requisition_approvals.approve', ['pending_approval'], __('dashboard.personal.sources.purchase_requisitions'), 'admin.purchases.purchase-requisition-approvals.index'),
            $this->source('purchase_orders', 'purchase_orders.approve', ['submitted'], __('dashboard.personal.sources.purchase_orders'), 'admin.purchases.purchase-orders.index'),
            $this->source('production_material_requests', 'production.material_requests.approve', ['submitted'], __('dashboard.personal.sources.material_requests'), 'admin.production.material-requests.index'),
            $this->source('quality_inspections', 'production.quality.review', ['submitted'], __('dashboard.personal.sources.quality'), 'admin.production.quality.index'),
            $this->source('production_expense_requests', 'production.expenses.approve', ['submitted'], __('dashboard.personal.sources.expenses'), 'admin.production.expenses.index'),
            $this->source('production_expense_requests', 'production.expenses.pay', ['approved'], __('dashboard.personal.sources.expenses_payment'), 'admin.production.expenses.index'),
            $this->source('maintenance_work_orders', 'maintenance.orders.approve', ['draft'], __('dashboard.personal.sources.maintenance'), 'admin.maintenance.orders.index'),
            $this->source('sales_orders', 'sales_orders.approve', ['pending_approval'], __('dashboard.personal.sources.sales_orders'), 'admin.sales.sales-orders.index'),
            $this->source('sales_orders', 'sales_orders.credit_override', ['held_credit'], __('dashboard.personal.sources.credit_holds'), 'admin.sales.sales-orders.index'),
            $this->source('sales_returns', 'sales_returns.authorize', ['pending_authorization'], __('dashboard.personal.sources.sales_returns'), 'admin.sales.sales-returns.index'),
            $this->source('sales_returns', 'sales_returns.receive', ['authorized'], __('dashboard.personal.sources.return_receipts'), 'admin.sales.sales-returns.index'),
            $this->source('sales_returns', 'sales_returns.inspect', ['received'], __('dashboard.personal.sources.return_inspections'), 'admin.sales.sales-returns.index'),
            $this->source('hr_employee_service_requests', 'hr.hr_requests.manage', ['submitted'], __('dashboard.personal.sources.hr_requests'), 'admin.hr.hr-requests.index', 'public_uuid'),
        ];
    }

    /**
     * @param  list<string>  $statuses
     * @return array{table: string, permission: string, statuses: list<string>, title: string, url: string, document_column: string, soft_deletes: bool, company_scoped: bool, branch_scoped: bool}
     */
    private function source(string $table, string $permission, array $statuses, string $title, string $routeName, string $documentColumn = 'doc_num'): array
    {
        return [
            'table' => $table,
            'permission' => $permission,
            'statuses' => $statuses,
            'title' => $title,
            'url' => Route::has($routeName) ? route($routeName, [], false) : route('dashboard', [], false),
            'document_column' => $documentColumn,
            'soft_deletes' => true,
            'company_scoped' => true,
            'branch_scoped' => true,
        ];
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
