<?php

namespace Modules\Accounting\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Accounting\Models\AccountClassification;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Yajra\DataTables\Facades\DataTables;

class AccountsDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $query = $this->baseQuery($request)
            ->leftJoin('accounts as parent_accounts', 'parent_accounts.id', '=', 'accounts.parent_id')
            ->leftJoin('account_classifications', 'account_classifications.id', '=', 'accounts.account_classification_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'accounts.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'accounts.updated_by')
            ->select([
                'accounts.*',
                'parent_accounts.account_code as parent_code',
                'parent_accounts.name as parent_name',
                'parent_accounts.name_en as parent_name_en',
                'account_classifications.code as classification_code',
                'account_classifications.name as classification_name',
                'account_classifications.name_en as classification_name_en',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);
        $query = $this->applyReportFilters($query, $request);

        $canView = (bool) $request->user()?->can('accounts.view');
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => [
                        'accounts.doc_num', 'accounts.account_code', 'accounts.name', 'accounts.name_en',
                        'accounts.statement_type', 'accounts.normal_balance',
                        'parent_accounts.account_code', 'parent_accounts.name', 'parent_accounts.name_en',
                        'account_classifications.name', 'account_classifications.name_en',
                        'created_users.name', 'updated_users.name',
                    ]]);
                }
            })
            ->addColumn('checkbox', fn (Account $account): string => view('modules.accounting.accounts.partials.checkbox', compact('account'))->render())
            ->editColumn('doc_num', fn (Account $account): string => $canView ? '<a class="fw-semibold dt-code-value" dir="ltr" href="'.e(route('admin.accounting.accounts.show', $account->doc_num)).'">'.e($account->doc_num).'</a>' : e($account->doc_num))
            ->editColumn('account_code', fn (Account $account): string => '<span class="dt-code-value" dir="ltr">'.e($account->account_code).'</span>')
            ->editColumn('name', fn (Account $account): string => $this->ellipsisText($account->name))
            ->addColumn('parent', fn (Account $account): string => $this->ellipsisText($this->parentName($account)))
            ->addColumn('classification', fn (Account $account): string => $this->ellipsisText($this->classificationName($account)))
            ->editColumn('statement_type', fn (Account $account): string => $this->plainText(__("accounts.statement_types.{$account->statement_type}")))
            ->editColumn('normal_balance', fn (Account $account): string => $this->badge(__("accounts.normal_balances.{$account->normal_balance}"), $account->normal_balance === Account::BalanceDebit ? 'info' : 'warning'))
            ->editColumn('status', fn (Account $account): string => $this->badge(__("accounts.statuses.{$account->status}"), $account->status === 'active' ? 'success' : 'secondary'))
            ->addColumn('created_by', fn (Account $account): string => $this->ellipsisText($account->created_by_name))
            ->editColumn('created_at', fn (Account $account): string => $this->plainText($account->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (Account $account): string => $this->ellipsisText($account->updated_by_name))
            ->editColumn('updated_at', fn (Account $account): string => $this->plainText($account->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (Account $account): string => view('modules.accounting.accounts.partials.actions', compact('account'))->render())
            ->orderColumn('doc_num', 'accounts.doc_number $1')
            ->orderColumn('account_code', 'accounts.account_code $1')
            ->orderColumn('name', 'accounts.name $1')
            ->orderColumn('parent', 'parent_accounts.account_code $1, parent_accounts.name $1')
            ->orderColumn('classification', $this->classificationOrderColumn())
            ->orderColumn('statement_type', 'accounts.statement_type $1')
            ->orderColumn('normal_balance', 'accounts.normal_balance $1')
            ->orderColumn('status', 'accounts.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'accounts.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'accounts.updated_at $1')
            ->removeColumn('id')
            ->rawColumns([
                'checkbox',
                'doc_num',
                'account_code',
                'name',
                'parent',
                'classification',
                'normal_balance',
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
        $query = match (! $request->user()?->can('accounts.view_trashed') ? 'active' : $request->string('trash_filter')->toString()) {
            'trashed' => Account::onlyTrashed(),
            'all' => Account::withTrashed(),
            default => Account::query(),
        };

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->forCompany($companyId);
    }

    private function applyReportFilters($query, Request $request)
    {
        $search = trim((string) $request->input('account_search'));
        if ($search !== '') {
            $like = '%'.mb_strtolower($search).'%';
            $query->where(function ($builder) use ($like): void {
                $builder->whereRaw('LOWER(accounts.account_code) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(accounts.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(accounts.name_en, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(account_classifications.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(account_classifications.name_en, \'\')) LIKE ?', [$like]);
            });
        }

        foreach (['statement_type', 'normal_balance', 'status'] as $field) {
            $value = trim((string) $request->input($field));
            if ($value !== '') {
                $query->where("accounts.{$field}", $value);
            }
        }

        $classification = trim((string) $request->input('classification'));
        if ($classification !== '') {
            $query->whereHas('classification', fn ($builder) => $builder->where('code', $classification));
        }

        $hierarchy = trim((string) $request->input('hierarchy'));
        if ($hierarchy === 'root') {
            $query->whereNull('accounts.parent_id');
        } elseif ($hierarchy === 'children') {
            $query->whereNotNull('accounts.parent_id');
        }

        $level = trim((string) $request->input('level'));
        if ($level !== '' && ctype_digit($level)) {
            $query->where('accounts.level', (int) $level);
        }

        return $query;
    }

    private function badge(string $label, string $color): string
    {
        return '<span class="badge rounded-pill badge-subtle-'.$color.'">'.e($label).'</span>';
    }

    private function classificationName(Account $account): string
    {
        return AccountClassification::displayNameFor(
            $account->getAttribute('classification_name'),
            $account->getAttribute('classification_name_en'),
        );
    }

    private function parentName(Account $account): string
    {
        return Account::codeNameLabelFor(
            $account->getAttribute('parent_code'),
            $account->getAttribute('parent_name'),
            $account->getAttribute('parent_name_en'),
        );
    }

    private function classificationOrderColumn(): string
    {
        if (config('languages.available.'.app()->getLocale().'.dir') === 'rtl') {
            return 'account_classifications.name $1';
        }

        return "COALESCE(NULLIF(account_classifications.name_en, ''), account_classifications.name) $1";
    }

    private function booleanBadge(bool $value): string
    {
        return $this->badge($value ? __('common.actions.yes') : __('common.actions.no'), $value ? 'success' : 'secondary');
    }
}
