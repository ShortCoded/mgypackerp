@extends('layouts.app')

@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $query = collect($filters)->filter(fn ($value) => filled($value))->all();
@endphp

@section('title', __('Procurement Cycle Report'))

@section('content')
    <x-admin.report.page
        class="procurement-cycle-report"
        :title="__('Procurement Cycle Report')"
        :description="__('Operational procurement, receiving, quality, supplier payable, return, and production-linked analysis.')"
    >
        @can('reports.purchases.export')
        <x-slot:actions>
            <x-admin.report.actions-toolbar
                filter-target="procurement-cycle-report-filters"
                :export-options="[
                    [
                        'url' => route('admin.purchases.procurement-cycle-report.export.excel', $query),
                        'label' => __('Export Excel'),
                        'icon' => 'file-excel',
                    ],
                    [
                        'url' => route('admin.purchases.procurement-cycle-report.print', $query),
                        'label' => __('Print / PDF'),
                        'icon' => 'print',
                        'newTab' => true,
                    ],
                ]"
            />
        </x-slot:actions>
        @endcan

        <x-admin.report.filter-panel
            id="procurement-cycle-report-filters"
            :title="__('Report filters')"
            :description="__('Filters are applied within the active company and financial period.')"
        >
            @php
                $fieldClass = 'form-control form-control-sm';
                $selectClass = 'form-select form-select-sm';
            @endphp
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-report-type">{{ __('Report') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-report-type" name="report_type">
                    @foreach($reportTypes as $type)
                        <option value="{{ $type }}" @selected($reportType === $type)>{{ __('procurement.reports.types.'.$type) }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-date-from">{{ __('From date') }}</label>
                <x-forms.date-input class="{{ $fieldClass }}" id="procurement-date-from" name="date_from" :value="$filters['date_from'] ?? ''" />
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-date-to">{{ __('To date') }}</label>
                <x-forms.date-input class="{{ $fieldClass }}" id="procurement-date-to" name="date_to" :value="$filters['date_to'] ?? ''" />
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-supplier">{{ __('Supplier') }}</label>
                <x-forms.select class="{{ $selectClass }} js-select2-ajax" data-url="{{ route('admin.purchases.select2.suppliers') }}" data-allow-clear="true" data-placeholder="{{ __('All') }}" id="procurement-supplier" name="supplier_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->doc_num }}" @selected(($filters['supplier_doc_num'] ?? '') === $supplier->doc_num)>{{ $supplier->doc_num }} / {{ $supplier->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-product">{{ __('Item') }}</label>
                <x-forms.select class="{{ $selectClass }} js-select2-ajax" data-url="{{ route('admin.purchases.select2.products') }}" data-allow-clear="true" data-placeholder="{{ __('All') }}" id="procurement-product" name="product_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($products as $product)
                        <option value="{{ $product->doc_num }}" @selected(($filters['product_doc_num'] ?? '') === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-pr">{{ __('Purchase requisition') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-pr" name="purchase_requisition_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($requisitions as $requisition)
                        <option value="{{ $requisition->doc_num }}" @selected(($filters['purchase_requisition_doc_num'] ?? '') === $requisition->doc_num)>{{ $requisition->doc_num }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-po">{{ __('Purchase order') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-po" name="purchase_order_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($orders as $order)
                        <option value="{{ $order->doc_num }}" @selected(($filters['purchase_order_doc_num'] ?? '') === $order->doc_num)>{{ $order->doc_num }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label" for="procurement-detail">{{ __('Detail level') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-detail" name="detail_level"><option value="summary">{{ __('Summary') }}</option><option value="lines" @selected(($filters['detail_level'] ?? '') === 'lines')>{{ __('Item lines') }}</option></x-forms.select>
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label" for="procurement-currency">{{ __('Currency') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-currency" name="currency_doc_num"><option value="">{{ __('All') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(($filters['currency_doc_num'] ?? '') === $currency->doc_num)>{{ $currency->doc_num }} / {{ $currency->name }}</option>@endforeach</x-forms.select>
            </div>
            @if($reportType === \Modules\Purchases\Services\Reports\ProcurementCycleReport::SupplierStatement)
            <div class="col-12 col-md-4 report-filter-field">
                <label class="form-label" for="procurement-document-type">{{ __('Document type') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-document-type" name="document_type"><option value="">{{ __('All') }}</option>
                    @foreach(['purchase_invoice' => 'Purchase Invoice', 'purchase_return' => 'Purchase Return', 'supplier_payment' => 'Supplier Payment', 'supplier_cheque_issue' => 'Cheque issue', 'supplier_cheque_clearing' => 'Cheque clearing', 'cash_voucher' => 'Cash Payment Voucher', 'cheque' => 'Cheque', 'opening_balance' => 'Opening balance', 'manual' => 'Journal Entry'] as $type => $label)<option value="{{ $type }}" @selected(($filters['document_type'] ?? '') === $type)>{{ __($label) }}</option>@endforeach
                </x-forms.select>
            </div>
            @endif
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-status">{{ __('Status') }}</label>
                <x-forms.input class="{{ $fieldClass }}" id="procurement-status" name="status" value="{{ $filters['status'] ?? '' }}" />
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-branch">{{ __('Branch') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-branch" name="branch_id">
                    <option value="">{{ __('All') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-warehouse">{{ __('Warehouse') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-warehouse" name="warehouse_uuid">
                    <option value="">{{ __('All') }}</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->public_uuid }}" @selected(($filters['warehouse_uuid'] ?? '') === $warehouse->public_uuid)>{{ $warehouse->name }}</option>
                    @endforeach
                </x-forms.select>
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-qc">{{ __('QC status') }}</label>
                <x-forms.input class="{{ $fieldClass }}" id="procurement-qc" name="qc_status" value="{{ $filters['qc_status'] ?? '' }}" />
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-production-order">{{ __('Production order') }}</label>
                <x-forms.input class="{{ $fieldClass }}" id="procurement-production-order" name="production_order_doc_num" value="{{ $filters['production_order_doc_num'] ?? '' }}" dir="ltr" />
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-work-order">{{ __('Work order reference') }}</label>
                <x-forms.input class="{{ $fieldClass }}" id="procurement-work-order" name="work_order_reference" value="{{ $filters['work_order_reference'] ?? '' }}" dir="ltr" />
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-overdue">{{ __('Overdue only') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-overdue" name="overdue">
                    <option value="">{{ __('No restriction') }}</option>
                    <option value="1" @selected(($filters['overdue'] ?? '') === '1')>{{ __('Yes') }}</option>
                </x-forms.select>
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-outstanding">{{ __('Outstanding only') }}</label>
                <x-forms.select class="{{ $selectClass }}" id="procurement-outstanding" name="outstanding">
                    <option value="">{{ __('No restriction') }}</option>
                    <option value="1" @selected(($filters['outstanding'] ?? '') === '1')>{{ __('Yes') }}</option>
                </x-forms.select>
            </div>
        </x-admin.report.filter-panel>

        @if(filled($filters['document_type'] ?? null))<p class="text-600">{{ __('Balances include all posted supplier movements; the document filter limits the displayed movements.') }}</p>@endif
        <div class="row g-2 mb-3">
            @foreach($metrics as $key => $value)
                <div class="col-6 col-md-4 col-xl">
                    <div class="card h-100"><div class="card-body py-2">
                        <div class="text-600 fs-11">{{ __('procurement.reports.metrics.'.$key) }}</div>
                        <div class="fs-7 fw-semibold" dir="ltr">{{ $numbers->format($value) }}</div>
                    </div></div>
                </div>
            @endforeach
        </div>

        @if($reportType === \Modules\Purchases\Services\Reports\ProcurementCycleReport::GoodsReceivedNotInvoiced)
            <div class="card mb-3">
                <div class="card-header py-2"><h6 class="mb-0">{{ __('Goods Received Not Invoiced') }}</h6></div>
                <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                    <thead class="bg-100"><tr><th>{{ __('Date / GRN') }}</th><th>{{ __('Supplier / PO') }}</th><th>{{ __('Product / Store') }}</th><th class="text-end">{{ __('Received Qty') }}</th><th class="text-end">{{ __('Invoiced Qty') }}</th><th class="text-end">{{ __('Returned Qty') }}</th><th class="text-end">{{ __('Remaining Qty') }}</th><th class="text-end">{{ __('Provisional Unit Value') }}</th><th class="text-end">{{ __('Remaining GRNI Value') }}</th><th>{{ __('Currency') }}</th><th class="text-end">{{ __('Days Outstanding') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>@forelse($rows as $row)<tr><td>{{ $row['date'] }}<br><span dir="ltr">{{ $row['document'] }}</span></td><td>{{ $row['supplier'] }}<br><span dir="ltr">{{ $row['purchase_order'] }}</span></td><td>{{ $row['product'] }}<br>{{ $row['warehouse'] }}</td><td class="text-end">{{ $numbers->format($row['received_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['invoiced_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['returned_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['remaining_quantity']) }}</td><td class="text-end">{{ $numbers->format($row['provisional_unit_value']) }}</td><td class="text-end">{{ $numbers->format($row['remaining_grni_value']) }}</td><td>{{ $row['currency'] }}</td><td class="text-end">{{ $row['age_days'] }}</td><td>{{ __($row['status'] === 'cleared' ? 'Cleared' : 'Open') }}</td></tr>@empty<tr><td colspan="12" class="text-center text-muted">{{ __('No matching records.') }}</td></tr>@endforelse</tbody>
                </table></div>
            </div>
            @if($grniReconciliation)
                <div class="card mb-3"><div class="card-header py-2"><h6 class="mb-0">{{ __('GRNI Subledger to General Ledger Reconciliation') }}</h6></div><div class="card-body"><div class="row g-2"><div class="col-md-3"><strong>{{ __('Account') }}</strong><div>{{ $grniReconciliation['account'] ?? __('Not configured') }}</div></div><div class="col-md-3"><strong>{{ __('Subledger') }}</strong><div dir="ltr">{{ $numbers->format($grniReconciliation['subledger']) }}</div></div><div class="col-md-3"><strong>{{ __('General Ledger') }}</strong><div dir="ltr">{{ $numbers->format($grniReconciliation['gl']) }}</div></div><div class="col-md-3"><strong>{{ __('Difference') }}</strong><div class="text-{{ $grniReconciliation['status'] === 'reconciled' ? 'success' : 'danger' }}" dir="ltr">{{ $numbers->format($grniReconciliation['difference']) }} — {{ __(str($grniReconciliation['status'])->replace('_', ' ')->title()->toString()) }}</div></div></div></div></div>
            @endif
        @endif

        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">{{ __('procurement.reports.types.'.$reportType) }}</h6>
                <span class="text-600 fs-10">{{ __('Records') }}: <span dir="ltr">{{ $rows->count() }}</span></span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive procurement-lines-scroll">
                    @if(app(\Modules\Purchases\Services\Reports\ProcurementCycleReport::class)->columns($reportType, $showPrices, $filters['detail_level'] ?? 'summary'))
@include('modules.purchases.procurement.report-columns')
@else
<table class="table table-sm table-hover align-middle mb-0 procurement-lines-table">
                        <thead class="bg-100"><tr>
                            <th>{{ __('Date') }}</th><th>{{ __('Document') }}</th><th>{{ __('Status') }}</th><th>{{ __('Supplier') }}</th>
                            <th>{{ __('Item') }}</th><th>{{ __('Purchase requisition') }}</th><th>{{ __('Purchase order') }}</th>
                            <th>{{ __('Branch / Warehouse') }}</th><th>{{ __('QC') }}</th><th>{{ __('Production / Work order') }}</th>
                            <th class="text-end">{{ __('Quantity') }}</th>@if($showPrices)<th class="text-end">{{ __('Amount') }}</th>@endif
                            <th class="text-end">{{ __('Outstanding') }}</th><th>{{ __('Overdue') }}</th>
                        </tr></thead>
                        <tbody>
                            @forelse($rows as $row)
                                <tr>
                                    <td dir="ltr">{{ $row['date'] ?: '—' }}</td><td dir="ltr">@if(filled($row['document_url'] ?? null) && auth()->user()?->can($row['document_permission']))<a href="{{ $row['document_url'] }}">{{ $row['document'] }}</a>@else{{ $row['document'] ?: '—' }}@endif</td>
                                    <td>{{ $row['status'] ? __('procurement.statuses.'.$row['status']) : '—' }}</td><td>{{ $row['supplier'] ?: '—' }}</td>
                                    <td>{{ $row['product'] ?: '—' }}</td><td dir="ltr">{{ $row['requisition'] ?: '—' }}</td><td dir="ltr">{{ $row['purchase_order'] ?: '—' }}</td>
                                    <td>{{ collect([$row['branch'], $row['warehouse']])->filter()->join(' / ') ?: '—' }}</td><td>{{ $row['qc_status'] ? __('procurement.statuses.'.$row['qc_status']) : '—' }}</td>
                                    <td dir="ltr">{{ collect([$row['production_order'], $row['work_order']])->filter()->join(' / ') ?: '—' }}</td>
                                    <td class="text-end" dir="ltr">{{ $numbers->format($row['quantity']) }}</td>
                                    @if($showPrices)<td class="text-end" dir="ltr">{{ $numbers->format($row['amount']) }}</td>@endif
                                    <td class="text-end" dir="ltr">{{ $numbers->format($row['outstanding']) }}</td>
                                    <td>{{ $row['overdue'] ? __('Yes') : __('No') }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="{{ $showPrices ? 14 : 13 }}" class="text-center text-600 py-4">{{ __('No matching records.') }}</td></tr>
                            @endforelse
                        </tbody>
                    </table>
@endif
                </div>
            </div>
        </div>
    </x-admin.report.page>
@endsection

@push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const panel = document.querySelector('#procurement-cycle-report-filters');
            const form = panel?.querySelector('.js-report-filters');

            form?.addEventListener('submit', function (event) {
                event.preventDefault();
                const parameters = new URLSearchParams(new FormData(form));
                window.location.assign('{{ route('admin.purchases.procurement-cycle-report.index') }}?' + parameters.toString());
            });

            panel?.querySelector('.js-report-reset')?.addEventListener('click', function () {
                window.location.assign('{{ route('admin.purchases.procurement-cycle-report.index') }}');
            });
        });
    </script>
@endpush
