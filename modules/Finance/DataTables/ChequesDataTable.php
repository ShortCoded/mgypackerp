<?php

namespace Modules\Finance\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\Cheque;
use Modules\Finance\Services\FinanceAmountService;
use Yajra\DataTables\Facades\DataTables;

class ChequesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
        private readonly NumericFormatService $numbers,
        private readonly FinanceAmountService $amounts,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $query = match ($this->trashFilter($request)) {
            'trashed' => Cheque::onlyTrashed(),
            'all' => Cheque::withTrashed(),
            default => Cheque::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'cheques', $request);
        $typeFilter = $request->string('cheque_type_filter')->toString();

        $query
            ->when(in_array($typeFilter, Cheque::types(), true), fn ($query) => $query->where('cheques.cheque_type', $typeFilter))
            ->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'cheques.bank_account_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'cheques.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'cheques.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'cheques.updated_by')
            ->select([
                'cheques.*',
                'bank_accounts.doc_num as bank_account_doc_num',
                'bank_accounts.bank_name',
                'bank_accounts.account_name as bank_account_name',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->selectSub(
                DB::table('cheque_lines')
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('cheque_lines.cheque_id', 'cheques.id'),
                'distributed_total'
            );

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'cheques.doc_num',
                            'cheques.cheque_number',
                            'cheques.party_name',
                            'cheques.external_bank_name',
                            'cheques.reason',
                            'bank_accounts.doc_num',
                            'bank_accounts.bank_name',
                            'bank_accounts.account_name',
                            'currencies.code',
                            'currencies.name',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (Cheque $record): string => view('modules.finance.cheques.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (Cheque $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.finance.cheques.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('cheque_type', fn (Cheque $record): string => '<span class="badge rounded-pill badge-subtle-info">'.e(__('cheques.types.'.$record->cheque_type)).'</span>')
            ->editColumn('cheque_number', fn (Cheque $record): string => $this->plainText($record->cheque_number))
            ->addColumn('party', fn (Cheque $record): string => $this->ellipsisText($record->party_name ?: __('common.empty_value')))
            ->addColumn('bank_account', fn (Cheque $record): string => $this->ellipsisText(trim(implode(' — ', array_filter([$record->bank_account_doc_num, $record->bank_name, $record->bank_account_name])))))
            ->editColumn('external_bank_name', fn (Cheque $record): string => $this->ellipsisText($record->external_bank_name ?: __('common.empty_value')))
            ->addColumn('currency', fn (Cheque $record): string => $this->plainText(trim(implode(' — ', array_filter([$record->currency_code, $record->currency_name])))))
            ->editColumn('exchange_rate', fn (Cheque $record): string => $this->plainText($this->numbers->format($record->exchange_rate)))
            ->editColumn('amount', fn (Cheque $record): string => $this->plainText($this->numbers->format($record->amount)))
            ->addColumn('distributed_amount', fn (Cheque $record): string => $this->plainText($this->numbers->format($record->distributed_total)))
            ->addColumn('remaining_amount', fn (Cheque $record): string => $this->plainText($this->numbers->format(
                $this->amounts->fromUnits(
                    $this->amounts->toUnits($record->amount) - $this->amounts->toUnits($record->distributed_total)
                )
            )))
            ->editColumn('due_date', fn (Cheque $record): string => $this->plainText($record->due_date?->format($dateFormat) ?? ''))
            ->editColumn('status', fn (Cheque $record): string => view('modules.finance.cheques.partials.status', ['record' => $record])->render())
            ->addColumn('created_by', fn (Cheque $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->addColumn('updated_by', fn (Cheque $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->addColumn('actions', fn (Cheque $record): string => view('modules.finance.cheques.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (Cheque $record): string => route('admin.finance.cheques.edit', $record->doc_num))
            ->addColumn('can_edit', fn (Cheque $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('cheques.edit'))
            ->addColumn('edit_blocked_message', fn (Cheque $record): string => $this->editBlockedMessage($record, (bool) $request->user()?->can('cheques.edit')))
            ->orderColumn('doc_num', 'cheques.doc_number $1')
            ->orderColumn('cheque_type', 'cheques.cheque_type $1')
            ->orderColumn('cheque_number', 'cheques.cheque_number $1')
            ->orderColumn('party', 'cheques.party_name $1')
            ->orderColumn('bank_account', 'bank_accounts.bank_name $1')
            ->orderColumn('external_bank_name', 'cheques.external_bank_name $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('exchange_rate', 'cheques.exchange_rate $1')
            ->orderColumn('amount', 'cheques.amount $1')
            ->orderColumn('distributed_amount', 'distributed_total $1')
            ->orderColumn('remaining_amount', 'cheques.amount - distributed_total $1')
            ->orderColumn('due_date', 'cheques.due_date $1')
            ->orderColumn('status', 'cheques.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('bank_account_id')
            ->removeColumn('party_id')
            ->removeColumn('currency_id')
            ->removeColumn('cancelled_by')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'cheque_type', 'party', 'bank_account', 'external_bank_name', 'status', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('cheques.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function editBlockedMessage(Cheque $record, bool $hasPermission): string
    {
        if (! $hasPermission) {
            return __('cheques.messages.edit_permission_denied');
        }

        if ($record->status === Cheque::StatusReturned) {
            return __('cheques.messages.returned_edit_forbidden');
        }

        if (in_array($record->status, [Cheque::StatusCollected, Cheque::StatusCleared, Cheque::StatusCancelled], true)) {
            return __('cheques.messages.final_edit_forbidden');
        }

        return __('cheques.messages.document_locked');
    }
}
