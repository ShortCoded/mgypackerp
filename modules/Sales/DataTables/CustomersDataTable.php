<?php

namespace Modules\Sales\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\ScreenDataVisibilityService;
use Modules\Core\Services\SettingService;
use Modules\Sales\Models\Customer;
use Yajra\DataTables\Facades\DataTables;

class CustomersDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
        private readonly ScreenDataVisibilityService $visibility,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $query = match ($this->trashFilter($request)) {
            'trashed' => Customer::onlyTrashed(),
            'all' => Customer::withTrashed(),
            default => Customer::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'customers', $request);
        if ($request->user()) {
            $query = $this->visibility->applyToEloquent($query, $request->user(), 'customers');
        }

        $query->leftJoin('accounts', 'accounts.id', '=', 'customers.account_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'customers.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'customers.updated_by')
            ->select([
                'customers.*',
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
                        'text' => ['customers.doc_num', 'customers.name', 'customers.phone', 'customers.mobile', 'customers.email', 'customers.tax_number', 'accounts.account_code', 'accounts.name'],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (Customer $record): string => view('modules.finance.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (Customer $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.sales.customers.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->addColumn('account', fn (Customer $record): string => $this->ellipsisText(Account::codeNameLabelFor($record->account_code, $record->account_label, $record->account_label_en)))
            ->editColumn('name', fn (Customer $record): string => $this->ellipsisText($record->name))
            ->editColumn('phone', fn (Customer $record): string => $this->phoneColumn($record->phone))
            ->editColumn('mobile', fn (Customer $record): string => $this->phoneColumn($record->mobile))
            ->editColumn('email', fn (Customer $record): string => $this->ellipsisText($record->email))
            ->editColumn('tax_number', fn (Customer $record): string => $this->ellipsisText($record->tax_number))
            ->editColumn('status', fn (Customer $record): string => '<span class="badge rounded-pill badge-subtle-'.($record->status === 'active' ? 'success' : 'secondary').'">'.e(__("business_partners.statuses.{$record->status}")).'</span>')
            ->editColumn('created_by', fn (Customer $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (Customer $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? __('common.empty_value')))
            ->editColumn('updated_by', fn (Customer $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (Customer $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? __('common.empty_value')))
            ->addColumn('actions', fn (Customer $record): string => view('modules.finance.partials.actions', ['record' => $record, 'resource' => 'customers', 'routePrefix' => 'admin.sales.customers'])->render())
            ->orderColumn('doc_num', 'customers.doc_number $1')
            ->orderColumn('name', 'customers.name $1')
            ->orderColumn('account', 'accounts.account_code $1')
            ->orderColumn('phone', 'customers.phone $1')
            ->orderColumn('mobile', 'customers.mobile $1')
            ->orderColumn('email', 'customers.email $1')
            ->orderColumn('tax_number', 'customers.tax_number $1')
            ->orderColumn('status', 'customers.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'customers.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'customers.updated_at $1')
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
        if (! $request->user()?->can('customers.view_trashed')) {
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
