<?php

namespace Modules\HR\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\HR\Models\HrFoundationModel;
use Modules\HR\Services\HrFoundationDefinition;
use Modules\HR\Services\HrFoundationRegistry;
use Yajra\DataTables\Facades\DataTables;

class HrFoundationDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly NumericFormatService $numericFormatter,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $definition = app(HrFoundationRegistry::class)->fromRouteName($request->route()?->getName());
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $trashFilter = $this->trashFilter($request, $definition);
        $canView = (bool) $request->user()?->can($definition->permission('view'));
        $query = $this->baseQuery($definition, $trashFilter)
            ->leftJoin('users as created_users', 'created_users.id', '=', "{$definition->table}.created_by")
            ->leftJoin('users as updated_users', 'updated_users.id', '=', "{$definition->table}.updated_by")
            ->select($this->selectColumns($definition));

        $dataTable = DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $definition): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $this->searchColumns($definition));
            })
            ->addColumn('checkbox', fn (HrFoundationModel $record): string => view('modules.hr.foundation.partials.checkbox', [
                'definition' => $definition,
                'record' => $record,
            ])->render())
            ->editColumn('doc_num', fn (HrFoundationModel $record): string => $this->docNumColumn($definition, $record, $canView))
            ->editColumn('name', fn (HrFoundationModel $record): string => $this->ellipsisText($record->name))
            ->editColumn('status', fn (HrFoundationModel $record): string => $this->statusBadge((string) $record->status))
            ->addColumn('created_by', fn (HrFoundationModel $record): string => $this->ellipsisText($record->created_by_name))
            ->editColumn('created_at', fn (HrFoundationModel $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (HrFoundationModel $record): string => $this->ellipsisText($record->updated_by_name))
            ->editColumn('updated_at', fn (HrFoundationModel $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (HrFoundationModel $record): string => view('modules.hr.foundation.partials.actions', [
                'definition' => $definition,
                'record' => $record,
            ])->render())
            ->orderColumn('doc_num', "{$definition->table}.doc_number $1")
            ->orderColumn('name', "{$definition->table}.name $1")
            ->orderColumn('status', "{$definition->table}.status $1")
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', "{$definition->table}.created_at $1")
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', "{$definition->table}.updated_at $1");

        foreach ($definition->tableColumns as $column) {
            if (! in_array($column['type'] ?? null, ['number', 'decimal'], true)) {
                continue;
            }

            $columnName = (string) $column['name'];
            $dataTable
                ->editColumn(
                    $columnName,
                    fn (HrFoundationModel $record): string => $this->plainText(
                        $this->numericFormatter->format($record->getAttribute($columnName))
                    ),
                )
                ->orderColumn($columnName, "{$definition->table}.{$columnName} $1");
        }

        return $dataTable
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    /**
     * @return list<string>
     */
    private function selectColumns(HrFoundationDefinition $definition): array
    {
        $columns = [
            "{$definition->table}.doc_number",
            "{$definition->table}.doc_num",
            "{$definition->table}.name",
            "{$definition->table}.status",
            "{$definition->table}.notes",
            "{$definition->table}.created_at",
            "{$definition->table}.updated_at",
            "{$definition->table}.deleted_at",
            'created_users.name as created_by_name',
            'updated_users.name as updated_by_name',
        ];

        foreach ($definition->fields as $field) {
            if (($field['type'] ?? 'text') === 'relation') {
                continue;
            }

            $columns[] = "{$definition->table}.".(string) ($field['column'] ?? $field['name']);
        }

        return array_values(array_unique($columns));
    }

    /**
     * @return Builder<HrFoundationModel>
     */
    private function baseQuery(HrFoundationDefinition $definition, string $trashFilter): Builder
    {
        $query = $definition->modelClass::query();

        if ($definition->companyScoped) {
            app(OperatingCompanyContextService::class)->applyCompanyScope($query, $definition->table);
        }

        return match ($trashFilter) {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };
    }

    private function trashFilter(Request $request, HrFoundationDefinition $definition): string
    {
        if (! $request->user()?->can($definition->permission('view_trashed'))) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(HrFoundationDefinition $definition, HrFoundationModel $record, bool $canView): string
    {
        if (! $canView) {
            return sprintf('<span class="fw-semibold text-700">%s</span>', e((string) $record->doc_num));
        }

        return sprintf(
            '<a class="fw-semibold" href="%s">%s</a>',
            e(route($definition->route('show'), $record->doc_num)),
            e((string) $record->doc_num),
        );
    }

    private function statusBadge(string $status): string
    {
        $color = $status === 'active' ? 'success' : 'secondary';

        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e(__("hr.statuses.{$status}")).'</span>';
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(HrFoundationDefinition $definition): array
    {
        $textColumns = [
            "{$definition->table}.doc_num",
            "{$definition->table}.name",
            "{$definition->table}.status",
            "{$definition->table}.notes",
            'created_users.name',
            'updated_users.name',
        ];

        foreach ($definition->fields as $field) {
            $column = (string) ($field['column'] ?? $field['name']);

            if (($field['type'] ?? 'text') === 'relation') {
                continue;
            }

            $textColumns[] = "{$definition->table}.{$column}";
        }

        return [
            'text' => array_values(array_unique($textColumns)),
            'dates' => [
                "{$definition->table}.created_at",
                "{$definition->table}.updated_at",
            ],
            'date_text' => [
                "{$definition->table}.created_at",
                "{$definition->table}.updated_at",
            ],
        ];
    }
}
