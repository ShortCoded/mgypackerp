<?php

namespace Modules\Accounting\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class CostCentersDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $query = $this->baseQuery($request)
            ->leftJoin('cost_centers as parent_cost_centers', 'parent_cost_centers.id', '=', 'cost_centers.parent_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'cost_centers.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'cost_centers.updated_by')
            ->select([
                'cost_centers.*',
                'parent_cost_centers.cost_center_code as parent_code',
                'parent_cost_centers.name as parent_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);
        $query = $this->applyReportFilters($query, $request);

        $canView = (bool) $request->user()?->can('cost_centers.view');
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => [
                        'cost_centers.doc_num', 'cost_centers.cost_center_code', 'cost_centers.name',
                        'parent_cost_centers.cost_center_code', 'parent_cost_centers.name',
                        'created_users.name', 'updated_users.name',
                    ], 'exists' => [[
                        'table' => 'cost_center_accounts',
                        'first' => 'cost_center_accounts.cost_center_id',
                        'second' => 'cost_centers.id',
                        'join' => [
                            'table' => 'accounts as linked_account_search',
                            'first' => 'linked_account_search.id',
                            'second' => 'cost_center_accounts.account_id',
                        ],
                        'columns' => [
                            'linked_account_search.doc_num',
                            'linked_account_search.account_code',
                            'linked_account_search.name',
                            'linked_account_search.name_en',
                        ],
                    ]]]);
                }
            })
            ->addColumn('checkbox', fn (CostCenter $costCenter): string => view('modules.accounting.cost-centers.partials.checkbox', compact('costCenter'))->render())
            ->editColumn('doc_num', fn (CostCenter $costCenter): string => $canView ? '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.accounting.cost-centers.show', $costCenter->doc_num)).'">'.e($costCenter->doc_num).'</a>' : e($costCenter->doc_num))
            ->editColumn('cost_center_code', fn (CostCenter $costCenter): string => '<span class="dt-code-value" dir="ltr">'.e($costCenter->cost_center_code).'</span>')
            ->editColumn('name', fn (CostCenter $costCenter): string => $this->ellipsisText($costCenter->name))
            ->addColumn('parent', fn (CostCenter $costCenter): string => $this->ellipsisText($this->parentName($costCenter)))
            ->addColumn('linked_accounts', fn (CostCenter $costCenter): string => $this->ellipsisText($this->linkedAccountNames($costCenter)))
            ->addColumn('default_account', fn (CostCenter $costCenter): string => $this->ellipsisText($this->linkedAccountNames($costCenter)))
            ->editColumn('is_group', fn (CostCenter $costCenter): string => $this->booleanBadge((bool) $costCenter->is_group))
            ->editColumn('status', fn (CostCenter $costCenter): string => $this->badge(__("cost_centers.statuses.{$costCenter->status}"), $costCenter->status === 'active' ? 'success' : 'secondary'))
            ->addColumn('created_by', fn (CostCenter $costCenter): string => $this->ellipsisText($costCenter->created_by_name))
            ->editColumn('created_at', fn (CostCenter $costCenter): string => $this->plainText($costCenter->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (CostCenter $costCenter): string => $this->ellipsisText($costCenter->updated_by_name))
            ->editColumn('updated_at', fn (CostCenter $costCenter): string => $this->plainText($costCenter->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (CostCenter $costCenter): string => view('modules.accounting.cost-centers.partials.actions', compact('costCenter'))->render())
            ->orderColumn('doc_num', 'cost_centers.doc_number $1')
            ->orderColumn('cost_center_code', 'cost_centers.cost_center_code $1')
            ->orderColumn('name', 'cost_centers.name $1')
            ->orderColumn('parent', 'parent_cost_centers.cost_center_code $1, parent_cost_centers.name $1')
            ->orderColumn('linked_accounts', false)
            ->orderColumn('default_account', false)
            ->orderColumn('is_group', 'cost_centers.is_group $1')
            ->orderColumn('status', 'cost_centers.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'cost_centers.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'cost_centers.updated_at $1')
            ->removeColumn('id')
            ->rawColumns([
                'checkbox',
                'doc_num',
                'cost_center_code',
                'name',
                'parent',
                'linked_accounts',
                'default_account',
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
        $query = match (! $request->user()?->can('cost_centers.view_trashed') ? 'active' : $request->string('trash_filter')->toString()) {
            'trashed' => CostCenter::onlyTrashed(),
            'all' => CostCenter::withTrashed(),
            default => CostCenter::query(),
        };

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->forCompany($companyId)
            ->with(['accounts' => fn ($accountQuery) => $accountQuery->orderByRaw('LENGTH(accounts.account_code), accounts.account_code')]);
    }

    private function applyReportFilters($query, Request $request)
    {
        $search = trim((string) $request->input('cost_center_search'));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereRaw('LOWER(cost_centers.cost_center_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(cost_centers.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(parent_cost_centers.cost_center_code, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(parent_cost_centers.name, \'\')) LIKE ?', [$like]);
            });
        }

        $status = trim((string) $request->input('status'));
        if ($status !== '') {
            $query->where('cost_centers.status', $status);
        }

        $hierarchy = trim((string) $request->input('hierarchy'));
        if ($hierarchy === 'root') {
            $query->whereNull('cost_centers.parent_id');
        } elseif ($hierarchy === 'children') {
            $query->whereNotNull('cost_centers.parent_id');
        }

        $linkedAccountDocNum = trim((string) $request->input(
            'linked_account_doc_num',
            $request->input('default_account_doc_num'),
        ));
        if ($linkedAccountDocNum !== '') {
            $query->whereHas('accounts', fn ($accountQuery) => $accountQuery->where('accounts.doc_num', $linkedAccountDocNum));
        }

        return $query;
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    private function booleanBadge(bool $value): string
    {
        return $this->badge($value ? __('common.actions.yes') : __('common.actions.no'), $value ? 'success' : 'secondary');
    }

    private function parentName(CostCenter $costCenter): string
    {
        return CostCenter::codeNameLabelFor(
            $costCenter->getAttribute('parent_code'),
            $costCenter->getAttribute('parent_name'),
        );
    }

    private function linkedAccountNames(CostCenter $costCenter): string
    {
        return $costCenter->accounts
            ->map(fn (Account $account): string => $account->codeNameLabel())
            ->implode('، ');
    }
}
