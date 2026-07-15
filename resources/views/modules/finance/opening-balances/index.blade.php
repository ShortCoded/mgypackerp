@extends('modules.finance.partials.index', ['title' => __('opening_balances.title'), 'createLabel' => __('opening_balances.create'), 'resource' => 'opening_balances', 'routePrefix' => 'admin.finance.opening-balances', 'tableId' => 'opening-balances-table', 'columns' => ['doc_num', 'document_date', 'currency', 'total_debit', 'total_credit', 'state', 'created_by', 'created_at', 'updated_by', 'updated_at']])

@push('scripts')
    <script>window.openingBalanceMessages = @json(__('opening_balances.js'));</script>
    <script src="{{ asset('assets/js/modules/Finance/opening-balances.js') }}"></script>
@endpush
