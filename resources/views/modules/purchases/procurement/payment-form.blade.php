@extends('layouts.app')
@section('title', __('Supplier Payment'))

@section('content')
<form method="POST" action="{{ route('admin.purchases.supplier-payments.store') }}" class="js-supplier-payment-form">
    @csrf
    <div class="card mb-3">
        <div class="card-header"><h5 class="mb-0">{{ __('Supplier Payment / Advance') }}</h5></div>
        <div class="card-body">
            @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="supplier_doc_num">{{ __('Supplier') }}</label>
                    <select class="form-select js-payment-supplier" id="supplier_doc_num" name="supplier_doc_num" required>
                        <option value="">{{ __('Select') }}</option>
                        @foreach($suppliers as $supplier)
                            <option value="{{ $supplier->doc_num }}" @selected(old('supplier_doc_num') === $supplier->doc_num)>{{ $supplier->doc_num }} / {{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="payment_date">{{ __('Payment date') }}</label>
                    <input class="form-control" id="payment_date" type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="payment_method">{{ __('Payment method') }}</label>
                    <select class="form-select js-payment-method" id="payment_method" name="payment_method" required>
                        @foreach(\Modules\Purchases\Models\SupplierPaymentContext::methods() as $method)
                            <option value="{{ $method }}" @selected(old('payment_method', 'cash') === $method)>{{ str($method)->title() }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="currency_doc_num">{{ __('Currency') }}</label>
                    <select class="form-select" id="currency_doc_num" name="currency_doc_num" required>
                        @foreach($currencies as $currency)
                            <option value="{{ $currency->doc_num }}" @selected(old('currency_doc_num') === $currency->doc_num)>{{ $currency->doc_num }} / {{ $currency->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="amount">{{ __('Amount') }}</label>
                    <x-forms.numeric-input id="amount" name="amount" :value="old('amount')" :scale="4" min="0.0001" step="0.0001" required />
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="exchange_rate">{{ __('Exchange rate') }}</label>
                    <x-forms.numeric-input id="exchange_rate" name="exchange_rate" :value="old('exchange_rate', 1)" :scale="6" min="0.000001" step="0.000001" required />
                </div>
                <div class="col-md-3 js-cash-source">
                    <label class="form-label" for="cashbox_doc_num">{{ __('Cashbox') }}</label>
                    <select class="form-select" id="cashbox_doc_num" name="cashbox_doc_num">
                        <option value="">{{ __('Select') }}</option>
                        @foreach($cashboxes as $cashbox)
                            <option value="{{ $cashbox->doc_num }}" @selected(old('cashbox_doc_num') === $cashbox->doc_num)>{{ $cashbox->doc_num }} / {{ $cashbox->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6 js-bank-source d-none">
                    <label class="form-label" for="bank_account_doc_num">{{ __('Bank / branch / account') }}</label>
                    <select class="form-select" id="bank_account_doc_num" name="bank_account_doc_num">
                        <option value="">{{ __('Select') }}</option>
                        @foreach($bankAccounts as $bankAccount)
                            <option value="{{ $bankAccount->doc_num }}" @selected(old('bank_account_doc_num') === $bankAccount->doc_num)>
                                {{ $bankAccount->bank?->name ?? $bankAccount->bank?->name_en ?? __('Bank') }} / {{ $bankAccount->bank_branch_name ?: '—' }} / {{ $bankAccount->account_number }} / {{ $bankAccount->account_name }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 js-cheque-source d-none"><label class="form-label" for="cheque_number">{{ __('Cheque number') }}</label><input class="form-control" id="cheque_number" name="cheque_number" value="{{ old('cheque_number') }}" dir="ltr"></div>
                <div class="col-md-3 js-cheque-source d-none"><label class="form-label" for="cheque_date">{{ __('Cheque date') }}</label><input class="form-control" id="cheque_date" type="date" name="cheque_date" value="{{ old('cheque_date', now()->toDateString()) }}"></div>
                <div class="col-md-3 js-cheque-source d-none"><label class="form-label" for="cheque_due_date">{{ __('Due date') }}</label><input class="form-control" id="cheque_due_date" type="date" name="cheque_due_date" value="{{ old('cheque_due_date', now()->toDateString()) }}"></div>
                <div class="col-md-3">
                    <label class="form-label" for="purchase_order_doc_num">{{ __('Related PO (advance)') }}</label>
                    <select class="form-select" id="purchase_order_doc_num" name="purchase_order_doc_num"><option value="">{{ __('None') }}</option>@foreach($orders as $order)<option value="{{ $order->doc_num }}" @selected(old('purchase_order_doc_num') === $order->doc_num)>{{ $order->doc_num }}</option>@endforeach</select>
                </div>
                <div class="col-md-3 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="is_advance" value="1" id="is_advance" @checked(old('is_advance'))><label class="form-check-label" for="is_advance">{{ __('Supplier advance / allow unapplied balance') }}</label></div></div>
                <div class="col-md-6"><label class="form-label" for="reason">{{ __('Reason') }}</label><input class="form-control" id="reason" name="reason" value="{{ old('reason') }}"></div>
                <div class="col-md-6"><label class="form-label" for="notes">{{ __('Notes') }}</label><input class="form-control" id="notes" name="notes" value="{{ old('notes') }}"></div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header"><h6 class="mb-0">{{ __('Invoice / installment allocations') }}</h6></div>
        <div class="card-body p-0"><div class="table-responsive procurement-lines-scroll"><table class="table table-sm align-middle mb-0 procurement-lines-table">
            <thead class="bg-100"><tr><th>{{ __('Supplier') }}</th><th>{{ __('Invoice') }}</th><th>{{ __('Installment') }}</th><th class="text-end">{{ __('Outstanding') }}</th><th>{{ __('Allocate') }}</th></tr></thead>
            <tbody>
                @php $allocationIndex = 0; @endphp
                @foreach($invoices as $invoice)
                    @forelse($invoice->paymentSchedules as $schedule)
                        <tr class="js-allocation-row" data-supplier="{{ $invoice->supplier?->doc_num }}"><td>{{ $invoice->supplier?->name }}</td><td dir="ltr">{{ $invoice->doc_num }}<input type="hidden" name="allocations[{{ $allocationIndex }}][purchase_invoice_doc_num]" value="{{ $invoice->doc_num }}"></td><td>{{ $schedule->due_date?->format('Y-m-d') }}<input type="hidden" name="allocations[{{ $allocationIndex }}][payment_schedule_public_id]" value="{{ $schedule->public_id }}"></td><td class="text-end" dir="ltr">{{ max(0, (float) $schedule->amount - (float) $schedule->paid_amount - (float) $schedule->credited_amount) }}</td><td><x-forms.numeric-input name="allocations[{{ $allocationIndex }}][amount]" :value="old('allocations.'.$allocationIndex.'.amount')" :scale="4" min="0.0001" step="0.0001" /></td></tr>
                        @php $allocationIndex++; @endphp
                    @empty
                        <tr class="js-allocation-row" data-supplier="{{ $invoice->supplier?->doc_num }}"><td>{{ $invoice->supplier?->name }}</td><td dir="ltr">{{ $invoice->doc_num }}<input type="hidden" name="allocations[{{ $allocationIndex }}][purchase_invoice_doc_num]" value="{{ $invoice->doc_num }}"></td><td>—</td><td class="text-end" dir="ltr">{{ $invoice->remaining_amount }}</td><td><x-forms.numeric-input name="allocations[{{ $allocationIndex }}][amount]" :value="old('allocations.'.$allocationIndex.'.amount')" :scale="4" min="0.0001" step="0.0001" /></td></tr>
                        @php $allocationIndex++; @endphp
                    @endforelse
                @endforeach
            </tbody>
        </table></div></div>
    </div>
    <div class="d-flex justify-content-end"><button class="btn btn-primary">{{ __('Create Supplier payment') }}</button></div>
</form>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.js-supplier-payment-form');
    if (!form) return;
    const method = form.querySelector('.js-payment-method');
    const supplier = form.querySelector('.js-payment-supplier');
    const update = function () {
        const selectedMethod = method.value;
        form.querySelectorAll('.js-cash-source').forEach((node) => node.classList.toggle('d-none', selectedMethod !== 'cash'));
        form.querySelectorAll('.js-bank-source').forEach((node) => node.classList.toggle('d-none', !['bank', 'cheque'].includes(selectedMethod)));
        form.querySelectorAll('.js-cheque-source').forEach((node) => node.classList.toggle('d-none', selectedMethod !== 'cheque'));
        form.querySelectorAll('.js-allocation-row').forEach((row) => row.classList.toggle('d-none', supplier.value !== '' && row.dataset.supplier !== supplier.value));
    };
    method.addEventListener('change', update);
    supplier.addEventListener('change', update);
    update();
});
</script>
@endpush
