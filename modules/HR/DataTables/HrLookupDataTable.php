<?php

namespace Modules\HR\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\SettingService;
use Modules\HR\Models\HrLookupModel;
use Modules\HR\Services\HrLookupDefinition;
use Yajra\DataTables\Facades\DataTables;

abstract class HrLookupDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
    ) {}

    abstract protected function definition(): HrLookupDefinition;

    public function json(Request $request): JsonResponse
    {
        $definition = $this->definition();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $trashFilter = $this->trashFilter($request, $definition);
        $canView = (bool) $request->user()?->can($definition->permission('view'));
        $query = $this->baseQuery($definition, $trashFilter)
            ->leftJoin('users as created_users', 'created_users.id', '=', "{$definition->table}.created_by")
            ->leftJoin('users as updated_users', 'updated_users.id', '=', "{$definition->table}.updated_by")
            ->select([
                "{$definition->table}.name",
                "{$definition->table}.notes",
                "{$definition->table}.doc_number",
                "{$definition->table}.doc_num",
                "{$definition->table}.created_at",
                "{$definition->table}.updated_at",
                "{$definition->table}.deleted_at",
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $definition): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $this->searchColumns($definition));
            })
            ->addColumn('checkbox', fn (HrLookupModel $record): string => view('modules.hr.lookups.partials.checkbox', [
                'definition' => $definition,
                'record' => $record,
            ])->render())
            ->editColumn('doc_num', fn (HrLookupModel $record): string => $this->docNumColumn($definition, $record, $canView))
            ->editColumn('name', fn (HrLookupModel $record): string => $this->ellipsisText($record->name))
            ->editColumn('notes', fn (HrLookupModel $record): string => $this->ellipsisText($record->notes))
            ->addColumn('created_by', fn (HrLookupModel $record): string => $this->ellipsisText($record->created_by_name))
            ->editColumn('created_at', fn (HrLookupModel $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (HrLookupModel $record): string => $this->ellipsisText($record->updated_by_name))
            ->editColumn('updated_at', fn (HrLookupModel $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (HrLookupModel $record): string => view('modules.hr.lookups.partials.actions', [
                'definition' => $definition,
                'record' => $record,
            ])->render())
            ->orderColumn('doc_num', "{$definition->table}.doc_number $1")
            ->orderColumn('name', "{$definition->table}.name $1")
            ->orderColumn('notes', "{$definition->table}.notes $1")
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', "{$definition->table}.created_at $1")
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', "{$definition->table}.updated_at $1")
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'name', 'notes', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function baseQuery(HrLookupDefinition $definition, string $trashFilter): Builder
    {
        $query = $definition->modelClass::query();

        return match ($trashFilter) {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };
    }

    private function trashFilter(Request $request, HrLookupDefinition $definition): string
    {
        if (! $request->user()?->can($definition->permission('view_trashed'))) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(HrLookupDefinition $definition, HrLookupModel $record, bool $canView): string
    {
        if (! $canView) {
            return sprintf(
                '<span class="fw-semibold text-700">%s</span>',
                e((string) $record->doc_num),
            );
        }

        return sprintf(
            '<a class="fw-semibold" href="%s">%s</a>',
            e(route($definition->route('show'), $record->doc_num)),
            e((string) $record->doc_num),
        );
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(HrLookupDefinition $definition): array
    {
        return [
            'text' => [
                "{$definition->table}.doc_num",
                "{$definition->table}.name",
                "{$definition->table}.notes",
                'created_users.name',
                'updated_users.name',
            ],
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
