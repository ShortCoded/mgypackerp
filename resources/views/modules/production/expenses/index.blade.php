@extends('layouts.app')

@section('title', __('production_execution.expenses.title'))

@section('content')
    <div class="production-mobile-workflow">
    @can('production.expenses.create')
        <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('production_execution.expenses.create') }}</h5></div><div class="card-body"><form method="POST" action="{{ route('admin.production.expenses.store') }}" class="row g-3 align-items-end">@csrf
            <div class="col-md-4"><label class="form-label">{{ __('production_execution.fields.run') }}</label><x-forms.select class="form-select" name="production_run_id" required>@foreach($runs as $run)<option value="{{ $run->id }}">{{ $run->run_number }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('production_execution.fields.amount') }}</label><x-forms.input class="form-control" name="amount" type="number" min="0.0001" step="0.0001" required /></div>
            <div class="col-md-2"><label class="form-label">{{ __('production_execution.fields.currency') }}</label><x-forms.select class="form-select" name="currency_id" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('production_execution.fields.payment_channel') }}</label><x-forms.select class="form-select" name="payment_channel"><option value="cashbox">{{ __('production_execution.payment_channels.cashbox') }}</option><option value="bank">{{ __('production_execution.payment_channels.bank') }}</option></x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('production_execution.fields.cashbox') }}</label><x-forms.select class="form-select" name="cashbox_id"><option value="">—</option>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->id }}">{{ $cashbox->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('production_execution.fields.bank_account') }}</label><x-forms.select class="form-select" name="bank_account_id"><option value="">—</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}">{{ $bank->account_name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-4"><label class="form-label">{{ __('production_execution.fields.expense_account') }}</label><x-forms.select class="form-select" name="expense_account_id"><option value="">—</option>@foreach($expenseAccounts as $account)<option value="{{ $account->id }}">{{ $account->account_code }} — {{ $account->name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-4"><label class="form-label">{{ __('production_execution.fields.reason') }}</label><x-forms.input class="form-control" name="reason" required /></div>
            <div class="col-md-1"><button class="btn btn-primary w-100">{{ __('production_execution.actions.request') }}</button></div>
        </form></div></div>
    @endcan
    <div class="card erp-datatable-card"><div class="card-header"><h5 class="mb-0">{{ __('production_execution.expenses.title') }}</h5></div><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.expenses.index') }}" data-order-column="1" data-order-direction="desc" data-columns='[{"data":"doc_num","name":"production_expense_requests.doc_num"},{"data":"request_date","name":"request_date"},{"data":"run_number","name":"run_number"},{"data":"amount","name":"amount"},{"data":"currency_code","name":"currency_code"},{"data":"reason","name":"reason"},{"data":"voucher_number","name":"voucher_number","defaultContent":"—"},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'><thead><tr><th>{{ __('production_execution.fields.document') }}</th><th>{{ __('production_execution.fields.date') }}</th><th>{{ __('production_execution.fields.run') }}</th><th>{{ __('production_execution.fields.amount') }}</th><th>{{ __('production_execution.fields.currency') }}</th><th>{{ __('production_execution.fields.reason') }}</th><th>{{ __('production_execution.fields.payment_voucher') }}</th><th>{{ __('production_execution.fields.status') }}</th><th></th></tr></thead></table></div></div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
