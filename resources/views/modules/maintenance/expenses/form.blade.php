@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $title = $isView ? __('maintenance.expenses.view_document', ['document' => $record->doc_num]) : ($isEdit ? __('maintenance.expenses.edit_document', ['document' => $record->doc_num]) : __('maintenance.expenses.create'));
    $paymentChannel = old('payment_channel', $record?->payment_channel ?? 'cashbox');
@endphp

@section('title', $title)

@section('content')
    <form data-maintenance-form data-maintenance-expense-form method="POST" action="{{ $isEdit ? route('admin.maintenance.expenses.update', $record) : route('admin.maintenance.expenses.store') }}">
        @csrf
        @if($isEdit) @method('PUT') @endif
        <x-forms.input type="hidden" name="submit_action" value="save" />
        <div class="card mb-3">
            <div class="card-header py-2"><div class="row flex-between-center g-2"><div class="col"><h5 class="mb-0">{{ $title }}</h5></div><div class="col-auto">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.expenses', 'routePrefix' => 'admin.maintenance.expenses', 'canClone' => false, 'canEditRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted])</div></div></div>
            <div class="card-body">
                @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                <fieldset @disabled($isView)>
                    <div class="row g-3 align-items-start">
                        <div class="col-lg-6"><x-forms.label for="maintenance-expense-order" :label="__('maintenance.fields.work_order')" required /><x-forms.select variant="local" id="maintenance-expense-order" name="maintenance_work_order_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($orders as $order)<option value="{{ $order->id }}" @selected(old('maintenance_work_order_id', $record?->maintenance_work_order_id ?? request('order')) == $order->id)>{{ $order->doc_num }} — {{ $order->asset?->asset_name ?: $order->mold?->name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-expense-amount" :label="__('maintenance.fields.amount')" required /><x-forms.numeric-input id="maintenance-expense-amount" name="amount" :value="old('amount', $record?->amount)" :scale="4" min="0.0001" step="0.0001" required /></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-expense-currency" :label="__('maintenance.fields.currency')" required /><x-forms.select variant="local" id="maintenance-expense-currency" name="currency_id" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected(old('currency_id', $record?->currency_id) == $currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3"><x-forms.label for="maintenance-payment-channel" :label="__('maintenance.fields.payment_channel')" required /><x-forms.select variant="local" id="maintenance-payment-channel" name="payment_channel" :allow-clear="false" required><option value="cashbox" @selected($paymentChannel === 'cashbox')>{{ __('production_execution.payment_channels.cashbox') }}</option><option value="bank" @selected($paymentChannel === 'bank')>{{ __('production_execution.payment_channels.bank') }}</option></x-forms.select></div>
                        <div class="col-sm-6 col-lg-3" data-maintenance-cashbox-field @if($paymentChannel !== 'cashbox') hidden @endif><x-forms.label for="maintenance-expense-cashbox" :label="__('maintenance.fields.cashbox')" required /><x-forms.select variant="local" id="maintenance-expense-cashbox" name="cashbox_id" :required="$paymentChannel === 'cashbox'"><option value="">{{ __('common.placeholders.select') }}</option>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->id }}" @selected(old('cashbox_id', $record?->cashbox_id) == $cashbox->id)>{{ $cashbox->name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3" data-maintenance-bank-field @if($paymentChannel !== 'bank') hidden @endif><x-forms.label for="maintenance-expense-bank" :label="__('maintenance.fields.bank_account')" required /><x-forms.select variant="local" id="maintenance-expense-bank" name="bank_account_id" :required="$paymentChannel === 'bank'"><option value="">{{ __('common.placeholders.select') }}</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}" @selected(old('bank_account_id', $record?->bank_account_id) == $bank->id)>{{ $bank->account_name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-sm-6 col-lg-3" data-maintenance-cashbox-field @if($paymentChannel !== 'cashbox') hidden @endif><x-forms.label for="maintenance-expense-account" :label="__('maintenance.fields.expense_account')" required /><x-forms.select variant="local" id="maintenance-expense-account" name="expense_account_id" :required="$paymentChannel === 'cashbox'"><option value="">{{ __('common.placeholders.select') }}</option>@foreach($expenseAccounts as $account)<option value="{{ $account->id }}" @selected(old('expense_account_id', $record?->expense_account_id) == $account->id)>{{ $account->account_code }} — {{ $account->name }}</option>@endforeach</x-forms.select></div>
                        <div class="col-md-6"><x-forms.label for="maintenance-expense-reason" :label="__('maintenance.fields.reason')" required /><x-forms.input id="maintenance-expense-reason" name="reason" :value="old('reason', $record?->reason)" maxlength="2000" required /></div>
                        <div class="col-md-6"><x-forms.label for="maintenance-expense-notes" :label="__('maintenance.fields.notes')" /><x-forms.input id="maintenance-expense-notes" name="notes" :value="old('notes', $record?->notes)" maxlength="5000" /></div>
                    </div>
                </fieldset>
            </div>
            <div class="card-footer">@include('modules.finance.partials.form-actions', ['mode' => $mode, 'record' => $record, 'resource' => 'maintenance.expenses', 'routePrefix' => 'admin.maintenance.expenses', 'canClone' => false, 'canEditRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted])</div>
        </div>
    </form>
@endsection

@pushOnce('scripts', 'maintenance-execution-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce
