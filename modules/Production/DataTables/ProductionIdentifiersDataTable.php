<?php

namespace Modules\Production\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Production\Models\ProductionIdentifier;
use Yajra\DataTables\Facades\DataTables;

class ProductionIdentifiersDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $query = $this->baseQuery($request)
            ->leftJoin('production_identifiers as parent_identifiers', 'parent_identifiers.id', '=', 'production_identifiers.parent_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'production_identifiers.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'production_identifiers.updated_by')
            ->select([
                'production_identifiers.*',
                'parent_identifiers.doc_num as parent_doc_num',
                'parent_identifiers.name as parent_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        $canView = (bool) $request->user()?->can('production.identifiers.view');
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => [
                        'production_identifiers.doc_num', 'production_identifiers.name',
                        'parent_identifiers.doc_num', 'parent_identifiers.name',
                        'created_users.name', 'updated_users.name',
                    ]]);
                }
            })
            ->addColumn('checkbox', fn (ProductionIdentifier $identifier): string => view('modules.production.identifiers.partials.checkbox', compact('identifier'))->render())
            ->editColumn('doc_num', fn (ProductionIdentifier $identifier): string => $canView ? '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.production.identifiers.show', $identifier->doc_num)).'">'.e($identifier->doc_num).'</a>' : e($identifier->doc_num))
            ->editColumn('name', fn (ProductionIdentifier $identifier): string => $this->ellipsisText($identifier->name))
            ->addColumn('parent', fn (ProductionIdentifier $identifier): string => $this->ellipsisText($this->parentName($identifier)))
            ->editColumn('is_group', fn (ProductionIdentifier $identifier): string => $this->booleanBadge((bool) $identifier->is_group))
            ->editColumn('status', fn (ProductionIdentifier $identifier): string => $this->badge(__("production_identifiers.statuses.{$identifier->status}"), $identifier->status === 'active' ? 'success' : 'secondary'))
            ->addColumn('created_by', fn (ProductionIdentifier $identifier): string => $this->ellipsisText($identifier->created_by_name))
            ->editColumn('created_at', fn (ProductionIdentifier $identifier): string => $this->plainText($identifier->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (ProductionIdentifier $identifier): string => $this->ellipsisText($identifier->updated_by_name))
            ->editColumn('updated_at', fn (ProductionIdentifier $identifier): string => $this->plainText($identifier->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (ProductionIdentifier $identifier): string => view('modules.production.identifiers.partials.actions', compact('identifier'))->render())
            ->orderColumn('doc_num', 'production_identifiers.doc_number $1')
            ->orderColumn('name', 'production_identifiers.name $1')
            ->orderColumn('parent', 'parent_identifiers.doc_number $1, parent_identifiers.name $1')
            ->orderColumn('is_group', 'production_identifiers.is_group $1')
            ->orderColumn('status', 'production_identifiers.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'production_identifiers.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'production_identifiers.updated_at $1')
            ->removeColumn('id')
            ->rawColumns([
                'checkbox',
                'doc_num',
                'name',
                'parent',
                'is_group',
                'status',
                'created_by',
                'updated_by',
                'actions',
            ])
            ->toJson();
    }

    private function baseQuery(Request $request)
    {
        $companyId = $this->companies->currentCompanyId($request);
        $query = match (! $request->user()?->can('production.identifiers.view_trashed') ? 'active' : $request->string('trash_filter')->toString()) {
            'trashed' => ProductionIdentifier::onlyTrashed(),
            'all' => ProductionIdentifier::withTrashed(),
            default => ProductionIdentifier::query(),
        };

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->forCompany($companyId);
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    private function booleanBadge(bool $value): string
    {
        return $this->badge($value ? __('common.actions.yes') : __('common.actions.no'), $value ? 'success' : 'secondary');
    }

    private function parentName(ProductionIdentifier $identifier): string
    {
        return ProductionIdentifier::documentNameLabelFor(
            $identifier->getAttribute('parent_doc_num'),
            $identifier->getAttribute('parent_name'),
        );
    }
}
