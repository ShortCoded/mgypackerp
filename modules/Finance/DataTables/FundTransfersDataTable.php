<?php

namespace Modules\Finance\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\FundTransfer;
use Yajra\DataTables\Facades\DataTables;

class FundTransfersDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $query = match ($this->trashFilter($request)) {
            'trashed' => FundTransfer::onlyTrashed(),
            'all' => FundTransfer::withTrashed(),
            default => FundTransfer::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'fund_transfers', $request);

        $query
            ->leftJoin('cashboxes as source_cashboxes', 'source_cashboxes.id', '=', 'fund_transfers.source_cashbox_id')
            ->leftJoin('bank_accounts as source_bank_accounts', 'source_bank_accounts.id', '=', 'fund_transfers.source_bank_account_id')
            ->leftJoin('cashboxes as target_cashboxes', 'target_cashboxes.id', '=', 'fund_transfers.target_cashbox_id')
            ->leftJoin('bank_accounts as target_bank_accounts', 'target_bank_accounts.id', '=', 'fund_transfers.target_bank_account_id')
            ->leftJoin('currencies as source_currencies', 'source_currencies.id', '=', 'fund_transfers.source_currency_id')
            ->leftJoin('currencies as target_currencies', 'target_currencies.id', '=', 'fund_transfers.target_currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'fund_transfers.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'fund_transfers.updated_by')
            ->select([
                'fund_transfers.*',
                'source_cashboxes.doc_num as source_cashbox_doc_num',
                'source_cashboxes.name as source_cashbox_name',
                'source_bank_accounts.doc_num as source_bank_account_doc_num',
                'source_bank_accounts.bank_name as source_bank_name',
                'source_bank_accounts.account_name as source_bank_account_name',
                'target_cashboxes.doc_num as target_cashbox_doc_num',
                'target_cashboxes.name as target_cashbox_name',
                'target_bank_accounts.doc_num as target_bank_account_doc_num',
                'target_bank_accounts.bank_name as target_bank_name',
                'target_bank_accounts.account_name as target_bank_account_name',
                'source_currencies.code as source_currency_code',
                'source_currencies.name as source_currency_name',
                'target_currencies.code as target_currency_code',
                'target_currencies.name as target_currency_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ]);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'fund_transfers.doc_num',
                            'fund_transfers.reason',
                            'fund_transfers.description',
                            'source_cashboxes.doc_num',
                            'source_cashboxes.name',
                            'source_bank_accounts.doc_num',
                            'source_bank_accounts.bank_name',
                            'source_bank_accounts.account_name',
                            'target_cashboxes.doc_num',
                            'target_cashboxes.name',
                            'target_bank_accounts.doc_num',
                            'target_bank_accounts.bank_name',
                            'target_bank_accounts.account_name',
                            'source_currencies.code',
                            'target_currencies.code',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (FundTransfer $record): string => view('modules.finance.fund-transfers.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (FundTransfer $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.finance.fund-transfers.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('transfer_date', fn (FundTransfer $record): string => $this->plainText($record->transfer_date?->format($dateFormat) ?? ''))
            ->addColumn('source', fn (FundTransfer $record): string => $this->ellipsisText($this->holderLabel($record, 'source')))
            ->addColumn('source_currency', fn (FundTransfer $record): string => $this->plainText(trim(implode(' — ', array_filter([$record->source_currency_code, $record->source_currency_name])))))
            ->editColumn('source_amount', fn (FundTransfer $record): string => $this->plainText($this->formatAmount((float) $record->source_amount)))
            ->addColumn('target', fn (FundTransfer $record): string => $this->ellipsisText($this->holderLabel($record, 'target')))
            ->addColumn('target_currency', fn (FundTransfer $record): string => $this->plainText(trim(implode(' — ', array_filter([$record->target_currency_code, $record->target_currency_name])))))
            ->editColumn('target_amount', fn (FundTransfer $record): string => $this->plainText($this->formatAmount((float) $record->target_amount)))
            ->editColumn('exchange_rate', fn (FundTransfer $record): string => $this->plainText($this->formatAmount((float) $record->exchange_rate, 6)))
            ->editColumn('status', fn (FundTransfer $record): string => view('modules.finance.fund-transfers.partials.status', ['record' => $record])->render())
            ->editColumn('reason', fn (FundTransfer $record): string => $this->ellipsisText($record->reason))
            ->addColumn('created_by', fn (FundTransfer $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->addColumn('updated_by', fn (FundTransfer $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->addColumn('actions', fn (FundTransfer $record): string => view('modules.finance.fund-transfers.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (FundTransfer $record): string => route('admin.finance.fund-transfers.edit', $record->doc_num))
            ->addColumn('can_edit', fn (FundTransfer $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('fund_transfers.edit'))
            ->addColumn('edit_blocked_message', fn (FundTransfer $record): string => $this->editBlockedMessage($record, (bool) $request->user()?->can('fund_transfers.edit')))
            ->orderColumn('doc_num', 'fund_transfers.doc_number $1')
            ->orderColumn('transfer_date', 'fund_transfers.transfer_date $1')
            ->orderColumn('source', 'source_cashboxes.name $1')
            ->orderColumn('source_currency', 'source_currencies.code $1')
            ->orderColumn('source_amount', 'fund_transfers.source_amount $1')
            ->orderColumn('target', 'target_cashboxes.name $1')
            ->orderColumn('target_currency', 'target_currencies.code $1')
            ->orderColumn('target_amount', 'fund_transfers.target_amount $1')
            ->orderColumn('exchange_rate', 'fund_transfers.exchange_rate $1')
            ->orderColumn('status', 'fund_transfers.status $1')
            ->orderColumn('reason', 'fund_transfers.reason $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('source_cashbox_id')
            ->removeColumn('source_bank_account_id')
            ->removeColumn('target_cashbox_id')
            ->removeColumn('target_bank_account_id')
            ->removeColumn('source_currency_id')
            ->removeColumn('target_currency_id')
            ->removeColumn('approved_by')
            ->removeColumn('cancelled_by')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'source', 'target', 'status', 'reason', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('fund_transfers.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function holderLabel(FundTransfer $record, string $side): string
    {
        if ($record->{"{$side}_type"} === FundTransfer::HolderBankAccount) {
            return trim(implode(' — ', array_filter([
                $record->{"{$side}_bank_account_doc_num"},
                $record->{"{$side}_bank_name"},
                $record->{"{$side}_bank_account_name"},
            ])));
        }

        return trim(implode(' — ', array_filter([
            $record->{"{$side}_cashbox_doc_num"},
            $record->{"{$side}_cashbox_name"},
        ])));
    }

    private function editBlockedMessage(FundTransfer $record, bool $hasPermission): string
    {
        if (! $hasPermission) {
            return __('fund_transfers.messages.edit_permission_denied');
        }

        return $record->isApproved()
            ? __('fund_transfers.messages.approved_edit_forbidden')
            : __('fund_transfers.messages.cancelled_edit_forbidden');
    }

    private function formatAmount(float $amount, int $scale = 4): string
    {
        return rtrim(rtrim(number_format($amount, $scale, '.', ''), '0'), '.') ?: '0';
    }
}
