@props(['showAdjustments' => true, 'showFinancials' => true])

<div class="card-footer erp-document-summary" data-document-summary>
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
        <h6 class="mb-0 text-700">{{ $showFinancials ? __('sales_ui.financial_summary') : __('Sales lines') }}</h6>
        <button class="btn btn-falcon-default btn-sm" type="button" data-sales-add-line>
            <span class="fas fa-plus me-1"></span>{{ __('Add line') }}
        </button>
    </div>
    <div class="row g-3 align-items-start">
        <div class="{{ $showFinancials ? 'col-lg-6' : 'col-12' }}">
            <div class="row g-2">
                <div class="col-12 col-sm-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-600">{{ __('sales_ui.line_count') }}</div>
                        <div class="fw-semibold" data-sales-summary-lines>0</div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-600">{{ __('sales_ui.distinct_products') }}</div>
                        <div class="fw-semibold" data-sales-summary-products>0</div>
                    </div>
                </div>
                <div class="col-12 col-sm-4">
                    <div class="border rounded p-2 h-100">
                        <div class="small text-600">{{ __('sales_ui.total_quantity') }}</div>
                        <div class="fw-semibold" data-sales-summary-quantity>0</div>
                    </div>
                </div>
            </div>
        </div>
        @if($showFinancials)
        <div class="col-lg-6">
            <div class="table-responsive erp-document-total-box ms-lg-auto">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr>
                            <th>{{ __('sales_ui.items_subtotal') }}</th>
                            <td class="text-end" dir="ltr" data-sales-summary-subtotal>0</td>
                        </tr>
                        @if($showAdjustments)
                            <tr>
                                <th>{{ __('sales_ui.line_discounts') }}</th>
                                <td class="text-end" dir="ltr" data-sales-summary-discount>0</td>
                            </tr>
                            <tr>
                                <th>{{ __('sales_ui.taxable_amount') }}</th>
                                <td class="text-end" dir="ltr" data-sales-summary-taxable>0</td>
                            </tr>
                            <tr>
                                <th>{{ __('sales_ui.tax_total') }}</th>
                                <td class="text-end" dir="ltr" data-sales-summary-tax>0</td>
                            </tr>
                        @endif
                        <tr class="fw-bold">
                            <th>{{ __('sales_ui.grand_total') }}</th>
                            <td class="text-end" dir="ltr">
                                <span data-sales-summary-total>0</span>
                                <span data-sales-summary-currency></span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>
</div>
