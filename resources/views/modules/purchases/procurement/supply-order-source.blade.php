@extends('layouts.app')

@section('title', __('Create Supply Order'))

@section('content')
<div class="card mb-3">
    <div class="card-header py-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h5 class="mb-1">{{ __('Create Supply Order') }}</h5>
            <p class="text-600 fs-10 mb-0">{{ __('Choose the approved source document; its supplier, warehouse, and remaining lines will load automatically.') }}</p>
        </div>
        <a class="btn btn-falcon-default btn-sm" href="{{ route('admin.purchases.supply-orders.index') }}">
            <span class="fas fa-arrow-left me-1"></span>{{ __('common.actions.back') }}
        </a>
    </div>
    <div class="card-body">
        <div class="row g-4">
            <div class="col-lg-6">
                <form method="GET" action="{{ route('admin.purchases.supply-orders.create') }}" class="border rounded-3 p-3 h-100">
                    <h6>{{ __('From Purchase Order') }}</h6>
                    <p class="text-600 fs-10">{{ __('Use this path as soon as the purchase order is approved; a supplier invoice is not required.') }}</p>
                    <label class="form-label" for="supply_source_purchase_order">{{ __('Purchase Order') }}</label>
                    <select class="form-select js-select2-ajax" id="supply_source_purchase_order" name="purchase_order" data-url="{{ route('admin.purchases.select2.purchase-orders', ['purpose' => 'supply_order']) }}" data-placeholder="{{ __('Select') }}" required></select>
                    <button class="btn btn-falcon-primary btn-sm mt-3" type="submit">{{ __('Load remaining lines') }}</button>
                </form>
            </div>
            <div class="col-lg-6">
                <form method="GET" action="{{ route('admin.purchases.supply-orders.create') }}" class="border rounded-3 p-3 h-100">
                    <h6>{{ __('From Purchase Invoice') }}</h6>
                    <p class="text-600 fs-10">{{ __('Use an approved supplier invoice that is linked to its purchase order.') }}</p>
                    <label class="form-label" for="supply_source_purchase_invoice">{{ __('Purchase Invoice') }}</label>
                    <select class="form-select js-select2-ajax" id="supply_source_purchase_invoice" name="purchase_invoice" data-url="{{ route('admin.purchases.select2.invoices', ['purpose' => 'supply_order']) }}" data-placeholder="{{ __('Select') }}" required></select>
                    <button class="btn btn-falcon-primary btn-sm mt-3" type="submit">{{ __('Load remaining lines') }}</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
