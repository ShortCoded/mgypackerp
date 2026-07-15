<?php

namespace Modules\Auth\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Auth\Services\Reports\ActivityLogReport;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Reports\ActivityLogHumanizer;
use Modules\Core\Services\Reports\ReportSanitizer;
use Spatie\Activitylog\Models\Activity;
use Yajra\DataTables\Facades\DataTables;

class ActivityLogsReportDataTable
{
    /**
     * @var array<string, array<string, mixed>>
     */
    private array $rowCache = [];

    public function __construct(
        private readonly ActivityLogReport $report,
        private readonly DataTableSearchService $searchService,
        private readonly ActivityLogHumanizer $humanizer,
        private readonly ReportSanitizer $sanitizer,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $this->rowCache = [];
        $table = config('activitylog.table_name', 'activity_log');
        $filters = $this->report->filtersFromRequest($request);
        $query = $this->report->listingQuery($filters);
        $canViewDetails = (bool) $request->user()?->can('activity.logs.details');
        $statusOrder = $this->statusRankOrderSql("{$table}.status");

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $table): void {
                $terms = $this->searchService->terms($request->input('search.value'));

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, [
                    'text' => [
                        "{$table}.public_id",
                        "{$table}.log_name",
                        "{$table}.description",
                        "{$table}.event",
                        "{$table}.module",
                        "{$table}.action",
                        "{$table}.status",
                        "{$table}.ip_address",
                        "{$table}.method",
                        "{$table}.url",
                        "{$table}.properties->record->label",
                        "{$table}.properties->record->doc_num",
                        "{$table}.properties->new_role_name",
                        "{$table}.properties->role_name",
                        "{$table}.properties->new_user_name",
                        "{$table}.properties->user_name",
                        "{$table}.properties->new_company_name",
                        "{$table}.properties->company_name",
                        "{$table}.properties->source_company_name",
                        "{$table}.properties->source_role_name",
                        "{$table}.properties->source_user_name",
                        "{$table}.properties->source_name",
                        "{$table}.properties->new_name",
                        "{$table}.properties->old_name",
                        "{$table}.properties->file_name",
                        "{$table}.properties->folder_name",
                        "{$table}.properties->name",
                        "{$table}.properties->new_doc_num",
                        "{$table}.properties->doc_num",
                        "{$table}.properties->file_doc_num",
                        "{$table}.properties->folder_doc_num",
                        "{$table}.properties->role_doc_num",
                        "{$table}.properties->user_doc_num",
                        "{$table}.properties->company_doc_num",
                        "{$table}.properties->source_doc_num",
                    ],
                    'dates' => ["{$table}.created_at"],
                    'date_text' => ["{$table}.created_at"],
                    'exists' => [
                        [
                            'table' => 'users',
                            'first' => 'users.id',
                            'second' => "{$table}.causer_id",
                            'where' => [["{$table}.causer_type", User::class]],
                            'columns' => ['users.name', 'users.doc_num', 'users.username', 'users.email'],
                        ],
                        [
                            'table' => 'companies',
                            'first' => 'companies.id',
                            'second' => "{$table}.company_id",
                            'columns' => ['companies.name', 'companies.doc_num'],
                        ],
                    ],
                ]);
            })
            ->editColumn('created_at', fn (Activity $activity): string => $this->text($this->row($activity)['date_time']))
            ->addColumn('causer_label', fn (Activity $activity): string => $this->stackedLabel($this->row($activity)['user']))
            ->addColumn('area_label', fn (Activity $activity): string => $this->badge($this->row($activity)['area'], 'primary'))
            ->addColumn('activity_label', fn (Activity $activity): string => $this->wrappedText($this->row($activity)['activity']))
            ->addColumn('result_label', fn (Activity $activity): string => $this->statusBadge($this->row($activity)))
            ->addColumn('record_label', fn (Activity $activity): string => $this->wrappedText($this->row($activity)['record']))
            ->addColumn('summary_label', fn (Activity $activity): string => $this->wrappedText($this->row($activity)['summary']))
            ->addColumn('actions', fn (Activity $activity): string => view('modules.auth.activity-logs.partials.actions', [
                'activity' => $activity,
                'canViewDetails' => $canViewDetails,
            ])->render())
            ->orderColumn('created_at', "{$table}.created_at $1")
            ->orderColumn('area_label', "COALESCE(NULLIF({$table}.module, ''), NULLIF({$table}.log_name, ''), NULLIF({$table}.action, '')) $1, {$table}.action $1")
            ->orderColumn('activity_label', "{$table}.action $1, {$table}.event $1, {$table}.description $1")
            ->orderColumn('result_label', $statusOrder['sql'].' $1, '.$table.'.created_at $1', $statusOrder['bindings'])
            ->orderColumn('causer_label', 'causer_name $1, causer_doc_num $1')
            ->orderColumn('record_label', function (Builder $query, string $direction) use ($table): void {
                foreach ($this->recordOrderColumns($table) as $column) {
                    $query->orderBy($column, $direction);
                }
            })
            ->orderColumn('summary_label', false)
            ->orderColumn('actions', false)
            ->removeColumn('id')
            ->removeColumn('properties')
            ->removeColumn('subject_id')
            ->removeColumn('subject_type')
            ->removeColumn('causer_id')
            ->removeColumn('causer_type')
            ->removeColumn('causer_name')
            ->removeColumn('causer_doc_num')
            ->removeColumn('company_id')
            ->removeColumn('company_name')
            ->removeColumn('company_doc_num')
            ->removeColumn('public_id')
            ->removeColumn('log_name')
            ->removeColumn('description')
            ->removeColumn('event')
            ->removeColumn('module')
            ->removeColumn('action')
            ->removeColumn('status')
            ->removeColumn('url')
            ->removeColumn('method')
            ->removeColumn('ip_address')
            ->removeColumn('updated_at')
            ->removeColumn('batch_uuid')
            ->rawColumns(['created_at', 'area_label', 'result_label', 'causer_label', 'activity_label', 'record_label', 'summary_label', 'actions'])
            ->toJson();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Activity $activity): array
    {
        $key = (string) ($activity->public_id ?? $activity->getKey() ?? spl_object_id($activity));

        return $this->rowCache[$key] ??= $this->humanizer->row($activity);
    }

    private function badge(mixed $value, string $class = 'secondary'): string
    {
        $value = $this->displayText($value);
        $class = $this->badgeClass($class);

        return $value === ''
            ? $this->emptyValue()
            : '<span class="badge badge-subtle-'.$class.'">'.e($value).'</span>';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function statusBadge(array $row): string
    {
        $value = $this->displayText($row['result'] ?? '');
        $class = $this->badgeClass($row['result_class'] ?? 'secondary');

        return $value === ''
            ? $this->emptyValue()
            : '<span class="badge badge-subtle-'.$class.'">'.e($value).'</span>';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function changesLabel(array $row): string
    {
        $changes = $this->displayText($row['changes'] ?? '');

        if ($changes !== '' || (bool) ($row['has_technical_fallback'] ?? false) || (bool) ($row['has_technical_details'] ?? false)) {
            return '<span class="badge badge-subtle-info">'.e(__('activity_logs.messages.details_available')).'</span>';
        }

        return $this->emptyValue();
    }

    private function stackedLabel(mixed $value): string
    {
        $value = $this->displayText($value);

        if ($value === '') {
            return $this->emptyValue();
        }

        $parts = array_values(array_filter(
            array_map(fn (string $part): string => trim($part), explode(' / ', $value, 2)),
            fn (string $part): bool => $part !== ''
        ));

        if (count($parts) < 2) {
            return $this->wrappedText($value, 'activity-log-stack-single');
        }

        return sprintf(
            '<div class="activity-log-stack" title="%s"><div class="fw-semibold text-900 activity-log-stack-main">%s</div><div class="small text-500 activity-log-stack-meta">%s</div></div>',
            e($value),
            e($parts[0]),
            e($parts[1])
        );
    }

    private function wrappedText(mixed $value, string $class = ''): string
    {
        $value = $this->displayText($value);

        if ($value === '') {
            return $this->emptyValue();
        }

        $classes = trim('activity-log-wrapped '.$class);

        return sprintf('<span class="%s" title="%s">%s</span>', e($classes), e($value), e($value));
    }

    private function text(mixed $value): string
    {
        $value = $this->displayText($value);

        return $value === '' ? $this->emptyValue() : e($value);
    }

    private function emptyValue(): string
    {
        return '<span class="text-500">&mdash;</span>';
    }

    private function badgeClass(mixed $class): string
    {
        $class = preg_replace('/[^A-Za-z0-9_-]/', '', trim((string) $class)) ?: 'secondary';

        return $class === '' ? 'secondary' : $class;
    }

    private function displayText(mixed $value): string
    {
        return $this->sanitizer->displayText($value);
    }

    /**
     * @return array{sql: string, bindings: list<string>}
     */
    private function statusRankOrderSql(string $column): array
    {
        return [
            'sql' => "CASE {$column}
                WHEN ? THEN 1
                WHEN ? THEN 2
                WHEN ? THEN 3
                WHEN ? THEN 4
                WHEN ? THEN 5
                WHEN ? THEN 5
                ELSE 99
            END",
            'bindings' => ['success', 'info', 'warning', 'blocked', 'failed', 'error'],
        ];
    }

    /**
     * @return list<string>
     */
    private function recordOrderColumns(string $table): array
    {
        return [
            "{$table}.properties->record->label",
            "{$table}.properties->record->doc_num",
            "{$table}.properties->new_role_name",
            "{$table}.properties->role_name",
            "{$table}.properties->new_user_name",
            "{$table}.properties->user_name",
            "{$table}.properties->new_company_name",
            "{$table}.properties->company_name",
            "{$table}.properties->source_company_name",
            "{$table}.properties->source_role_name",
            "{$table}.properties->source_user_name",
            "{$table}.properties->source_name",
            "{$table}.properties->new_name",
            "{$table}.properties->old_name",
            "{$table}.properties->file_name",
            "{$table}.properties->folder_name",
            "{$table}.properties->name",
            "{$table}.properties->new_doc_num",
            "{$table}.properties->doc_num",
            "{$table}.properties->file_doc_num",
            "{$table}.properties->folder_doc_num",
            "{$table}.properties->role_doc_num",
            "{$table}.properties->user_doc_num",
            "{$table}.properties->company_doc_num",
            "{$table}.properties->source_doc_num",
            "{$table}.subject_type",
            "{$table}.subject_id",
        ];
    }
}
