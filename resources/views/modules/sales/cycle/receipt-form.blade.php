@extends('layouts.app')

@section('title', __('Record Customer Collection'))

@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $selectedCustomer = $selectedInvoice?->customer ?? $selectedOrder?->customer;
    $selectedCurrency = $selectedInvoice?->currency ?? $selectedOrder?->currency;
@endphp
<form class="js-sales-cycle-form" data-sales-ui data-index-url="{{ route('admin.sales.customer-receipts.index') }}" data-create-url="{{ route('admin.sales.customer-receipts.create') }}" action="{{ route('admin.sales.customer-receipts.store') }}" method="POST" novalidate>
    @csrf
        <x-forms.line-item-cards :line-label="__('sales_ui.line')" />
    <div class="alert alert-danger d-none js-sales-form-alert"></div>
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center"><h5 class="mb-0">{{ __('Customer Receipt / Collection') }}</h5><a class="btn btn-falcon-default btn-sm" href="{{ route('admin.sales.customer-receipts.index') }}">{{ __('Back') }}</a></div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label" for="customer_doc_num">{{ __('Customer') }}</label><select class="form-select js-select2-ajax" id="customer_doc_num" name="customer_doc_num" required data-url="{{ route('admin.sales.select2.customers') }}" data-allow-clear="true"><option value="">{{ __('Select customer') }}</option>@foreach($customers as $customer)<option value="{{ $customer->doc_num }}" @selected(old('customer_doc_num', $selectedCustomer?->doc_num) === $customer->doc_num)>{{ $customer->doc_num }} / {{ $customer->name }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label" for="receipt_date">{{ __('Receipt date') }}</label><input class="form-control js-date-picker" id="receipt_date" name="receipt_date" value="{{ old('receipt_date', now()->toDateString()) }}" required></div>
                <div class="col-md-2"><label class="form-label" for="currency_doc_num">{{ __('Currency') }}</label><select class="form-select js-select2" id="currency_doc_num" name="currency_doc_num" required>@foreach($currencies as $currency)<option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num', $selectedCurrency?->doc_num) === $currency->doc_num || (! $selectedCurrency && $currency->is_main))>{{ $currency->doc_num }} / {{ $currency->code }}</option>@endforeach</select></div>
                <div class="col-md-2"><label class="form-label" for="amount">{{ __('Amount') }}</label><input class="form-control text-end" id="amount" name="amount" value="{{ old('amount', $numbers->formatForInput($suggestedAmount)) }}" inputmode="decimal" required></div>
                <div class="col-md-2"><label class="form-label" for="receipt_type">{{ __('Type') }}</label><select class="form-select" id="receipt_type" name="receipt_type"><option value="collection" @selected(! $selectedOrder || $selectedInvoice)>{{ __('Collection') }}</option><option value="advance" @selected($selectedOrder && ! $selectedInvoice)>{{ __('Advance') }}</option></select></div>
                <div class="col-md-3"><label class="form-label" for="payment_method">{{ __('Payment method') }}</label><select class="form-select" id="payment_method" name="payment_method" data-sales-payment-method><option value="cash">{{ __('Cash') }}</option><option value="bank">{{ __('Bank deposit') }}</option><option value="transfer">{{ __('Bank transfer') }}</option><option value="cheque">{{ __('Cheque') }}</option></select></div>
                <div class="col-md-3" data-payment-source="cash"><label class="form-label" for="cashbox_doc_num">{{ __('Cashbox') }}</label><select class="form-select js-select2-ajax" data-url="{{ route('admin.sales.select2.cashboxes') }}" id="cashbox_doc_num" name="cashbox_doc_num"><option value="">{{ __('Select cashbox') }}</option>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->doc_num }}">{{ $cashbox->doc_num }} / {{ $cashbox->name }}</option>@endforeach</select></div>
                <div class="col-md-3 d-none" data-payment-source="bank"><label class="form-label" for="bank_account_doc_num">{{ __('Bank account') }}</label><select class="form-select js-select2-ajax" data-url="{{ route('admin.sales.select2.bank-accounts') }}" id="bank_account_doc_num" name="bank_account_doc_num" disabled><option value="">{{ __('Select bank account') }}</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->doc_num }}">{{ $bank->doc_num }} / {{ $bank->account_name }}</option>@endforeach</select></div>
                <div class="col-md-3 d-none" data-payment-source="reference"><label class="form-label" for="reference_no">{{ __('Reference / cheque number') }}</label><input class="form-control" id="reference_no" name="reference_no" disabled></div>
                <div class="col-md-3 d-none" data-payment-source="cheque"><label class="form-label" for="cheque_due_date">{{ __('Cheque due date') }}</label><input class="form-control js-date-picker" id="cheque_due_date" name="cheque_due_date" value="{{ now()->toDateString() }}" disabled></div>
                <div class="col-md-3 d-none" data-payment-source="cheque"><label class="form-label" for="external_bank_name">{{ __('Drawer bank') }}</label><input class="form-control" id="external_bank_name" name="external_bank_name" disabled></div>
                <div class="col-md-6"><label class="form-label" for="notes">{{ __('Notes') }}</label><textarea class="form-control" id="notes" name="notes" rows="2"></textarea></div>
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
                    <td>{{ $schedule->invoice->doc_num }} / #{{ $schedule->sequence }}</td><td>{{ $schedule->invoice->customer->name }}</td><td>{{ $dates->format($schedule->due_date) }}</td><td class="text-end">{{ $numbers->format($schedule->outstanding_amount) }}</td>
                    <td><input type="hidden" name="allocations[{{ $index }}][invoice_schedule_public_id]" value="{{ $schedule->public_id }}" disabled><input class="form-control form-control-sm text-end" name="allocations[{{ $index }}][amount]" inputmode="decimal" max="{{ $schedule->outstanding_amount }}" disabled></td>
                </tr>
            @empty<tr><td colspan="6" class="text-center text-500 py-4">{{ __('No open posted installments found.') }}</td></tr>@endforelse
        </tbody></table></div>
    </div>
    @include('modules.finance.partials.form-actions', ['mode' => 'create', 'record' => null, 'resource' => 'customer_receipts', 'routePrefix' => 'admin.sales.customer-receipts', 'canEdit' => false, 'canClone' => false])
</form>
@endsection

@push('scripts')
@include('modules.sales.cycle.partials.scripts')
@endpush
