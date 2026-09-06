@extends('layouts.app')

@section('title', __('Record Customer Collection'))

@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $selectedCustomer = $selectedInvoice?->customer ?? $selectedOrder?->customer;
    $selectedCurrency = $selectedInvoice?->currency ?? $selectedOrder?->currency;
    $selectedEmployeeDocNum = old('received_by_employee_doc_num');
    $selectedPaymentMethod = old('payment_method', 'cash');
    $formDate = fn (string $field) => $dates->formatDate(
        $dates->parseDate((string) old($field, now()->toDateString())),
        '',
    );
@endphp
<form class="js-sales-cycle-form" data-sales-ui data-index-url="{{ route('admin.sales.customer-receipts.index') }}" data-create-url="{{ route('admin.sales.customer-receipts.create') }}" action="{{ route('admin.sales.customer-receipts.store') }}" method="POST" novalidate>
    @csrf
        <x-forms.line-item-cards :line-label="__('sales_ui.line')" />
    <div class="alert alert-danger d-none js-sales-form-alert"></div>
    <div class="card mb-3">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <h5 class="mb-0">{{ __('Customer Receipt / Collection') }}</h5>
            @include('modules.finance.partials.form-actions', ['mode' => 'create', 'record' => null, 'resource' => 'customer_receipts', 'routePrefix' => 'admin.sales.customer-receipts', 'canEdit' => false, 'canClone' => false])
        </div>
        <div class="card-body">
            <div class="row g-3 align-items-start">
                <div class="col-md-4">
                    <label class="form-label" for="customer_doc_num">{{ __('Customer') }} <span class="text-danger">*</span></label>
                    <select class="form-select js-select2-ajax" id="customer_doc_num" name="customer_doc_num" required data-url="{{ route('admin.sales.select2.customers') }}" data-allow-clear="true">
                        <option value="">{{ __('Select customer') }}</option>
                        @foreach($customers as $customer)<option value="{{ $customer->doc_num }}" @selected(old('customer_doc_num', $selectedCustomer?->doc_num) === $customer->doc_num)>{{ $customer->doc_num }} / {{ $customer->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="received_by_employee_doc_num">{{ __('sales_ui.received_by_employee') }} <span class="text-danger">*</span></label>
                    <select class="form-select js-select2-ajax" id="received_by_employee_doc_num" name="received_by_employee_doc_num" required data-url="{{ route('admin.sales.select2.employees') }}" data-allow-clear="true" data-placeholder="{{ __('sales_ui.select_receiving_employee') }}">
                        <option value="">{{ __('sales_ui.select_receiving_employee') }}</option>
                        @foreach($receivedByEmployees as $employee)<option value="{{ $employee->doc_num }}" @selected($selectedEmployeeDocNum === $employee->doc_num)>{{ $employee->doc_num }} / {{ $employee->full_name ?: $employee->name }}</option>@endforeach
                    </select>
                    <small class="form-text text-muted">{{ __('sales_ui.received_by_employee_help') }} @can('hr.employees.create')<a href="{{ route('admin.hr.employees.create') }}">{{ __('sales_ui.add_employee') }}</a>@endcan</small>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="receipt_date">{{ __('Receipt date') }} <span class="text-danger">*</span></label>
                    <input class="form-control js-date-picker" id="receipt_date" name="receipt_date" value="{{ $formDate('receipt_date') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="receipt_type">{{ __('Type') }} <span class="text-danger">*</span></label>
                    <select class="form-select" id="receipt_type" name="receipt_type" required>
                        <option value="collection" @selected(old('receipt_type', (! $selectedOrder || $selectedInvoice) ? 'collection' : 'advance') === 'collection')>{{ __('Collection') }}</option>
                        <option value="advance" @selected(old('receipt_type', ($selectedOrder && ! $selectedInvoice) ? 'advance' : 'collection') === 'advance')>{{ __('Advance') }}</option>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label" for="currency_doc_num">{{ __('Currency') }} <span class="text-danger">*</span></label>
                    <select class="form-select js-select2-ajax" id="currency_doc_num" name="currency_doc_num" data-url="{{ route('admin.select2.currencies') }}" data-placeholder="{{ __('Currency') }}" required>
                        @foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num', $selectedCurrency?->doc_num) === $currency->doc_num || (! $selectedCurrency && $currency->is_main))>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="amount">{{ __('Amount') }} <span class="text-danger">*</span></label>
                    <input class="form-control text-end" id="amount" name="amount" value="{{ old('amount', $numbers->formatForInput($suggestedAmount)) }}" inputmode="decimal" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="payment_method">{{ __('Payment method') }} <span class="text-danger">*</span></label>
                    <select class="form-select" id="payment_method" name="payment_method" data-sales-payment-method required>
                        @foreach(['cash' => __('Cash'), 'bank' => __('Bank deposit'), 'transfer' => __('Bank transfer'), 'cheque' => __('Cheque')] as $value => $label)<option value="{{ $value }}" @selected($selectedPaymentMethod === $value)>{{ $label }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3" data-payment-source="cash">
                    <label class="form-label" for="cashbox_doc_num">{{ __('Cashbox') }} <span class="text-danger">*</span></label>
                    <select class="form-select js-select2-ajax" data-url="{{ route('admin.sales.select2.cashboxes') }}" data-required-for="cash" id="cashbox_doc_num" name="cashbox_doc_num">
                        <option value="">{{ __('Select cashbox') }}</option>
                        @foreach($cashboxes as $cashbox)<option value="{{ $cashbox->doc_num }}" @selected(old('cashbox_doc_num') === $cashbox->doc_num)>{{ $cashbox->doc_num }} / {{ $cashbox->name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3 d-none" data-payment-source="bank">
                    <label class="form-label" for="bank_account_doc_num">{{ __('Bank account') }} <span class="text-danger">*</span></label>
                    <select class="form-select js-select2-ajax" data-url="{{ route('admin.sales.select2.bank-accounts') }}" data-required-for="bank,transfer,cheque" id="bank_account_doc_num" name="bank_account_doc_num" disabled>
                        <option value="">{{ __('Select bank account') }}</option>
                        @foreach($bankAccounts as $bank)<option value="{{ $bank->doc_num }}" @selected(old('bank_account_doc_num') === $bank->doc_num)>{{ $bank->doc_num }} / {{ $bank->account_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3 d-none" data-payment-source="reference">
                    <label class="form-label" for="reference_no"><span data-payment-reference-label data-bank-label="{{ __('sales_ui.bank_reference') }}" data-cheque-label="{{ __('Cheque number') }}">{{ __('sales_ui.bank_reference') }}</span> <span class="text-danger d-none" data-reference-required>*</span></label>
                    <input class="form-control" id="reference_no" name="reference_no" value="{{ old('reference_no') }}" data-required-for="cheque" dir="ltr" disabled>
                </div>
                <div class="col-md-3 d-none" data-payment-source="cheque">
                    <label class="form-label" for="cheque_due_date">{{ __('Cheque due date') }} <span class="text-danger">*</span></label>
                    <input class="form-control js-date-picker" id="cheque_due_date" name="cheque_due_date" value="{{ $formDate('cheque_due_date') }}" data-required-for="cheque" disabled>
                </div>
                <div class="col-md-3 d-none" data-payment-source="cheque">
                    <label class="form-label" for="external_bank_name">{{ __('Drawer bank') }} <span class="text-danger">*</span></label>
                    <input class="form-control" id="external_bank_name" name="external_bank_name" value="{{ old('external_bank_name') }}" data-required-for="cheque" disabled>
                </div>
                <div class="col-12"><label class="form-label" for="notes">{{ __('Notes') }}</label><textarea class="form-control" id="notes" name="notes" rows="2">{{ old('notes') }}</textarea></div>
                @if($selectedOrder)<input type="hidden" name="sales_order_doc_num" value="{{ $selectedOrder->doc_num }}">@endif
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ __('Allocate to open invoice installments') }}</h6><small class="text-600">{{ __('Leave installments unchecked to create an unapplied customer advance.') }}</small></div>
        <div class="table-responsive"><table class="table table-sm table-bordered align-middle mb-0" style="min-width:850px"><thead><tr><th></th><th>{{ __('Invoice') }}</th><th>{{ __('Customer') }}</th><th>{{ __('Due date') }}</th><th class="text-end">{{ __('Outstanding') }}</th><th>{{ __('Allocate') }}</th></tr></thead><tbody>
            @forelse($schedules as $index => $schedule)
                <tr data-receipt-allocation-row data-customer="{{ $schedule->invoice->customer->doc_num }}">
                    <td><input class="form-check-input" type="checkbox" data-receipt-allocation-toggle></td>
                    <td>{{ $schedule->invoice->doc_num }} / #{{ $schedule->sequence }}</td><td>{{ $schedule->invoice->customer->name }}</td><td>{{ $dates->formatDate($schedule->due_date) }}</td><td class="text-end">{{ $numbers->format($schedule->outstanding_amount) }}</td>
                    <td><input type="hidden" name="allocations[{{ $index }}][invoice_schedule_public_id]" value="{{ $schedule->public_id }}" disabled><input class="form-control form-control-sm text-end" name="allocations[{{ $index }}][amount]" inputmode="decimal" max="{{ $schedule->outstanding_amount }}" disabled></td>
                </tr>
            @empty<tr><td colspan="6" class="text-center text-500 py-4">{{ __('No open posted installments found.') }}</td></tr>@endforelse
        </tbody></table></div>
    </div>
</form>
@endsection

@push('scripts')
@include('modules.sales.cycle.partials.scripts')
@endpush
