<?php

namespace Modules\Purchases\DataTables;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\DataTables\Concerns\FormatsNullableColumns;
use Modules\Core\Models\Branch;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\DateFormatService;
use Modules\Core\Services\NumericFormatService;
use Modules\Core\Services\OperatingCompanyContextService;
use Modules\Core\Services\OperatingContextService;
use Modules\Core\Services\SettingService;
use Modules\Purchases\Models\PurchaseInvoice;
use Yajra\DataTables\Facades\DataTables;

class PurchaseInvoicesDataTable
{
    use FormatsNullableColumns;

    public function __construct(
        private readonly DataTableSearchService $search,
        private readonly OperatingCompanyContextService $companies,
        private readonly NumericFormatService $numbers,
    ) {}

    public function json(Request $request): JsonResponse
    {
        $dateFormat = app(SettingService::class)->dateFormat();
        $dateTimeFormat = app(SettingService::class)->dateTimeFormat();
        $context = app(OperatingContextService::class)->snapshot($request);
        $isAdministrativeBranch = Branch::query()
            ->whereKey($context['branch_id'])
            ->where('company_id', $context['company_id'])
            ->where('type', Branch::TypeAdministrative)
            ->exists();
        $query = match ($this->trashFilter($request)) {
            'trashed' => PurchaseInvoice::onlyTrashed(),
            'all' => PurchaseInvoice::withTrashed(),
            default => PurchaseInvoice::query(),
        };

        $query = $this->companies->applyCompanyScope($query, 'purchase_invoices', $request);
        $query
            ->leftJoin('suppliers', 'suppliers.id', '=', 'purchase_invoices.supplier_id')
            ->leftJoin('financial_periods', 'financial_periods.id', '=', 'purchase_invoices.financial_period_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'purchase_invoices.currency_id')
            ->leftJoin('users as created_users', 'created_users.id', '=', 'purchase_invoices.created_by')
            ->leftJoin('users as approved_users', 'approved_users.id', '=', 'purchase_invoices.approved_by')
            ->select([
                'purchase_invoices.id',
                'purchase_invoices.doc_number',
                'purchase_invoices.doc_num',
                'purchase_invoices.company_id',
                'purchase_invoices.invoice_date',
                'purchase_invoices.supplier_invoice_number',
                'purchase_invoices.payment_type',
                'purchase_invoices.status',
                'purchase_invoices.payment_status',
                'purchase_invoices.total_amount',
                'purchase_invoices.paid_amount',
                'purchase_invoices.remaining_amount',
                'purchase_invoices.created_at',
                'purchase_invoices.updated_at',
                'purchase_invoices.approved_at',
                'purchase_invoices.deleted_at',
                'suppliers.name as supplier_name',
                'suppliers.doc_num as supplier_doc_num',
                'financial_periods.name as financial_period_name',
                'financial_periods.doc_num as financial_period_doc_num',
                'currencies.code as currency_code',
                'currencies.name as currency_name',
                'created_users.name as created_by_name',
                'approved_users.name as approved_by_name',
            ]);

        $this->applyFilters($query, $request);

        return DataTables::eloquent($query)
            ->filter(function ($query) use ($request): void {
                $terms = $this->search->terms(is_string($request->input('search.value')) ? $request->input('search.value') : null);

                if ($terms !== []) {
                    $this->search->applyMultiTermSearch($query, $terms, [
                        'text' => [
                            'purchase_invoices.doc_num',
                            'purchase_invoices.supplier_invoice_number',
                            'suppliers.name',
                            'suppliers.doc_num',
                            'financial_periods.name',
                            'financial_periods.doc_num',
                            'currencies.code',
                            'currencies.name',
                        ],
                    ]);
                }
            })
            ->addColumn('checkbox', fn (PurchaseInvoice $record): string => view('modules.purchases.purchase-invoices.partials.checkbox', ['record' => $record])->render())
            ->editColumn('doc_num', fn (PurchaseInvoice $record): string => '<a class="fw-semibold dt-code-value" href="'.e(route('admin.purchases.purchase-invoices.show', $record->doc_num)).'">'.e($record->doc_num).'</a>')
            ->editColumn('invoice_date', fn (PurchaseInvoice $record): string => $this->plainText($record->invoice_date?->format($dateFormat) ?? ''))
            ->addColumn('supplier', fn (PurchaseInvoice $record): string => $this->ellipsisText(trim(implode(' / ', array_filter([$record->supplier_doc_num, $record->supplier_name])))))
            ->addColumn('financial_period', fn (PurchaseInvoice $record): string => $this->ellipsisText(trim(implode(' / ', array_filter([$record->financial_period_doc_num, $record->financial_period_name])))))
            ->addColumn('currency', fn (PurchaseInvoice $record): string => $this->ellipsisText(trim(implode(' / ', array_filter([$record->currency_code, $record->currency_name])))))
            ->editColumn('payment_type', fn (PurchaseInvoice $record): string => $this->plainText(__('purchase_invoices.payment_types.'.$record->payment_type)))
            ->editColumn('status', fn (PurchaseInvoice $record): string => view('modules.purchases.purchase-invoices.partials.status', ['record' => $record])->render())
            ->editColumn('payment_status', fn (PurchaseInvoice $record): string => view('modules.purchases.purchase-invoices.partials.payment-status', ['record' => $record])->render())
            ->editColumn('total_amount', fn (PurchaseInvoice $record): string => $this->plainText($this->numbers->format($record->total_amount)))
            ->editColumn('paid_amount', fn (PurchaseInvoice $record): string => $this->plainText($this->numbers->format($record->paid_amount)))
            ->editColumn('remaining_amount', fn (PurchaseInvoice $record): string => $this->plainText($this->numbers->format($record->remaining_amount)))
            ->addColumn('created_by', fn (PurchaseInvoice $record): string => $this->ellipsisText($record->created_by_name ?: __('common.empty_value')))
            ->editColumn('created_at', fn (PurchaseInvoice $record): string => $this->plainText($record->created_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('approved_by', fn (PurchaseInvoice $record): string => $this->ellipsisText($record->approved_by_name ?: __('common.empty_value')))
            ->editColumn('approved_at', fn (PurchaseInvoice $record): string => $this->plainText($record->approved_at?->format($dateTimeFormat) ?? ''))
            ->addColumn('actions', fn (PurchaseInvoice $record): string => view('modules.purchases.purchase-invoices.partials.actions', ['record' => $record])->render())
            ->addColumn('view_url', fn (PurchaseInvoice $record): string => route('admin.purchases.purchase-invoices.show', $record->doc_num))
            ->addColumn('edit_url', fn (PurchaseInvoice $record): string => route('admin.purchases.purchase-invoices.edit', $record->doc_num))
            ->addColumn('can_edit', fn (PurchaseInvoice $record): bool => $isAdministrativeBranch && ! $record->trashed() && ! $record->isLockedForEditing() && (bool) $request->user()?->can('purchase_invoices.edit'))
            ->orderColumn('doc_num', 'purchase_invoices.doc_number $1')
            ->orderColumn('invoice_date', 'purchase_invoices.invoice_date $1')
            ->orderColumn('supplier', 'suppliers.name $1')
            ->orderColumn('financial_period', 'financial_periods.from_date $1')
            ->orderColumn('currency', 'currencies.code $1')
            ->orderColumn('payment_type', 'purchase_invoices.payment_type $1')
            ->orderColumn('status', 'purchase_invoices.status $1')
            ->orderColumn('payment_status', 'purchase_invoices.payment_status $1')
            ->orderColumn('total_amount', 'purchase_invoices.total_amount $1')
            ->orderColumn('paid_amount', 'purchase_invoices.paid_amount $1')
            ->orderColumn('remaining_amount', 'purchase_invoices.remaining_amount $1')
            ->orderColumn('created_by', 'created_users.name $1')
            ->orderColumn('created_at', 'purchase_invoices.created_at $1')
            ->orderColumn('approved_by', 'approved_users.name $1')
            ->orderColumn('approved_at', 'purchase_invoices.approved_at $1')
            ->removeColumn('id')
            ->removeColumn('company_id')
            ->rawColumns(['checkbox', 'doc_num', 'supplier', 'financial_period', 'currency', 'status', 'payment_status', 'created_by', 'approved_by', 'actions'])
            ->toJson();
    }

    private function applyFilters(mixed $query, Request $request): void
    {
        if ($request->filled('supplier_doc_num')) {
            $query->where('suppliers.doc_num', $request->string('supplier_doc_num')->toString());
        }

        if ($request->filled('financial_period_doc_num')) {
            $query->where('financial_periods.doc_num', $request->string('financial_period_doc_num')->toString());
        }

        if ($request->filled('payment_status')) {
            $query->where('purchase_invoices.payment_status', $request->string('payment_status')->toString());
        }

        if ($request->filled('status')) {
            $query->where('purchase_invoices.status', $request->string('status')->toString());
        }

        if ($request->filled('payment_type')) {
            $query->where('purchase_invoices.payment_type', $request->string('payment_type')->toString());
        }

        if ($request->filled('currency_doc_num')) {
            $query->where('currencies.doc_num', $request->string('currency_doc_num')->toString());
        }

        if ($request->filled('created_by')) {
            $query->where('created_users.doc_num', $request->string('created_by')->toString());
        }

        if ($request->filled('approved_by')) {
            $query->where('approved_users.doc_num', $request->string('approved_by')->toString());
        }

        $dates = app(DateFormatService::class);

        if ($request->filled('date_from') && $dates->isValidDate($request->string('date_from')->toString())) {
            $query->whereDate('purchase_invoices.invoice_date', '>=', $dates->normalizeForStorage($request->string('date_from')->toString()));
        }

        if ($request->filled('date_to') && $dates->isValidDate($request->string('date_to')->toString())) {
            $query->whereDate('purchase_invoices.invoice_date', '<=', $dates->normalizeForStorage($request->string('date_to')->toString()));
        }
    }

    private function trashFilter(Request $request): string
    {
        if (! $request->user()?->can('purchase_invoices.view_trashed')) {
            return 'active';
        }

        return in_array($request->string('trash_filter')->toString(), ['active', 'trashed', 'all'], true) ? $request->string('trash_filter')->toString() : 'active';
    }
}
