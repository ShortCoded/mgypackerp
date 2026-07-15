<?php

namespace Modules\Finance\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Accounting\Models\Account;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\Cashbox;
use Yajra\DataTables\Facades\DataTables;

class CashboxesDataTable
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
            'trashed' => Cashbox::onlyTrashed(),
            'all' => Cashbox::withTrashed(),
            default => Cashbox::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'cashboxes', $request);

        $query->with(['currencies.currency'])
            ->leftJoin('accounts', 'accounts.id', '=', 'cashboxes.account_id')
            ->leftJoin('branches', 'branches.id', '=', 'cashboxes.branch_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'cashboxes.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'cashboxes.updated_by')
            ->select(['cashboxes.*', 'accounts.account_code', 'accounts.name as account_label', 'accounts.name_en as account_label_en', 'branches.name as branch_name', 'branches.doc_num as branch_doc_num', 'created_users.name as created_by_name', 'updated_users.name as updated_by_name']);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => ['cashboxes.doc_num', 'cashboxes.name', 'accounts.account_code', 'accounts.name', 'branches.name']]);
                }
            })
            ->addColumn('checkbox', fn (Cashbox $record): string => view('modules.finance.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (Cashbox $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.finance.cashboxes.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->addColumn('account', fn (Cashbox $record): string => $this->ellipsisText(Account::codeNameLabelFor($record->account_code, $record->account_label, $record->account_label_en)))
            ->addColumn('branch', fn (Cashbox $record): string => $this->ellipsisText(trim(implode(' — ', array_filter([$record->branch_doc_num, $record->branch_name])))))
            ->addColumn('currencies_summary', fn (Cashbox $record): string => $this->ellipsisText($this->currenciesSummary($record)))
            ->editColumn('status', fn (Cashbox $record): string => '<span class="badge rounded-pill badge-subtle-'.($record->status === 'active' ? 'success' : 'secondary').'">'.e(__("finance.statuses.{$record->status}")).'</span>')
            ->addColumn('created_by', fn (Cashbox $record): string => $this->ellipsisText($record->created_by_name))
            ->editColumn('created_at', fn (Cashbox $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('updated_by', fn (Cashbox $record): string => $this->ellipsisText($record->updated_by_name))
            ->editColumn('updated_at', fn (Cashbox $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (Cashbox $record): string => view('modules.finance.partials.actions', ['record' => $record, 'resource' => 'cashboxes', 'routePrefix' => 'admin.finance.cashboxes'])->render())
            ->orderColumn('doc_num', 'cashboxes.doc_number $1')
            ->orderColumn('name', 'cashboxes.name $1')
            ->orderColumn('account', 'accounts.account_code $1')
            ->orderColumn('branch', 'branches.name $1')
            ->orderColumn('currencies_summary', 'cashboxes.name $1')
            ->orderColumn('status', 'cashboxes.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'cashboxes.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'cashboxes.updated_at $1')
            ->removeColumn('id')
            ->rawColumns(['checkbox', 'doc_num', 'account', 'branch', 'currencies_summary', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('cashboxes.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function currenciesSummary(Cashbox $record): string
    {
        $codes = $record->currencies
            ->map(fn ($row): ?string => $row->currency?->code)
            ->filter()
            ->values();

        return $codes->isEmpty() ? __('cashboxes.all_currencies') : $codes->implode(', ');
    }
}
