<?php

namespace Modules\Finance\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Finance\Models\OpeningBalance;
use Yajra\DataTables\Facades\DataTables;

class OpeningBalancesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $context = $this->operatingContext->snapshot($request);
        $query = match ($this->trashFilter($request)) {
            'trashed' => OpeningBalance::onlyTrashed(),
            'all' => OpeningBalance::withTrashed(),
            default => OpeningBalance::query(),
        };

        $query
            ->when(
                $context['company_id'] && $context['financial_period_id'],
                fn ($query) => $query
                    ->where('opening_balances.company_id', $context['company_id'])
                    ->where('opening_balances.financial_period_id', $context['financial_period_id']),
                fn ($query) => $query->whereRaw('1 = 0')
            )
            ->leftJoin('currencies', 'currencies.id', '=', 'opening_balances.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'opening_balances.created_by')
            ->leftJoin('users as updated_users', 'updated_users.id', '=', 'opening_balances.updated_by')
            ->select([
                'opening_balances.*',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'updated_users.name as updated_by_name',
            ])
            ->selectSub(
                DB::table('opening_balance_lines')
                    ->selectRaw('COALESCE(SUM(debit_amount), 0)')
                    ->whereColumn('opening_balance_lines.opening_balance_id', 'opening_balances.id'),
                'total_debit'
            )
            ->selectSub(
                DB::table('opening_balance_lines')
                    ->selectRaw('COALESCE(SUM(credit_amount), 0)')
                    ->whereColumn('opening_balance_lines.opening_balance_id', 'opening_balances.id'),
                'total_credit'
            );

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);
                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, ['text' => ['opening_balances.doc_num', 'opening_balances.description', 'opening_balances.notes', 'currencies.code', 'currencies.name']]);
                }
            })
            ->addColumn('checkbox', fn (OpeningBalance $record): string => view('modules.finance.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (OpeningBalance $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.finance.opening-balances.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('document_date', fn (OpeningBalance $record): string => $this->plainText($record->document_date?->format($dateFormat) ?? ''))
            ->addColumn('currency', fn (OpeningBalance $record): string => $this->plainText(trim(implode(' — ', array_filter([$record->currency_code, $record->currency_name])))))
            ->addColumn('total_debit', fn (OpeningBalance $record): string => $this->plainText($this->formatAmount((float) $record->total_debit)))
            ->addColumn('total_credit', fn (OpeningBalance $record): string => $this->plainText($this->formatAmount((float) $record->total_credit)))
            ->addColumn('state', fn (OpeningBalance $record): string => view('modules.finance.opening-balances.partials.state', ['record' => $record])->render())
            ->editColumn('created_by', fn (OpeningBalance $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (OpeningBalance $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->editColumn('updated_by', fn (OpeningBalance $record): string => $this->ellipsisText($record->updated_by_name ?: __('common.empty_value')))
            ->editColumn('updated_at', fn (OpeningBalance $record): string => $this->plainText($record->updated_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (OpeningBalance $record): string => view('modules.finance.opening-balances.partials.actions', ['record' => $record])->render())
            ->addColumn('edit_url', fn (OpeningBalance $record): string => route('admin.finance.opening-balances.edit', $record->doc_num))
            ->addColumn('can_edit', fn (OpeningBalance $record): bool => ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('opening_balances.edit'))
            ->addColumn('edit_blocked_message', fn (OpeningBalance $record): string => $this->editBlockedMessage($record, (bool) $request->user()?->can('opening_balances.edit')))
            ->orderColumn('doc_num', 'opening_balances.doc_number $1')
            ->orderColumn('document_date', 'opening_balances.document_date $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('total_debit', 'total_debit $1')
            ->orderColumn('total_credit', 'total_credit $1')
            ->orderColumn('state', 'opening_balances.status $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'opening_balances.created_at $1')
            ->orderColumn('updated_by', 'updated_users.name $1')
            ->orderColumn('updated_at', 'opening_balances.updated_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->removeColumn('financial_period_id')
            ->removeColumn('currency_id')
            ->removeColumn('approved_by')
            ->removeColumn('journal_entry_id')
            ->removeColumn('deleted_by')
            ->removeColumn('restored_by')
            ->rawColumns(['checkbox', 'doc_num', 'state', 'created_by', 'updated_by', 'actions'])
            ->toJson();
    }

    private function editBlockedMessage(OpeningBalance $record, bool $hasPermission): string
    {
        if (! $hasPermission) {
            return __('opening_balances.messages.edit_permission_denied');
        }

        if ($record->isApproved()) {
            return __('opening_balances.messages.approved_edit_forbidden');
        }

        if ($record->isClosed()) {
            return __('opening_balances.messages.closed_edit_forbidden');
        }

        return __('opening_balances.messages.document_locked');
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('opening_balances.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }

    private function formatAmount(float $amount): string
    {
        return rtrim(rtrim(number_format($amount, 3, '.', ''), '0'), '.') ?: '0';
    }
}
