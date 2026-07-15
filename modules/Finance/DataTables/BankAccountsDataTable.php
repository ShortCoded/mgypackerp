<?php

namespace Modules\Finance\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\BankAccount;
use Yajra\DataTables\Facades\DataTables;

class BankAccountsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $query = match ($this->trashFilter($request, 'bank_accounts.view_trashed')) {
            'trashed' => BankAccount::onlyTrashed(),
            'all' => BankAccount::withTrashed(),
            default => BankAccount::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'bank_accounts', $request);

        $query->leftJoin('accounts as generated_accounts', 'generated_accounts.id', '=', 'bank_accounts.account_id')
            ->leftJoin('accounts as bank_groups', 'bank_groups.id', '=', 'bank_accounts.bank_id')
            ->leftJoin('accounts as generated_parent_groups', 'generated_parent_groups.id', '=', 'generated_accounts.parent_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'bank_accounts.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'bank_accounts.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'bank_accounts.updated_by')
            ->select(['bank_accounts.*', 'currencies.code as currency_code', 'currencies.name as currency_name', 'generated_accounts.name as linked_account_name', 'generated_accounts.name_en as linked_account_name_en', 'created_users.name as created_by_name', 'updated_users.name as updated_by_name'])
            ->selectRaw('COALESCE(bank_groups.account_code, CASE WHEN generated_accounts.is_group THEN generated_accounts.account_code ELSE generated_parent_groups.account_code END) as bank_group_code')
            ->selectRaw('COALESCE(bank_groups.name, CASE WHEN generated_accounts.is_group THEN generated_accounts.name ELSE generated_parent_groups.name END) as bank_group_label');

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => ['bank_accounts.doc_num', 'bank_accounts.account_name', 'generated_accounts.account_code', 'generated_accounts.name', 'generated_accounts.name_en', 'bank_groups.account_code', 'bank_groups.name', 'currencies.code']]);
                }
            })
            ->addColumn('checkbox', fn (BankAccount $record): string => view('modules.finance.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (BankAccount $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.finance.bank-accounts.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->addColumn('bank', fn (BankAccount $record): string => $this->ellipsisText(trim($record->bank_group_code.' — '.$record->bank_group_label)))
            ->addColumn('currency', fn (BankAccount $record): string => e(trim($record->currency_code.' — '.$record->currency_name)))
            ->editColumn('account_name', fn (BankAccount $record): string => $this->ellipsisText(Account::displayNameFor($record->linked_account_name, $record->linked_account_name_en) ?: $record->account_name))
            ->editColumn('status', fn (BankAccount $record): string => '<span class="badge rounded-pill badge-subtle-'.($record->status === 'active' ? 'success' : 'secondary').'">'.e(__("finance.statuses.{$record->status}")).'</span>')
            ->addColumn('created_by', fn (BankAccount $record): string => $this->ellipsisText($record->created_by_name))
            ->editColumn('created_at', fn (BankAccount $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (BankAccount $record): string => $this->ellipsisText($record->updated_by_name))
            ->editColumn('updated_at', fn (BankAccount $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (BankAccount $record): string => view('modules.finance.partials.actions', ['record' => $record, 'resource' => 'bank_accounts', 'routePrefix' => 'admin.finance.bank-accounts'])->render())
            ->orderColumn('doc_num', 'bank_accounts.doc_number $1')
            ->orderColumn('bank', 'COALESCE(bank_groups.account_code, generated_parent_groups.account_code, generated_accounts.account_code) $1')
            ->orderColumn('account_name', 'generated_accounts.name $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('account_number', 'bank_accounts.account_number $1')
            ->orderColumn('iban', 'bank_accounts.iban $1')
            ->orderColumn('status', 'bank_accounts.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'bank_accounts.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'bank_accounts.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('bank_name')
            ->removeColumn('bank_id')
            ->removeColumn('account_id')
            ->removeColumn('currency_id')
            ->rawColumns(['checkbox', 'doc_num', 'bank', 'account_name', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request, string $permission): string
    {
        if (! $request->user()?->can($permission)) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }
}
