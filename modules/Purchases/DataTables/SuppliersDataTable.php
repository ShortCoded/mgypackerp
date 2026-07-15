<?php

namespace Modules\Purchases\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Purchases\Models\Supplier;
use Yajra\DataTables\Facades\DataTables;

class SuppliersDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $query = match ($this->trashFilter($request)) {
            'trashed' => Supplier::onlyTrashed(),
            'all' => Supplier::withTrashed(),
            default => Supplier::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'suppliers', $request);

        $query->leftJoin('accounts', 'accounts.id', '=', 'suppliers.account_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'suppliers.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'suppliers.updated_by')
            ->select([
                'suppliers.*',
                'accounts.account_code',
                'accounts.name as account_label',
                'accounts.name_en as account_label_en',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => ['suppliers.doc_num', 'suppliers.name', 'suppliers.phone', 'suppliers.mobile', 'suppliers.email', 'suppliers.tax_number', 'accounts.account_code', 'accounts.name'],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (Supplier $record): string => view('modules.finance.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (Supplier $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.purchases.suppliers.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->addColumn('account', fn (Supplier $record): string => $this->ellipsisText(Account::codeNameLabelFor($record->account_code, $record->account_label, $record->account_label_en)))
            ->editColumn('name', fn (Supplier $record): string => $this->ellipsisText($record->name))
            ->editColumn('phone', fn (Supplier $record): string => $this->phoneColumn($record->phone))
            ->editColumn('mobile', fn (Supplier $record): string => $this->phoneColumn($record->mobile))
            ->editColumn('email', fn (Supplier $record): string => $this->ellipsisText($record->email))
            ->editColumn('tax_number', fn (Supplier $record): string => $this->ellipsisText($record->tax_number))
            ->editColumn('status', fn (Supplier $record): string => '<span class="badge rounded-pill badge-subtle-'.($record->status === 'active' ? 'success' : 'secondary').'">'.e(__("business_partners.statuses.{$record->status}")).'</span>')
            ->editColumn('created_by', fn (Supplier $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (Supplier $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? __('common.empty_value')))
            ->editColumn('updated_by', fn (Supplier $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (Supplier $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? __('common.empty_value')))
            ->addColumn('actions', fn (Supplier $record): string => view('modules.finance.partials.actions', ['record' => $record, 'resource' => 'suppliers', 'routePrefix' => 'admin.purchases.suppliers'])->render())
            ->orderColumn('doc_num', 'suppliers.doc_number $1')
            ->orderColumn('name', 'suppliers.name $1')
            ->orderColumn('account', 'accounts.account_code $1')
            ->orderColumn('phone', 'suppliers.phone $1')
            ->orderColumn('mobile', 'suppliers.mobile $1')
            ->orderColumn('email', 'suppliers.email $1')
            ->orderColumn('tax_number', 'suppliers.tax_number $1')
            ->orderColumn('status', 'suppliers.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'suppliers.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'suppliers.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('account_id')
            ->removeColumn('account_group_id')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'account', 'name', 'phone', 'mobile', 'email', 'tax_number', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('suppliers.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function phoneColumn(mixed $phone): string
    {
        $html = trim(view('components.contact.phone-actions', [
            'phone' => $phone,
            'class' => 'dt-ellipsis-content',
        ])->render());

        return $html !== ''
            ? '<span dir="ltr">'.$html.'</span>'
            : e(__('common.empty_value'));
    }
}
