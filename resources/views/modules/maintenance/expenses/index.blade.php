@extends('layouts.app')

@section('title', __('maintenance.expenses.title'))

@section('content')
    <div class="production-mobile-workflow">
        @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
        @can('maintenance.expenses.create')
            <form method="POST" action="{{ route('admin.maintenance.expenses.store') }}" class="card mb-3">@csrf
                <div class="card-header"><h5 class="mb-0">{{ __('maintenance.expenses.create') }}</h5></div><div class="card-body"><div class="row g-3 align-items-end">
                    <div class="col-12 col-md-4"><label class="form-label">{{ __('maintenance.fields.work_order') }}</label><x-forms.select name="maintenance_work_order_id" required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($orders as $order)<option value="{{ $order->id }}" @selected(old('maintenance_work_order_id', request('order')) == $order->id)>{{ $order->doc_num }} — {{ $order->asset?->asset_name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-6 col-md-2"><label class="form-label">{{ __('maintenance.fields.amount') }}</label><x-forms.input name="amount" type="number" min="0.0001" step="0.0001" required /></div>
                    <div class="col-6 col-md-2"><label class="form-label">{{ __('maintenance.fields.currency') }}</label><x-forms.select name="currency_id" required>@foreach($currencies as $currency)<option value="{{ $currency->id }}">{{ $currency->code }}</option>@endforeach</x-forms.select></div>
                    <div class="col-12 col-md-2"><label class="form-label">{{ __('maintenance.fields.payment_channel') }}</label><x-forms.select name="payment_channel"><option value="cashbox">{{ __('production_execution.payment_channels.cashbox') }}</option><option value="bank">{{ __('production_execution.payment_channels.bank') }}</option></x-forms.select></div>
                    <div class="col-12 col-md-3"><label class="form-label">{{ __('maintenance.fields.cashbox') }}</label><x-forms.select name="cashbox_id"><option value="">—</option>@foreach($cashboxes as $cashbox)<option value="{{ $cashbox->id }}">{{ $cashbox->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-12 col-md-3"><label class="form-label">{{ __('maintenance.fields.bank_account') }}</label><x-forms.select name="bank_account_id"><option value="">—</option>@foreach($bankAccounts as $bank)<option value="{{ $bank->id }}">{{ $bank->account_name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-12 col-md-3"><label class="form-label">{{ __('maintenance.fields.expense_account') }}</label><x-forms.select name="expense_account_id"><option value="">—</option>@foreach($expenseAccounts as $account)<option value="{{ $account->id }}">{{ $account->account_code }} — {{ $account->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-12 col-md-3"><label class="form-label">{{ __('maintenance.fields.reason') }}</label><x-forms.input name="reason" required /></div>
                </div></div><div class="card-footer d-grid d-sm-flex justify-content-sm-end"><button class="btn btn-primary">{{ __('maintenance.actions.submit_request') }}</button></div>
            </form>
        @endcan
        <div class="card erp-datatable-card"><div class="card-header"><h5 class="mb-0">{{ __('maintenance.expenses.title') }}</h5></div><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.maintenance.expenses.index') }}" data-order-column="1" data-order-direction="desc" data-columns='[{"data":"doc_num","name":"production_expense_requests.doc_num"},{"data":"request_date","name":"request_date"},{"data":"work_order_number","name":"work_order_number"},{"data":"asset_name","name":"asset_name"},{"data":"amount","name":"amount"},{"data":"currency_code","name":"currency_code"},{"data":"reason","name":"reason"},{"data":"voucher_number","name":"voucher_number","defaultContent":"—"},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'><thead><tr><th>{{ __('maintenance.fields.document') }}</th><th>{{ __('maintenance.fields.date') }}</th><th>{{ __('maintenance.fields.work_order') }}</th><th>{{ __('maintenance.fields.asset') }}</th><th>{{ __('maintenance.fields.amount') }}</th><th>{{ __('maintenance.fields.currency') }}</th><th>{{ __('maintenance.fields.reason') }}</th><th>{{ __('maintenance.fields.payment_voucher') }}</th><th>{{ __('maintenance.fields.status') }}</th><th></th></tr></thead></table></div></div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
