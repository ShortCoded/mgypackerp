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
            ->leftJoin('accounts as default_accounts', 'default_accounts.id', '=', 'cost_centers.default_account_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'cost_centers.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'cost_centers.updated_by')
            ->select([
                'cost_centers.*',
                'parent_cost_centers.cost_center_code as parent_code',
                'parent_cost_centers.name as parent_name',
                'default_accounts.doc_num as default_account_doc_num',
                'default_accounts.account_code as default_account_code',
                'default_accounts.name as default_account_name',
                'default_accounts.name_en as default_account_name_en',
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
                        'default_accounts.doc_num', 'default_accounts.account_code', 'default_accounts.name', 'default_accounts.name_en',
                        'created_users.name', 'updated_users.name',
                    ]]);
                }
            })
            ->addColumn('checkbox', fn (CostCenter $costCenter): string => view('modules.accounting.cost-centers.partials.checkbox', compact('costCenter'))->render())
            ->editColumn('doc_num', fn (CostCenter $costCenter): string => $canView ? '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.accounting.cost-centers.show', $costCenter->doc_num)).'">'.e($costCenter->doc_num).'</a>' : e($costCenter->doc_num))
            ->editColumn('cost_center_code', fn (CostCenter $costCenter): string => '<span class="dt-code-value" dir="ltr">'.e($costCenter->cost_center_code).'</span>')
            ->editColumn('name', fn (CostCenter $costCenter): string => $this->ellipsisText($costCenter->name))
            ->addColumn('parent', fn (CostCenter $costCenter): string => $this->ellipsisText($this->parentName($costCenter)))
            ->addColumn('default_account', fn (CostCenter $costCenter): string => $this->ellipsisText($this->defaultAccountName($costCenter)))
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
            ->orderColumn('default_account', 'default_accounts.account_code $1, default_accounts.name $1')
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

        return $query->forCompany($companyId);
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

        $defaultAccountDocNum = trim((string) $request->input('default_account_doc_num'));
        if ($defaultAccountDocNum !== '') {
            $query->where('default_accounts.doc_num', $defaultAccountDocNum);
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

    private function defaultAccountName(CostCenter $costCenter): string
    {
        return Account::codeNameLabelFor(
            $costCenter->getAttribute('default_account_code'),
            $costCenter->getAttribute('default_account_name'),
            $costCenter->getAttribute('default_account_name_en'),
        );
    }
}
