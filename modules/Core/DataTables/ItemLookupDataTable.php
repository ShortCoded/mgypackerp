<?php

namespace Modules\Core\DataTables;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\ItemLookup;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\ItemLookupDefinition;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class ItemLookupDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly OperatingCompanyContextService $companyContext,
        private readonly NumericFormatService $numbers,
    ) {}

    public function json(Request $request, ItemLookupDefinition $definition): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $trashFilter = $this->trashFilter($request, $definition);
        $canView = (bool) $request->user()?->can($definition->permission('view'));
        $query = $this->baseQuery($definition, $trashFilter)
            ->leftJoin('users as created_users', 'created_users.id', '=', "{$definition->table}.created_by")
            ->leftJoin('users as updated_users', 'updated_users.id', '=', "{$definition->table}.updated_by");

        if ($this->supportsEquivalence($definition)) {
            $query->leftJoin('item_units as equivalent_units', 'equivalent_units.id', '=', "{$definition->table}.equivalent_unit_id");
        }

        $select = [
            "{$definition->table}.name",
            "{$definition->table}.status",
            "{$definition->table}.notes",
            "{$definition->table}.doc_number",
            "{$definition->table}.doc_num",
            "{$definition->table}.created_at",
            "{$definition->table}.updated_at",
            "{$definition->table}.deleted_at",
            'created_users.name as created_by_name',
            'updated_users.name as updated_by_name',
        ];

        if ($this->supportsEquivalence($definition)) {
            $select[] = "{$definition->table}.equivalent_value";
            $select[] = "{$definition->table}.equivalent_unit_id";
            $select[] = 'equivalent_units.doc_num as equivalent_unit_doc_num';
            $select[] = 'equivalent_units.name as equivalent_unit_name';
        }

        $query->select($select);

        $dataTable = DataTables::eloquent($query)
            ->filter(function ($query) use ($request, $definition): void {
                $search = $request->input('search.value');
                $terms = $this->searchService->terms(is_string($search) ? $search : null);

                if ($terms === []) {
                    return;
                }

                $this->searchService->applyMultiTermSearch($query, $terms, $this->searchColumns($definition));
            })
            ->addColumn('checkbox', fn (ItemLookup $record): string => view('modules.core.item-lookups.partials.checkbox', [
                'definition' => $definition,
                'record' => $record,
            ])->render())
            ->editColumn('doc_num', fn (ItemLookup $record): string => $this->docNumColumn($definition, $record, $canView))
            ->editColumn('name', fn (ItemLookup $record): string => $this->ellipsisText($record->name))
            ->editColumn('status', fn (ItemLookup $record): string => $this->statusBadge((string) $record->status))
            ->editColumn('notes', fn (ItemLookup $record): string => $this->ellipsisText($record->notes))
            ->addColumn('created_by', fn (ItemLookup $record): string => $this->ellipsisText($record->created_by_name))
            ->editColumn('created_at', fn (ItemLookup $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (ItemLookup $record): string => $this->ellipsisText($record->updated_by_name))
            ->editColumn('updated_at', fn (ItemLookup $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''));

        if ($this->supportsEquivalence($definition)) {
            $dataTable
                ->addColumn('equivalent_to', fn (ItemLookup $record): string => $this->equivalentToColumn($record))
                ->orderColumn('equivalent_to', 'equivalent_units.name $1');
        }

        return $dataTable
            ->addColumn('actions', fn (ItemLookup $record): string => view('modules.core.item-lookups.partials.actions', [
                'definition' => $definition,
                'record' => $record,
            ])->render())
            ->orderColumn('doc_num', "{$definition->table}.doc_number $1")
            ->orderColumn('name', "{$definition->table}.name $1")
            ->orderColumn('status', "{$definition->table}.status $1")
            ->orderColumn('notes', "{$definition->table}.notes $1")
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', "{$definition->table}.created_at $1")
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', "{$definition->table}.updated_at $1")
            ->removeColumn('id')
            ->rawColumns($this->rawColumns($definition))
            ->toJson();
    }

    private function baseQuery(ItemLookupDefinition $definition, string $trashFilter): Builder
    {
        $query = $this->companyContext->applyCompanyScope(
            $definition->modelClass::query(),
            $definition->table,
        );

        return match ($trashFilter) {
            'trashed' => $query->onlyTrashed(),
            'all' => $query->withTrashed(),
            default => $query,
        };
    }

    private function trashFilter(Request $request, ItemLookupDefinition $definition): string
    {
        if (! $request->user()?->can($definition->permission('view_trashed'))) {
            return 'active';
        }

        $filter = $request->string('trash_filter')->trim()->toString();

        return in_array($filter, ['active', 'trashed', 'all'], true) ? $filter : 'active';
    }

    private function docNumColumn(ItemLookupDefinition $definition, ItemLookup $record, bool $canView): string
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

    private function statusBadge(string $status): string
    {
        $class = $status === 'active' ? 'success' : 'secondary';

        return sprintf(
            '<span class="badge rounded-pill badge-subtle-%s">%s</span>',
            e($class),
            e(__("item_lookups.statuses.{$status}")),
        );
    }

    private function equivalentToColumn(ItemLookup $record): string
    {
        if ($record->equivalent_value === null || ! $record->equivalent_unit_name) {
            return '';
        }

        return $this->ellipsisText(__('item_units.equivalence_text', [
            'unit' => (string) $record->name,
            'value' => $this->displayDecimal($record->equivalent_value),
            'equivalent_unit' => (string) $record->equivalent_unit_name,
        ]));
    }

    private function displayDecimal(mixed $value): string
    {
        return $this->numbers->format($value);
    }

    private function supportsEquivalence(ItemLookupDefinition $definition): bool
    {
        return $definition->key === 'item_units';
    }

    /**
     * @return list<string>
     */
    private function rawColumns(ItemLookupDefinition $definition): array
    {
        $columns = ['checkbox', 'doc_num', 'name', 'status', 'notes', 'created_by', 'updated_by', 'actions'];

        if ($this->supportsEquivalence($definition)) {
            $columns[] = 'equivalent_to';
        }

        return $columns;
    }

    /**
     * @return array{text: list<string>, dates: list<string>, date_text: list<string>}
     */
    private function searchColumns(ItemLookupDefinition $definition): array
    {
        return [
            'text' => [
                "{$definition->table}.doc_num",
                "{$definition->table}.name",
                "{$definition->table}.status",
                "{$definition->table}.notes",
                'created_users.name',
                'updated_users.name',
                ...$this->equivalenceSearchColumns($definition),
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

    /**
     * @return list<string>
     */
    private function equivalenceSearchColumns(ItemLookupDefinition $definition): array
    {
        if (! $this->supportsEquivalence($definition)) {
            return [];
        }

        return [
            'equivalent_units.doc_num',
            'equivalent_units.name',
        ];
    }
}
