@extends('layouts.app')

@php
    $isView = $mode === 'view';
    $isEdit = $mode === 'edit';
    $isClone = $mode === 'clone';
    $title = $isView
        ? __('production_execution.expenses.view_document', ['document' => $record->doc_num])
        : ($isEdit ? __('production_execution.expenses.edit_document', ['document' => $record->doc_num]) : ($isClone ? __('production_execution.expenses.clone_document', ['document' => $record->doc_num]) : __('production_execution.expenses.create')));
    $paymentChannel = old('payment_channel', $record?->payment_channel ?? 'cashbox');
@endphp

@section('title', $title)

@section('content')
    <div class="production-mobile-workflow">
        <form method="POST" action="{{ $isEdit ? route('admin.production.expenses.update', $record) : route('admin.production.expenses.store') }}" data-production-expense-form>
            @csrf
            @if($isEdit) @method('PUT') @endif
            <x-forms.input type="hidden" name="submit_action" value="save" />
            <div class="card mb-3">
                <div class="card-header py-2"><div class="row flex-between-center g-2">
                    <div class="col"><h5 class="mb-0">{{ $title }}</h5>@if($record && ! $isClone)<span class="badge badge-subtle-secondary mt-1">{{ __('production_execution.statuses.'.$record->status) }}</span>@endif</div>
                    <div class="col-auto d-flex flex-wrap gap-2">
                        @if($record && ! $isClone) @can('production.expenses.print')<a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.production.expenses.print', $record) }}"><span class="fas fa-print me-1"></span>{{ __('common.actions.print') }}</a>@endcan @endif
                        @include('modules.finance.partials.form-actions', ['mode' => $isClone ? 'clone' : $mode, 'record' => $record, 'resource' => 'production.expenses', 'routePrefix' => 'admin.production.expenses', 'canEditRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted])
                    </div>
                </div></div>
                <div class="card-body">
                    @if($errors->any())<div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
                    <fieldset @disabled($isView)>
                        <div class="row g-3 align-items-start">
                            <div class="col-lg-6"><x-forms.label for="production-expense-run" :label="__('production_execution.fields.run')" required /><x-forms.select variant="local" id="production-expense-run" name="production_run_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($runs as $run)<option value="{{ $run->id }}" @selected((int) old('production_run_id', $record?->production_run_id ?? request('run')) === (int) $run->id)>{{ $run->run_number }} — {{ $run->stageSnapshot?->stage_name ?: '—' }}</option>@endforeach</x-forms.select></div>
                            <div class="col-sm-6 col-lg-3"><x-forms.label for="production-expense-amount" :label="__('production_execution.fields.amount')" required /><x-forms.numeric-input id="production-expense-amount" name="amount" :value="old('amount', $record?->amount)" :scale="4" min="0.0001" step="0.0001" arrow-step="1" required /></div>
                            <div class="col-sm-6 col-lg-3"><x-forms.label for="production-expense-currency" :label="__('production_execution.fields.currency')" required /><x-forms.select variant="local" id="production-expense-currency" name="currency_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($currencies as $currency)<option value="{{ $currency->id }}" @selected((int) old('currency_id', $record?->currency_id) === (int) $currency->id)>{{ $currency->code }} — {{ $currency->name }}</option>@endforeach</x-forms.select></div>
                            <div class="col-sm-6 col-lg-3"><x-forms.label for="production-expense-payment-channel" :label="__('production_execution.fields.payment_channel')" required /><x-forms.select variant="local" id="production-expense-payment-channel" name="payment_channel" :allow-clear="false" data-production-payment-channel required><option value="cashbox" @selected($paymentChannel === 'cashbox')>{{ __('production_execution.payment_channels.cashbox') }}</option><option value="bank" @selected($paymentChannel === 'bank')>{{ __('production_execution.payment_channels.bank') }}</option></x-forms.select></div>
                            <div class="col-sm-6 col-lg-3" data-production-cashbox-field @if($paymentChannel !== 'cashbox') hidden @endif><x-forms.label for="production-expense-cashbox" :label="__('production_execution.fields.cashbox')" :required="$paymentChannel === 'cashbox'" /><x-forms.select variant="local" id="production-expense-cashbox" name="cashbox_id" :required="$paymentChannel === 'cashbox'"><option value="">{{ __('common.placeholders.select') }}</option>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->id }}" @selected((int) old('cashbox_id', $record?->cashbox_id) === (int) $cashbox->id)>{{ $cashbox->name }}</option>@endforeach</x-forms.select></div>
                            <div class="col-sm-6 col-lg-3" data-production-bank-field @if($paymentChannel !== 'bank') hidden @endif><x-forms.label for="production-expense-bank" :label="__('production_execution.fields.bank_account')" :required="$paymentChannel === 'bank'" /><x-forms.select variant="local" id="production-expense-bank" name="bank_account_id" :required="$paymentChannel === 'bank'"><option value="">{{ __('common.placeholders.select') }}</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}" @selected((int) old('bank_account_id', $record?->bank_account_id) === (int) $bank->id)>{{ $bank->account_name }}</option>@endforeach</x-forms.select></div>
                            <div class="col-sm-6 col-lg-3" data-production-cashbox-field @if($paymentChannel !== 'cashbox') hidden @endif><x-forms.label for="production-expense-account" :label="__('production_execution.fields.expense_account')" :required="$paymentChannel === 'cashbox'" /><x-forms.select variant="local" id="production-expense-account" name="expense_account_id" :required="$paymentChannel === 'cashbox'"><option value="">{{ __('common.placeholders.select') }}</option>@foreach($expenseAccounts as $account)<option value="{{ $account->id }}" @selected((int) old('expense_account_id', $record?->expense_account_id) === (int) $account->id)>{{ $account->account_code }} — {{ $account->name }}</option>@endforeach</x-forms.select></div>
                            <div class="col-md-6"><x-forms.label for="production-expense-reason" :label="__('production_execution.fields.reason')" required /><x-forms.textarea id="production-expense-reason" name="reason" rows="3" maxlength="2000" required>{{ old('reason', $record?->reason) }}</x-forms.textarea></div>
                            <div class="col-md-6"><x-forms.label for="production-expense-notes" :label="__('production_execution.fields.notes')" /><x-forms.textarea id="production-expense-notes" name="notes" rows="3" maxlength="5000">{{ old('notes', $record?->notes) }}</x-forms.textarea></div>
                        </div>
                    </fieldset>
                </div>
                <div class="card-footer d-flex flex-wrap justify-content-between gap-2">
                    <div>@if($record && ! $isClone) @can('production.expenses.print')<a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="{{ route('admin.production.expenses.print', $record) }}"><span class="fas fa-print me-1"></span>{{ __('common.actions.print') }}</a>@endcan @endif</div>
                    @include('modules.finance.partials.form-actions', ['mode' => $isClone ? 'clone' : $mode, 'record' => $record, 'resource' => 'production.expenses', 'routePrefix' => 'admin.production.expenses', 'canEditRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted, 'canDeleteRecord' => $record?->status === \Modules\Production\Models\ProductionExpenseRequest::StatusSubmitted])
                </div>
            </div>
        </form>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
