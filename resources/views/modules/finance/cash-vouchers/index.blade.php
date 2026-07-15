@extends('modules.finance.partials.index', [
    'title' => $title,
    'resource' => $resource,
    'routePrefix' => $routePrefix,
    'tableId' => $tableId,
    'tableName' => $tableName,
    'columns' => $columns,
])

@push('scripts')
    <script>window.cashVoucherMessages = @json(__($translationKey.'.js'));</script>
    <script src="{{ asset('assets/js/modules/Finance/cash-vouchers.js') }}"></script>
@endpush
