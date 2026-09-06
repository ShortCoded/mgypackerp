@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $reportTypes = ['financial', 'period', 'customers', 'products', 'invoices', 'receivables', 'collections', 'returns', 'quotations', 'fulfillment', 'operational'];
    $reportTitle = __('sales_ui.reports.types.'.$reportType);
    $reportDescription = __('sales_ui.reports.descriptions.'.$reportType);
    $hasFilters = collect(request()->except(['report', 'ledger_page', 'backorders_page']))->filter(fn ($value) => filled($value))->isNotEmpty();
    $query = request()->query();
    $exportQuery = fn (string $format) => route('admin.reports.sales.sales-orders.export', ['format' => $format, ...$query]);
    $dateValue = fn ($value) => $value ? $dates->formatDate($value, '') : '';
    $emptyRow = fn (int $columns) => '<tr><td colspan="'.$columns.'" class="text-center text-500 py-4">'.e(__('sales_ui.reports.no_results')).'</td></tr>';
    $qualityDispositionLabel = static fn (?string $value): string => collect(explode(',', (string) $value))
        ->filter()
        ->map(fn (string $bucket): string => __(str($bucket)->replace('_', ' ')->title()->toString()))
        ->join(app()->isLocale('ar') ? '، ' : ', ');
@endphp

@section('title', $reportTitle)

