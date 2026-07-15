<?php

namespace Modules\Finance\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\CashVoucher;
use Yajra\DataTables\Facades\DataTables;

class CashVouchersDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
    ) {}

    public function json(Request $request, string $voucherType, string $permissionPrefix, string $routePrefix, string $translationKey): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $query = match ($this->trashFilter($request, $permissionPrefix)) {
            'trashed' => CashVoucher::onlyTrashed(),
            'all' => CashVoucher::withTrashed(),
            default => CashVoucher::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'cash_vouchers', $request);

        $query
            ->where('cash_vouchers.voucher_type', $voucherType)
            ->leftJoin('cashboxes', 'cashboxes.id', '=', 'cash_vouchers.cashbox_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'cash_vouchers.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'cash_vouchers.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'cash_vouchers.updated_by')
            ->leftJoin('users as approved_users', 'approved_users.id', '=', 'cash_vouchers.approved_by')
            ->select([
                'cash_vouchers.*',
                'cashboxes.doc_num as cashbox_doc_num',
                'cashboxes.name as cashbox_name',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
                'approved_users.name as approved_by_name',
            ])
            ->selectSub(
                DB::table('cash_voucher_lines')
                    ->selectRaw('COALESCE(SUM(amount), 0)')
                    ->whereColumn('cash_voucher_lines.cash_voucher_id', 'cash_vouchers.id'),
                'distributed_total'
            );

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'cash_vouchers.doc_num',
                            'cash_vouchers.person_name',
                            'cash_vouchers.person_national_id',
                            'cash_vouchers.person_phone',
                            'cash_vouchers.reason',
                            'cash_vouchers.description',
                            'cashboxes.doc_num',
                            'cashboxes.name',
                            'currencies.code',
                            'currencies.name',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (CashVoucher $record): string => view('modules.finance.cash-vouchers.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (CashVoucher $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route($routePrefix.'.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('voucher_date', fn (CashVoucher $record): string => $this->plainText($record->voucher_date?->format($dateFormat) ?? ''))
            ->addColumn('cashbox', fn (CashVoucher $record): string => $this->ellipsisText(trim(implode(' — ', array_filter([$record->cashbox_doc_num, $record->cashbox_name])))))
            ->editColumn('person_name', fn (CashVoucher $record): string => $this->ellipsisText($record->person_name))
            ->addColumn('currency', fn (CashVoucher $record): string => $this->ellipsisText(trim(implode(' — ', array_filter([$record->currency_code, $record->currency_name])))))
            ->editColumn('exchange_rate', fn (CashVoucher $record): string => $this->plainText($this->formatAmount((float) $record->exchange_rate, 6)))
            ->editColumn('amount', fn (CashVoucher $record): string => $this->plainText($this->formatAmount((float) $record->amount)))
            ->addColumn('distributed_amount', fn (CashVoucher $record): string => $this->plainText($this->formatAmount((float) $record->distributed_total)))
            ->addColumn('remaining_amount', fn (CashVoucher $record): string => $this->plainText($this->formatAmount(((float) $record->amount) - ((float) $record->distributed_total))))
            ->editColumn('status', fn (CashVoucher $record): string => view('modules.finance.cash-vouchers.partials.status', ['record' => $record, 'translationKey' => $translationKey])->render())
            ->editColumn('reason', fn (CashVoucher $record): string => $this->ellipsisText($record->reason))
            ->addColumn('created_by', fn (CashVoucher $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->addColumn('updated_by', fn (CashVoucher $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->addColumn('approved_by', fn (CashVoucher $record): string => $this->ellipsisText($record->approved_by_name ?: __('common.empty_value')))
            ->editColumn('approved_at', fn (CashVoucher $record): string => $this->plainText($record->approved_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (CashVoucher $record): string => view('modules.finance.cash-vouchers.partials.actions', ['record' => $record, 'resource' => $permissionPrefix, 'routePrefix' => $routePrefix, 'translationKey' => $translationKey])->render())
            ->addColumn('edit_url', fn (CashVoucher $record): string => route($routePrefix.'.edit', $record->doc_num))
            ->addColumn('can_edit', fn (CashVoucher $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can($permissionPrefix.'.edit'))
            ->addColumn('edit_blocked_message', fn (CashVoucher $record): string => $this->editBlockedMessage($record, (bool) $request->user()?->can($permissionPrefix.'.edit'), $translationKey))
            ->orderColumn('doc_num', 'cash_vouchers.doc_number $1')
            ->orderColumn('voucher_date', 'cash_vouchers.voucher_date $1')
            ->orderColumn('cashbox', 'cashboxes.name $1')
            ->orderColumn('person_name', 'cash_vouchers.person_name $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('exchange_rate', 'cash_vouchers.exchange_rate $1')
            ->orderColumn('amount', 'cash_vouchers.amount $1')
            ->orderColumn('distributed_amount', 'distributed_total $1')
            ->orderColumn('remaining_amount', 'cash_vouchers.amount $1')
            ->orderColumn('status', 'cash_vouchers.status $1')
            ->orderColumn('reason', 'cash_vouchers.reason $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('approved_by', 'approved_users.name $1')
            ->orderColumn('approved_at', 'cash_vouchers.approved_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('cashbox_id')
            ->removeColumn('currency_id')
            ->removeColumn('cancelled_by')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'cashbox', 'person_name', 'currency', 'status', 'reason', 'created_by', 'updated_by', 'approved_by', 'actions'])
            ->toJson();
    }

    private function trashFilter(Request $request, string $permissionPrefix): string
    {
        if (! $request->user()?->can($permissionPrefix.'.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function editBlockedMessage(CashVoucher $record, bool $hasPermission, string $translationKey): string
    {
        if (! $hasPermission) {
            return __($translationKey.'.messages.edit_permission_denied');
        }

        if ($record->isApproved()) {
            return __($translationKey.'.messages.approved_edit_forbidden');
        }

        if ($record->isCancelled()) {
            return __($translationKey.'.messages.cancelled_edit_forbidden');
        }

        return __($translationKey.'.messages.document_locked');
    }

    private function formatAmount(float $amount, int $scale = 4): string
    {
        return rtrim(rtrim(number_format($amount, $scale, '.', ''), '0'), '.') ?: '0';
    }
}
