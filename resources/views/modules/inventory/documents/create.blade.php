@extends('layouts.app')

@section('title', __('New Inventory Movement'))

@section('content')
<form class="card" method="POST" action="{{ route('admin.inventory.documents.store') }}">
    @csrf
    <div class="card-header"><h5 class="mb-0">{{ __('New Inventory Movement') }}</h5></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">{{ __('Type') }}</label><select class="form-select" name="document_type" required>@foreach(['inventory_transfer','inventory_adjustment_in','inventory_adjustment_out','inventory_damage','inventory_scrap'] as $type)<option value="{{ $type }}">{{ str($type)->replace('_', ' ')->title() }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('Source store') }}</label><select class="form-select" name="branch_store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('Destination store') }}</label><select class="form-select" name="destination_branch_store_id"><option value="">—</option>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('Date') }}</label><input class="form-control" type="date" name="document_date" value="{{ now()->toDateString() }}" required></div>
            <div class="col-md-6"><label class="form-label">{{ __('Reason') }}</label><input class="form-control" name="movement_reason" required></div>
            <div class="col-md-3"><label class="form-label">{{ __('Source status') }}</label><input class="form-control" name="source_stock_status" value="available"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Destination status') }}</label><input class="form-control" name="destination_stock_status" value="available"></div>
        </div>
        <hr>
        <h6>{{ __('Movement line') }}</h6>
        <div class="row g-3">
            <div class="col-md-5"><label class="form-label">{{ __('Product') }}</label><select class="form-select" name="lines[0][product_id]" required>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('Quantity') }}</label><input class="form-control" type="number" step="0.00000001" min="0.00000001" name="lines[0][quantity]" required></div>
            <div class="col-md-2"><label class="form-label">{{ __('Batch / lot') }}</label><input class="form-control" name="lines[0][batch_lot]"></div>
            <div class="col-md-2"><label class="form-label">{{ __('Unit cost') }}</label><input class="form-control" type="number" step="0.00000001" min="0" name="lines[0][unit_cost]"></div>
        </div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary" type="submit">{{ __('Post movement') }}</button></div>
</form>
@endsection
