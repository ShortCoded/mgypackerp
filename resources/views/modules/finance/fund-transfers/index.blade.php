@extends('modules.finance.partials.index', [
    'title' => $title,
    'resource' => $resource,
    'routePrefix' => $routePrefix,
    'tableId' => $tableId,
    'tableName' => $tableName,
    'columns' => $columns,
])

@push('scripts')
    <script>window.fundTransferMessages = @json(__('fund_transfers.js'));</script>
    <script src="{{ asset('assets/js/modules/Finance/fund-transfers.js') }}"></script>
@endpush
