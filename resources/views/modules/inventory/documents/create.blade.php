@extends('layouts.app')

@section('title', __('New Inventory Movement'))

@section('content')
<form class="card" method="POST" action="{{ route('admin.inventory.documents.store') }}">
    @csrf
        <x-forms.line-item-cards />
    <div class="card-header"><h5 class="mb-0">{{ __('New Inventory Movement') }}</h5></div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3"><label class="form-label">{{ __('Type') }}</label><select class="form-select" name="document_type" required>@foreach($allowedDocumentTypes as $type)<option value="{{ $type }}">{{ __(str($type)->replace('_', ' ')->title()->toString()) }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('Source store') }}</label><select class="form-select" name="branch_store_id" required>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('Destination store') }}</label><select class="form-select" name="destination_branch_store_id"><option value="">—</option>@foreach($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach</select></div>
            <div class="col-md-3"><label class="form-label">{{ __('Date') }}</label><input class="form-control" type="date" name="document_date" value="{{ now()->toDateString() }}" required></div>
            <div class="col-md-6"><label class="form-label">{{ __('Reason') }}</label><input class="form-control" name="movement_reason" required></div>
            <div class="col-md-3"><label class="form-label">{{ __('Source status') }}</label><input class="form-control" name="source_stock_status" value="available"></div>
            <div class="col-md-3"><label class="form-label">{{ __('Destination status') }}</label><input class="form-control" name="destination_stock_status" value="available"></div>
        </div>
        <hr>
        <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">{{ __('Movement lines') }}</h6>
            <button class="btn btn-falcon-default btn-sm" type="button" data-add-inventory-line><span class="fas fa-plus me-1"></span>{{ __('Add line') }}</button>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0" data-inventory-lines>
                <thead><tr><th style="min-width:260px">{{ __('Product') }}</th><th style="min-width:130px">{{ __('Quantity') }}</th><th style="min-width:150px">{{ __('Batch / lot') }}</th><th style="min-width:140px">{{ __('Unit cost') }}</th><th style="min-width:220px">{{ __('Notes') }}</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
    <div class="card-footer text-end"><button class="btn btn-primary" type="submit">{{ __('Post movement') }}</button></div>
</form>

<template id="inventory-line-template">
    <tr data-inventory-line>
        <td><select class="form-select form-select-sm" name="lines[__INDEX__][product_id]" required><option value="">{{ __('Select product') }}</option>@foreach($products as $product)<option value="{{ $product->id }}">{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach</select></td>
        <td><input class="form-control form-control-sm" type="number" step="0.00000001" min="0.00000001" name="lines[__INDEX__][quantity]" required></td>
        <td><input class="form-control form-control-sm" name="lines[__INDEX__][batch_lot]" maxlength="100"></td>
        <td><input class="form-control form-control-sm" type="number" step="0.00000001" min="0.00000001" name="lines[__INDEX__][unit_cost]"></td>
        <td><input class="form-control form-control-sm" name="lines[__INDEX__][notes]"></td>
        <td class="text-center"><button class="btn btn-link text-danger p-0" type="button" data-remove-inventory-line title="{{ __('Remove line') }}"><span class="fas fa-trash-alt"></span></button></td>
    </tr>
</template>
@endsection

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function () {
    const table = document.querySelector('[data-inventory-lines] tbody');
    const template = document.getElementById('inventory-line-template');
    const addButton = document.querySelector('[data-add-inventory-line]');

    if (!table || !template || !addButton) {
        return;
    }

    const renumber = function () {
        table.querySelectorAll('[data-inventory-line]').forEach(function (row, index) {
            row.querySelectorAll('[name]').forEach(function (field) {
                field.name = field.name.replace(/lines\[\d+\]/, 'lines[' + index + ']');
            });
        });
    };

    const addLine = function () {
        const index = table.querySelectorAll('[data-inventory-line]').length;
        const fragment = template.content.cloneNode(true);

        fragment.querySelectorAll('[name]').forEach(function (field) {
            field.name = field.name.replace('__INDEX__', index);
        });
        table.appendChild(fragment);
    };

    addButton.addEventListener('click', addLine);
    table.addEventListener('click', function (event) {
        const button = event.target.closest('[data-remove-inventory-line]');

        if (!button) {
            return;
        }

        const rows = table.querySelectorAll('[data-inventory-line]');
        if (rows.length === 1) {
            rows[0].querySelectorAll('input, select').forEach(function (field) {
                field.value = '';
            });
            return;
        }

        button.closest('[data-inventory-line]').remove();
        renumber();
    });

    addLine();
});
</script>
@endpush
