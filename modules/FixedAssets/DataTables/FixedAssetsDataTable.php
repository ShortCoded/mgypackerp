<?php

namespace Modules\FixedAssets\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\CostCenter;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\FixedAssets\Models\FixedAsset;
use Yajra\DataTables\Facades\DataTables;

class FixedAssetsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
        private readonly NumericFormatService $numbers,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $query = match ($this->trashFilter($request)) {
            'trashed' => FixedAsset::onlyTrashed(),
            'all' => FixedAsset::withTrashed(),
            default => FixedAsset::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'fixed_assets', $request);

        $query
            ->leftJoin('accounts', 'accounts.id', '=', 'fixed_assets.account_id')
            ->leftJoin('accounts as category_accounts', 'category_accounts.id', '=', 'fixed_assets.asset_group_account_id')
            ->leftJoin('branches', 'branches.id', '=', 'fixed_assets.branch_id')
            ->leftJoin('cost_centers', 'cost_centers.id', '=', 'fixed_assets.cost_center_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'fixed_assets.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'fixed_assets.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'fixed_assets.updated_by')
            ->leftJoin('users as deleted_users', 'deleted_users.id', '=', 'fixed_assets.deleted_by')
            ->select([
                'fixed_assets.*',
                'accounts.account_code',
                'accounts.name as account_label',
                'accounts.name_en as account_label_en',
                'category_accounts.account_code as category_account_code',
                'category_accounts.name as category_account_label',
                'category_accounts.name_en as category_account_label_en',
                'branches.name as branch_name',
                'cost_centers.cost_center_code',
                'cost_centers.name as cost_center_name',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'created_users.doc_num as created_by_doc_num',
                'updated_users.name as updated_by_name',
                'updated_users.doc_num as updated_by_doc_num',
                'deleted_users.name as deleted_by_name',
                'deleted_users.doc_num as deleted_by_doc_num',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'fixed_assets.doc_num',
                            'fixed_assets.asset_name',
                            'fixed_assets.entry_type',
                            'fixed_assets.serial_number',
                            'accounts.account_code',
                            'accounts.name',
                            'branches.name',
                            'cost_centers.cost_center_code',
                            'cost_centers.name',
                            'created_users.name',
                            'created_users.doc_num',
                            'updated_users.name',
                            'updated_users.doc_num',
                            'deleted_users.name',
                            'deleted_users.doc_num',
                        ],
                        'dates' => [
                            'fixed_assets.created_at',
                            'fixed_assets.updated_at',
                            'fixed_assets.deleted_at',
                        ],
                        'date_text' => [
                            'fixed_assets.created_at',
                            'fixed_assets.updated_at',
                            'fixed_assets.deleted_at',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (FixedAsset $record): string => view('modules.finance.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (FixedAsset $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.fixed-assets.assets.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('asset_name', fn (FixedAsset $record): string => $this->ellipsisText($record->asset_name))
            ->editColumn('entry_type', fn (FixedAsset $record): string => $this->plainText(__("fixed_assets.entry_types.{$record->entry_type}")))
            ->addColumn('asset_category', fn (FixedAsset $record): string => $this->ellipsisText(Account::codeNameLabelFor($record->category_account_code, $record->category_account_label, $record->category_account_label_en)))
            ->addColumn('branch', fn (FixedAsset $record): string => $this->ellipsisText($record->branch_name))
            ->addColumn('cost_center', fn (FixedAsset $record): string => $this->ellipsisText(CostCenter::codeNameLabelFor($record->cost_center_code, $record->cost_center_name)))
            ->editColumn('purchase_value', fn (FixedAsset $record): string => $this->plainText($this->moneyText($record->purchase_value, $record->currency_code)))
            ->addColumn('currency', fn (FixedAsset $record): string => $this->plainText(trim(implode(' / ', array_filter([$record->currency_code, $record->currency_name])))))
            ->editColumn('previous_depreciation', fn (FixedAsset $record): string => $this->plainText($this->moneyText($record->previous_depreciation, $record->currency_code)))
            ->editColumn('net_value', fn (FixedAsset $record): string => $this->plainText($this->moneyText($record->net_value, $record->currency_code)))
            ->editColumn('is_depreciable', fn (FixedAsset $record): string => '<span class="badge rounded-pill badge-subtle-'.($record->is_depreciable ? 'success' : 'secondary').'">'.e(__('fixed_assets.booleans.'.($record->is_depreciable ? 'yes' : 'no'))).'</span>')
            ->editColumn('status', fn (FixedAsset $record): string => '<span class="badge rounded-pill badge-subtle-'.($record->status === 'active' ? 'success' : 'secondary').'">'.e(__("fixed_assets.statuses.{$record->status}")).'</span>')
            ->editColumn('created_by', fn (FixedAsset $record): string => $this->ellipsisText($this->auditUserLabel($record->created_by_name, $record->created_by_doc_num)))
            ->editColumn('created_at', fn (FixedAsset $record): string => $this->plainText($this->dateTimeText($record->created_at, $dateTimeFormat)))
            ->editColumn('updated_by', fn (FixedAsset $record): string => $this->ellipsisText($this->auditUserLabel($record->updated_by_name, $record->updated_by_doc_num)))
            ->editColumn('updated_at', fn (FixedAsset $record): string => $this->plainText($this->dateTimeText($record->updated_at, $dateTimeFormat)))
            ->editColumn('deleted_by', fn (FixedAsset $record): string => $this->ellipsisText($this->auditUserLabel($record->deleted_by_name, $record->deleted_by_doc_num)))
            ->editColumn('deleted_at', fn (FixedAsset $record): string => $this->plainText($this->dateTimeText($record->deleted_at, $dateTimeFormat)))
            ->addColumn('actions', fn (FixedAsset $record): string => view('modules.finance.partials.actions', ['record' => $record, 'resource' => 'fixed_assets', 'routePrefix' => 'admin.fixed-assets.assets'])->render())
            ->orderColumn('doc_num', 'fixed_assets.doc_number $1')
            ->orderColumn('asset_name', 'fixed_assets.asset_name $1')
            ->orderColumn('entry_type', 'fixed_assets.entry_type $1')
            ->orderColumn('asset_category', 'category_accounts.account_code $1')
            ->orderColumn('branch', 'branches.name $1')
            ->orderColumn('cost_center', 'cost_centers.cost_center_code $1')
            ->orderColumn('purchase_value', 'fixed_assets.purchase_value $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('previous_depreciation', 'fixed_assets.previous_depreciation $1')
            ->orderColumn('net_value', 'fixed_assets.net_value $1')
            ->orderColumn('is_depreciable', 'fixed_assets.is_depreciable $1')
            ->orderColumn('status', 'fixed_assets.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'fixed_assets.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'fixed_assets.updated_at $1')
            ->orderColumn('deleted_by', 'deleted_users.name $1')
            ->orderColumn('deleted_at', 'fixed_assets.deleted_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('account_id')
            ->removeColumn('asset_group_account_id')
            ->removeColumn('credit_account_id')
            ->removeColumn('cost_center_id')
            ->removeColumn('currency_id')
            ->removeColumn('branch_id')
            ->removeColumn('branch_hall_id')
            ->removeColumn('image_path')
            ->removeColumn('salvage_value')
            ->removeColumn('previous_depreciation_until_date')
            ->removeColumn('depreciation_start_date')
            ->removeColumn('depreciation_method')
            ->removeColumn('expected_usage_units')
            ->removeColumn('period_id')
            ->removeColumn('restored_by')
            ->removeColumn('account_code')
            ->removeColumn('account_label')
            ->removeColumn('account_label_en')
            ->removeColumn('category_account_code')
            ->removeColumn('category_account_label')
            ->removeColumn('category_account_label_en')
            ->removeColumn('branch_name')
            ->removeColumn('cost_center_code')
            ->removeColumn('cost_center_name')
            ->removeColumn('currency_code')
            ->removeColumn('currency_name')
            ->removeColumn('created_by_name')
            ->removeColumn('created_by_doc_num')
            ->removeColumn('updated_by_name')
            ->removeColumn('updated_by_doc_num')
            ->removeColumn('deleted_by_name')
            ->removeColumn('deleted_by_doc_num')
            ->rawColumns(['checkbox', 'doc_num', 'asset_name', 'asset_category', 'branch', 'cost_center', 'created_by', 'updated_by', 'deleted_by', 'is_depreciable', 'status', 'actions'])
            ->toJson();
    }

    private function moneyText(mixed $value, mixed $currencyCode): string
    {
        $amount = $this->numbers->format($value);

        if ($amount === '') {
            return __('common.empty_value');
        }

        $currency = trim((string) $currencyCode);

        return $currency === '' ? $amount : $amount.' '.$currency;
    }

    private function auditUserLabel(mixed $name, mixed $docNum): string
    {
        $label = trim(implode(' / ', array_filter([
            trim((string) $name),
            trim((string) $docNum),
        ])));

        return $label === '' ? __('common.empty_value') : $label;
    }

    private function dateTimeText(mixed $date, string $dateTimeFormat): string
    {
        return $date?->format($dateTimeFormat) ?? __('common.empty_value');
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('fixed_assets.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }
}
