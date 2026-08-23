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
                <select class="{{ $selectClass }}" id="procurement-report-type" name="report_type">
                    @foreach($reportTypes as $type)
                        <option value="{{ $type }}" @selected($reportType === $type)>{{ str($type)->replace('_', ' ')->title() }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-date-from">{{ __('From date') }}</label>
                <input class="{{ $fieldClass }}" id="procurement-date-from" name="date_from" type="date" value="{{ $filters['date_from'] ?? '' }}">
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-date-to">{{ __('To date') }}</label>
                <input class="{{ $fieldClass }}" id="procurement-date-to" name="date_to" type="date" value="{{ $filters['date_to'] ?? '' }}">
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-supplier">{{ __('Supplier') }}</label>
                <select class="{{ $selectClass }}" id="procurement-supplier" name="supplier_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($suppliers as $supplier)
                        <option value="{{ $supplier->doc_num }}" @selected(($filters['supplier_doc_num'] ?? '') === $supplier->doc_num)>{{ $supplier->doc_num }} / {{ $supplier->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-product">{{ __('Item') }}</label>
                <select class="{{ $selectClass }}" id="procurement-product" name="product_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($products as $product)
                        <option value="{{ $product->doc_num }}" @selected(($filters['product_doc_num'] ?? '') === $product->doc_num)>{{ $product->doc_num }} / {{ $product->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-pr">{{ __('Purchase requisition') }}</label>
                <select class="{{ $selectClass }}" id="procurement-pr" name="purchase_requisition_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($requisitions as $requisition)
                        <option value="{{ $requisition->doc_num }}" @selected(($filters['purchase_requisition_doc_num'] ?? '') === $requisition->doc_num)>{{ $requisition->doc_num }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-12 col-md-6 col-xl-3 report-filter-field">
                <label class="form-label mb-1" for="procurement-po">{{ __('Purchase order') }}</label>
                <select class="{{ $selectClass }}" id="procurement-po" name="purchase_order_doc_num">
                    <option value="">{{ __('All') }}</option>
                    @foreach($orders as $order)
                        <option value="{{ $order->doc_num }}" @selected(($filters['purchase_order_doc_num'] ?? '') === $order->doc_num)>{{ $order->doc_num }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-status">{{ __('Status') }}</label>
                <input class="{{ $fieldClass }}" id="procurement-status" name="status" value="{{ $filters['status'] ?? '' }}">
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-branch">{{ __('Branch') }}</label>
                <select class="{{ $selectClass }}" id="procurement-branch" name="branch_id">
                    <option value="">{{ __('All') }}</option>
                    @foreach($branches as $branch)
                        <option value="{{ $branch->id }}" @selected((string) ($filters['branch_id'] ?? '') === (string) $branch->id)>{{ $branch->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-warehouse">{{ __('Warehouse') }}</label>
                <select class="{{ $selectClass }}" id="procurement-warehouse" name="warehouse_uuid">
                    <option value="">{{ __('All') }}</option>
                    @foreach($warehouses as $warehouse)
                        <option value="{{ $warehouse->public_uuid }}" @selected(($filters['warehouse_uuid'] ?? '') === $warehouse->public_uuid)>{{ $warehouse->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-qc">{{ __('QC status') }}</label>
                <input class="{{ $fieldClass }}" id="procurement-qc" name="qc_status" value="{{ $filters['qc_status'] ?? '' }}">
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-production-order">{{ __('Production order') }}</label>
                <input class="{{ $fieldClass }}" id="procurement-production-order" name="production_order_doc_num" value="{{ $filters['production_order_doc_num'] ?? '' }}" dir="ltr">
            </div>
            <div class="col-6 col-md-4 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-work-order">{{ __('Work order reference') }}</label>
                <input class="{{ $fieldClass }}" id="procurement-work-order" name="work_order_reference" value="{{ $filters['work_order_reference'] ?? '' }}" dir="ltr">
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-overdue">{{ __('Overdue only') }}</label>
                <select class="{{ $selectClass }}" id="procurement-overdue" name="overdue">
                    <option value="">{{ __('No restriction') }}</option>
                    <option value="1" @selected(($filters['overdue'] ?? '') === '1')>{{ __('Yes') }}</option>
                </select>
            </div>
            <div class="col-6 col-md-3 col-xl-2 report-filter-field">
                <label class="form-label mb-1" for="procurement-outstanding">{{ __('Outstanding only') }}</label>
                <select class="{{ $selectClass }}" id="procurement-outstanding" name="outstanding">
                    <option value="">{{ __('No restriction') }}</option>
                    <option value="1" @selected(($filters['outstanding'] ?? '') === '1')>{{ __('Yes') }}</option>
                </select>
            </div>
        </x-admin.report.filter-panel>

        <div class="row g-2 mb-3">
            @foreach($metrics as $key => $value)
                <div class="col-6 col-md-4 col-xl">
                    <div class="card h-100"><div class="card-body py-2">
                        <div class="text-600 fs-11">{{ str($key)->replace('_', ' ')->title() }}</div>
                        <div class="fs-7 fw-semibold" dir="ltr">{{ $numbers->format($value) }}</div>
                    </div></div>
                </div>
            @endforeach
        </div>

        <div class="card">
            <div class="card-header py-2 d-flex justify-content-between align-items-center">
                <h6 class="mb-0">{{ str($reportType)->replace('_', ' ')->title() }}</h6>
                <span class="badge badge-subtle-secondary">{{ $rows->count() }}</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive procurement-lines-scroll">
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
                                    <td dir="ltr">{{ $row['date'] ?: '—' }}</td><td dir="ltr">{{ $row['document'] ?: '—' }}</td>
                                    <td>{{ str((string) $row['status'])->replace('_', ' ')->title() ?: '—' }}</td><td>{{ $row['supplier'] ?: '—' }}</td>
                                    <td>{{ $row['product'] ?: '—' }}</td><td dir="ltr">{{ $row['requisition'] ?: '—' }}</td><td dir="ltr">{{ $row['purchase_order'] ?: '—' }}</td>
                                    <td>{{ collect([$row['branch'], $row['warehouse']])->filter()->join(' / ') ?: '—' }}</td><td>{{ $row['qc_status'] ?: '—' }}</td>
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