@section('content')
<div data-sales-ui>
    <x-admin.report.page :title="$reportTitle" :description="$reportDescription">
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="sales-report-filters"
                :refresh-url="request()->fullUrl()"
                :export-options="[
                    ['label' => __('Excel'), 'url' => $exportQuery('xlsx'), 'icon' => 'file-excel', 'permission' => 'reports.sales.sales_orders.export'],
                    ['label' => __('CSV'), 'url' => $exportQuery('csv'), 'icon' => 'file-csv', 'permission' => 'reports.sales.sales_orders.export'],
                    ['label' => __('PDF / Print'), 'url' => route('admin.reports.sales.sales-orders.print', $query), 'icon' => 'file-pdf', 'permission' => 'reports.sales.sales_orders.print', 'newTab' => true],
                ]">
                <x-slot:extraActions>
                    <div class="dropdown">
                        <button class="btn btn-falcon-default btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                            <span class="fas fa-chart-line me-1"></span>{{ __('sales_ui.reports.choose_report') }}
                        </button>
                        <div class="dropdown-menu dropdown-menu-end overflow-auto">
                            @foreach($reportTypes as $type)
                                <a class="dropdown-item @if($type === $reportType) active @endif" href="{{ route('admin.reports.sales.sales-orders.index', ['report' => $type]) }}">{{ __('sales_ui.reports.types.'.$type) }}</a>
                            @endforeach
                        </div>
                    </div>
                </x-slot:extraActions>
            </x-admin.report.actions-toolbar>
        </x-slot:actions>

        <x-admin.report.filter-panel
            id="sales-report-filters"
            :title="__('sales_ui.reports.filters')"
            :description="__('sales_ui.reports.filters_help')"
            :action="route('admin.reports.sales.sales-orders.index')"
            :expanded="$hasFilters"
            :reset-url="route('admin.reports.sales.sales-orders.index', ['report' => $reportType])">
            <input type="hidden" name="report" value="{{ $reportType }}">

            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="report_currency" :label="__('Currency')" />
                <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="report_currency" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('Currency') }}" required>
                    @foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" selected>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="report_from" :label="__('From')" />
                <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="report_from" name="from" value="{{ $dateValue($from) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr">
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="report_to" :label="__('To')" />
                <input class="form-control form-control-sm js-date-picker js-report-filter-control" id="report_to" name="to" value="{{ $dateValue($to) }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr">
            </div>
            <div class="col-sm-6 col-xl-3">
                <x-forms.label for="report_customer" :label="__('Customer')" />
                <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="report_customer" name="customer_doc_num" data-url="{{ route('admin.sales.select2.customers') }}" data-placeholder="{{ __('All') }}" data-allow-clear="true">
                    @if($filterOptions['customer'])<option value="{{ $filterOptions['customer']->doc_num }}" selected>{{ $filterOptions['customer']->doc_num }} / {{ $filterOptions['customer']->name }}</option>@endif
                </select>
            </div>

            @if(in_array($reportType, ['products', 'returns', 'fulfillment', 'operational'], true))
                <div class="col-sm-6 col-xl-3">
                    <x-forms.label for="report_product" :label="__('Product')" />
                    <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="report_product" name="product_doc_num" data-url="{{ route('admin.sales.select2.quotation-products') }}" data-placeholder="{{ __('All') }}" data-allow-clear="true">
                        @if($filterOptions['product'])<option value="{{ $filterOptions['product']->doc_num }}" selected>{{ $filterOptions['product']->doc_num }} / {{ $filterOptions['product']->name }}</option>@endif
                    </select>
                </div>
            @endif
            @if(in_array($reportType, ['quotations', 'fulfillment', 'operational'], true))
                <div class="col-sm-6 col-xl-3">
                    <x-forms.label for="report_employee" :label="__('Sales Representative')" />
                    <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="report_employee" name="sales_person_doc_num" data-url="{{ route('admin.sales.select2.employees') }}" data-placeholder="{{ __('All') }}" data-allow-clear="true">
                        @if($filterOptions['employee'])<option value="{{ $filterOptions['employee']->doc_num }}" selected>{{ $filterOptions['employee']->doc_num }} / {{ $filterOptions['employee']->full_name ?: $filterOptions['employee']->name }}</option>@endif
                    </select>
                </div>
            @endif
            @if(in_array($reportType, ['fulfillment', 'operational'], true))
                <div class="col-sm-6 col-xl-3">
                    <x-forms.label for="report_warehouse" :label="__('Delivery warehouse')" />
                    <select class="form-select form-select-sm js-select2-ajax js-report-filter-control" id="report_warehouse" name="warehouse_uuid" data-url="{{ route('admin.sales.select2.stores') }}" data-placeholder="{{ __('All') }}" data-allow-clear="true">
                        @if($filterOptions['warehouse'])<option value="{{ $filterOptions['warehouse']->public_uuid }}" selected>{{ $filterOptions['warehouse']->name }}</option>@endif
                    </select>
                </div>
                <div class="col-sm-6 col-xl-3">
                    <x-forms.label for="report_order_status" :label="__('Order Status')" />
                    <select class="form-select form-select-sm js-report-filter-control" id="report_order_status" name="order_status">
                        <option value="">{{ __('All') }}</option>
                        @foreach($orderStatuses as $status)<option value="{{ $status }}" @selected($filters['order_status'] === $status)>{{ __(str($status)->replace('_', ' ')->title()->toString()) }}</option>@endforeach
                    </select>
                </div>
            @endif
            @if($reportType === 'quotations')
                <div class="col-sm-6 col-xl-3">
                    <x-forms.label for="report_quotation_status" :label="__('Quotation Status')" />
                    <select class="form-select form-select-sm js-report-filter-control" id="report_quotation_status" name="quotation_status"><option value="">{{ __('All') }}</option>@foreach(\Modules\Sales\Models\Quotation::Statuses as $status)<option value="{{ $status }}" @selected($filters['quotation_status'] === $status)>{{ __('quotations.statuses.'.$status) }}</option>@endforeach</select>
                </div>
            @endif
            @if(in_array($reportType, ['receivables', 'collections', 'financial'], true))
                <div class="col-sm-6 col-xl-3">
                    <x-forms.label for="report_payment_state" :label="__('Payment State')" />
                    <select class="form-select form-select-sm js-report-filter-control" id="report_payment_state" name="payment_state"><option value="">{{ __('All') }}</option><option value="outstanding" @selected($filters['payment_state'] === 'outstanding')>{{ __('Outstanding') }}</option><option value="settled" @selected($filters['payment_state'] === 'settled')>{{ __('Settled') }}</option></select>
                </div>
            @endif
            @if($reportType === 'returns')
                <div class="col-sm-6 col-xl-3">
                    <x-forms.label for="report_return_reason" :label="__('Return Reason')" />
                    <select class="form-select form-select-sm js-report-filter-control" id="report_return_reason" name="return_reason"><option value="">{{ __('All') }}</option>@foreach($returnReasons as $reason)<option value="{{ $reason }}" @selected($filters['return_reason'] === $reason)>{{ __(str($reason)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select>
                </div>
            @endif
        </x-admin.report.filter-panel>

        @if(in_array($reportType, ['financial', 'operational'], true))
            <div class="card mb-3" data-sales-financial-summary>
                <div class="card-header py-2"><h6 class="mb-0">{{ __('sales_ui.financial_summary') }} · {{ $reportCurrency?->code }}</h6></div>
                <div class="card-body"><div class="row g-2" data-sales-financial-kpi-grid>
                    @foreach([
                        ['invoice_count', 'invoice_count', false], ['gross_sales', 'gross_sales', true],
                        ['credit_notes', 'credit_notes_returns', true], ['net_sales', 'net_sales', true],
                        ['collections', 'collections', true], ['outstanding', 'outstanding', true],
                        ['overdue_outstanding', 'overdue_outstanding', true],
                    ] as [$key, $label, $showCurrency])
                        <div class="col-12 col-sm-6 col-lg-4 col-xl-3"><div class="border rounded h-100 p-3 bg-white"><div class="text-600 fs-11 mb-1">{{ __('sales_ui.'.$label) }}</div><div class="w-100 fs-5 fw-bold text-900 text-end white-space-nowrap" dir="ltr">{{ $numbers->format($financialSummary[$key]) }} @if($showCurrency)<small>{{ $reportCurrency?->code }}</small>@endif</div></div></div>
                    @endforeach
                    <div class="col-12 col-sm-6 col-lg-4 col-xl-3"><div class="border rounded h-100 p-3 bg-white"><div class="text-600 fs-11 mb-1">{{ __('sales_ui.collection_rate') }}</div><div class="w-100 fs-5 fw-bold text-900 text-end white-space-nowrap" dir="ltr">{{ $numbers->format($financialSummary['collection_rate']) }}%</div><small class="text-muted">{{ __('sales_ui.return_rate') }}: <span dir="ltr">{{ $numbers->format($financialSummary['return_rate']) }}%</span></small></div></div>
                </div></div>
            </div>
        @endif

        @if(in_array($reportType, ['customers', 'financial'], true))
            <x-admin.report.table-card :title="__('Sales / Outstanding by Customer')" table-id="sales-by-customer" class="mb-3"><thead><tr><th>{{ __('Customer') }}</th><th class="text-end">{{ __('Sales') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($salesByCustomer as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td><td class="text-end">{{ $numbers->format($row->outstanding) }}</td></tr>@empty{!! $emptyRow(3) !!}@endforelse</tbody></x-admin.report.table-card>
        @endif

        @if($reportType === 'period')
            <x-admin.report.table-card :title="__('Sales by Period')" table-id="sales-by-period" class="mb-3"><thead><tr><th>{{ __('Date') }}</th><th class="text-end">{{ __('Invoices') }}</th><th class="text-end">{{ __('Value') }}</th></tr></thead><tbody>@forelse($salesByPeriod as $row)<tr><td>{{ $dateValue($row->invoice_date) }}</td><td class="text-end">{{ $row->invoice_count }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td></tr>@empty{!! $emptyRow(3) !!}@endforelse</tbody></x-admin.report.table-card>
        @endif

        @if($reportType === 'products')
            <div class="row g-3 mb-3"><div class="col-xl-6"><x-admin.report.table-card :title="__('Sales by Item')" table-id="sales-by-product" class="h-100"><thead><tr><th>{{ __('Item') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Value') }}</th></tr></thead><tbody>@forelse($salesByItem as $row)<tr><td>{{ $row->doc_num }} / {{ $row->name }}</td><td class="text-end">{{ $numbers->format($row->sold_quantity) }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td></tr>@empty{!! $emptyRow(3) !!}@endforelse</tbody></x-admin.report.table-card></div><div class="col-xl-6"><x-admin.report.table-card :title="__('Customer / Item Sales Analysis')" table-id="customer-product-sales" class="h-100"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Value') }}</th></tr></thead><tbody>@forelse($salesByCustomerItem as $row)<tr><td>{{ $row->customer_doc_num }} / {{ $row->customer_name }}</td><td>{{ $row->product_doc_num }} / {{ $row->product_name }}</td><td class="text-end">{{ $numbers->format($row->sold_quantity) }}</td><td class="text-end">{{ $numbers->format($row->sales_value) }}</td></tr>@empty{!! $emptyRow(4) !!}@endforelse</tbody></x-admin.report.table-card></div></div>
        @endif

        @if(in_array($reportType, ['invoices', 'operational'], true))
            <x-admin.report.table-card :title="__('Sales Ledger')" table-id="sales-invoices-ledger" class="mb-3"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Sales Order') }}</th><th>{{ __('Delivery Notes') }}</th><th class="text-end">{{ __('Net Sales') }}</th><th class="text-end">{{ __('Collected') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($salesLedger as $invoice)<tr><td><a href="{{ route('admin.sales.sales-invoices.show', $invoice) }}">{{ $invoice->doc_num }}</a></td><td>{{ $invoice->customer?->name }}</td><td>{{ $dateValue($invoice->invoice_date) }}</td><td>{{ $invoice->order?->doc_num ?: '—' }}</td><td>{{ $invoice->deliveries->pluck('doc_num')->join(' / ') ?: '—' }}</td><td class="text-end">{{ $numbers->format(bcsub((string) $invoice->total_amount, (string) ($invoice->returns_amount ?? 0), 4)) }}</td><td class="text-end">{{ $numbers->format($invoice->paid_amount) }}</td><td class="text-end">{{ $numbers->format($invoice->remaining_amount) }}</td></tr>@empty{!! $emptyRow(8) !!}@endforelse</tbody></x-admin.report.table-card>
            @if(method_exists($salesLedger, 'links'))<div class="mb-3">{{ $salesLedger->links() }}</div>@endif
        @endif

        @if(in_array($reportType, ['receivables', 'financial'], true))
            <div class="row g-3 mb-3"><div class="col-xl-6"><x-admin.report.table-card :title="__('Invoice Outstanding')" table-id="outstanding-invoices" class="h-100"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($invoiceOutstanding as $invoice)<tr><td><a href="{{ route('admin.sales.sales-invoices.show', $invoice) }}">{{ $invoice->doc_num }}</a></td><td>{{ $invoice->customer?->name }}</td><td>{{ $dateValue($invoice->due_date) }}</td><td class="text-end">{{ $numbers->format($invoice->remaining_amount) }}</td></tr>@empty{!! $emptyRow(4) !!}@endforelse</tbody></x-admin.report.table-card></div><div class="col-xl-6"><x-admin.report.table-card :title="__('Customer Aging')" table-id="customer-aging" class="h-100"><thead><tr><th>{{ __('Customer') }}</th><th class="text-end">{{ __('Current') }}</th><th class="text-end">1–30</th><th class="text-end">31–60</th><th class="text-end">61–90</th><th class="text-end">90+</th></tr></thead><tbody>@forelse($aging as $row)<tr><td>{{ $row['customer'] }}</td><td class="text-end">{{ $numbers->format($row['current']) }}</td><td class="text-end">{{ $numbers->format($row['1_30']) }}</td><td class="text-end">{{ $numbers->format($row['31_60']) }}</td><td class="text-end">{{ $numbers->format($row['61_90']) }}</td><td class="text-end">{{ $numbers->format($row['over_90']) }}</td></tr>@empty{!! $emptyRow(6) !!}@endforelse</tbody></x-admin.report.table-card></div></div>
        @endif

        @if($reportType === 'collections')
            <x-admin.report.table-card :title="__('sales_ui.recorded_collections')" table-id="recorded-collections" class="mb-3"><thead><tr><th>{{ __('Document') }}</th><th>{{ __('Date') }}</th><th>{{ __('Customer') }}</th><th>{{ __('sales_ui.received_by_employee') }}</th><th>{{ __('Payment method') }}</th><th>{{ __('Reference') }}</th><th class="text-end">{{ __('Amount') }}</th><th>{{ __('Status') }}</th></tr></thead><tbody>@forelse($customerReceipts as $receipt)<tr><td><a href="{{ route('admin.sales.customer-receipts.show', $receipt) }}">{{ $receipt->doc_num }}</a></td><td>{{ $dateValue($receipt->receipt_date) }}</td><td>{{ $receipt->customer?->name }}</td><td>{{ $receipt->receivedByEmployee?->full_name ?: $receipt->receivedByEmployee?->name ?: __('sales_ui.legacy_receiver_unresolved') }}</td><td>{{ __(str($receipt->payment_method)->replace('_', ' ')->title()->toString()) }}</td><td dir="ltr">{{ $receipt->reference_no ?: $receipt->cashVoucher?->doc_num ?: $receipt->cheque?->doc_num ?: '—' }}</td><td class="text-end" dir="ltr">{{ $numbers->format($receipt->amount) }} {{ $receipt->currency?->code }}</td><td>{{ __(str($receipt->status)->replace('_', ' ')->title()->toString()) }}</td></tr>@empty{!! $emptyRow(8) !!}@endforelse</tbody></x-admin.report.table-card>
            <x-admin.report.table-card :title="__('sales_ui.upcoming_collections')" table-id="upcoming-collections" class="mb-3"><thead><tr><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due') }}</th><th class="text-end">{{ __('Outstanding') }}</th></tr></thead><tbody>@forelse($upcomingCollections as $row)<tr><td>{{ $row->doc_num }}</td><td>{{ $row->name }}</td><td>{{ $dateValue($row->due_date) }}</td><td class="text-end">{{ $numbers->format($row->outstanding) }}</td></tr>@empty{!! $emptyRow(4) !!}@endforelse</tbody></x-admin.report.table-card>
        @endif

        @if(in_array($reportType, ['quotations', 'operational'], true))
            <x-admin.report.table-card :title="__('Quotation Status / History')" table-id="quotation-history" class="mb-3"><thead><tr><th>{{ __('Quotation') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Date') }}</th><th>{{ __('Valid until') }}</th><th>{{ __('Status') }}</th><th>{{ __('Revision') }}</th><th class="text-end">{{ __('Total') }}</th></tr></thead><tbody>@forelse($quotations as $quotation)<tr><td><a href="{{ route('admin.sales.quotations.show', $quotation) }}">{{ $quotation->doc_num }}</a></td><td>{{ $quotation->customer?->name }}</td><td>{{ $dateValue($quotation->quotation_date) }}</td><td>{{ $dateValue($quotation->valid_until) }}</td><td>@include('modules.sales.quotations.partials.status', ['status' => $quotation->status])</td><td dir="ltr">{{ $quotation->currentRevision?->revision_code }}</td><td class="text-end">{{ $numbers->format($quotation->currentRevision?->total) }}</td></tr>@empty{!! $emptyRow(7) !!}@endforelse</tbody></x-admin.report.table-card>
        @endif

        @if(in_array($reportType, ['fulfillment', 'operational'], true))
            <x-admin.report.table-card :title="__('Invoice to Delivery Fulfillment')" table-id="sales-fulfillment" class="mb-3"><thead><tr><th>{{ __('Order') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Required date') }}</th><th>{{ __('Status') }}</th><th class="text-end">{{ __('Ordered') }}</th><th class="text-end">{{ __('Invoiced') }}</th><th class="text-end">{{ __('Delivered') }}</th><th class="text-end">{{ __('Remaining Delivery') }}</th></tr></thead><tbody>@forelse($openOrders as $order)<tr><td><a href="{{ route('admin.sales.sales-orders.show', $order) }}">{{ $order->doc_num }}</a></td><td>{{ $order->customer?->name }}</td><td>{{ $dateValue($order->expected_delivery_date) }}</td><td>{{ __(str($order->status)->replace('_', ' ')->title()->toString()) }}</td><td class="text-end">{{ $numbers->format($order->ordered_quantity) }}</td><td class="text-end">{{ $numbers->format($order->lines->sum('invoiced_quantity')) }}</td><td class="text-end">{{ $numbers->format($order->delivered_quantity) }}</td><td class="text-end">{{ $numbers->format(max(0, (float) $order->lines->sum('invoiced_quantity') - (float) $order->delivered_quantity)) }}</td></tr>@empty{!! $emptyRow(8) !!}@endforelse</tbody></x-admin.report.table-card>
        @endif

        @if($reportType === 'returns')
            <div class="row g-3 mb-3"><div class="col-xl-6"><x-admin.report.table-card :title="__('Returns by Reason and Quality Disposition')" table-id="returns-summary" class="h-100"><thead><tr><th>{{ __('Reason') }}</th><th class="text-end">{{ __('Returns') }}</th><th class="text-end">{{ __('Quantity') }}</th><th class="text-end">{{ __('Saleable') }}</th><th class="text-end">{{ __('Rejected / Rework / Scrap') }}</th></tr></thead><tbody>@forelse($returns as $row)<tr><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td class="text-end">{{ $row->return_count }}</td><td class="text-end">{{ $numbers->format($row->returned_quantity) }}</td><td class="text-end">{{ $numbers->format($row->saleable_quantity) }}</td><td class="text-end">{{ $numbers->format($row->rejected_quantity) }}</td></tr>@empty{!! $emptyRow(5) !!}@endforelse</tbody></x-admin.report.table-card></div><div class="col-xl-6"><x-admin.report.table-card :title="__('Customer / Item Return Analysis')" table-id="return-analysis" class="h-100"><thead><tr><th>{{ __('Customer') }}</th><th>{{ __('Item') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Disposition') }}</th><th class="text-end">{{ __('Quantity') }}</th></tr></thead><tbody>@forelse($returnAnalysis as $row)<tr><td>{{ $row->customer_name }}</td><td>{{ $row->product_name }}</td><td>{{ __(str($row->reason_code)->replace('_', ' ')->title()->toString()) }}</td><td>{{ $row->quality_disposition ? $qualityDispositionLabel($row->quality_disposition) : '—' }}</td><td class="text-end">{{ $numbers->format($row->returned_quantity) }}</td></tr>@empty{!! $emptyRow(5) !!}@endforelse</tbody></x-admin.report.table-card></div></div>
        @endif
    </x-admin.report.page>
</div>
@endsection
