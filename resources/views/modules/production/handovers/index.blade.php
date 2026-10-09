@extends('layouts.app')
@php
    $title = __($warehouse ? 'production_handover.warehouse_title' : 'production_handover.title');
    $prefix = $warehouse ? 'admin.inventory.production-receipts' : 'admin.production.handovers';
    $dates = app(\Modules\Core\Services\DateFormatService::class);
    $columns = [
        ['data' => 'doc_num', 'name' => 'inventory_documents.doc_num', 'className' => 'dt-code no-colvis all align-middle white-space-nowrap fw-semi-bold dtr-control', 'responsivePriority' => 1],
        ['data' => 'document_date', 'name' => 'inventory_documents.document_date', 'className' => 'dt-date align-middle white-space-nowrap', 'responsivePriority' => 8],
        ['data' => 'source_doc_num', 'name' => 'inventory_documents.source_doc_num', 'className' => 'dt-code align-middle white-space-nowrap', 'responsivePriority' => 12],
        ['data' => 'store', 'name' => 'store', 'orderable' => false, 'searchable' => false, 'className' => 'dt-text align-middle', 'responsivePriority' => 16],
        ['data' => 'status', 'name' => 'inventory_documents.status', 'className' => 'align-middle white-space-nowrap', 'responsivePriority' => 8],
        ['data' => 'actions', 'name' => 'actions', 'orderable' => false, 'searchable' => false, 'className' => 'dt-actions no-colvis all align-middle white-space-nowrap', 'responsivePriority' => 2],
    ];
@endphp
@section('title', $title)
@section('content')
<div class="production-mobile-workflow">
    <div class="alert alert-info">{{ __($warehouse ? 'production_handover.warehouse_help' : 'production_handover.list_help') }}</div>
    @if($warehouse && $handovers)
        <div class="card mb-3">
            <x-admin.crud-index-toolbar :title="__('production_handover.ready_handovers')" />
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead class="bg-100 text-900"><tr><th>{{ __('Document Number') }}</th><th>{{ __('Source') }}</th><th>{{ __('production_handover.date') }}</th><th>{{ __('common.fields.actions') }}</th></tr></thead>
                    <tbody>
                    @forelse($handovers as $handover)
                        <tr>
                            <td><a href="{{ route('admin.production.handovers.show', $handover) }}">{{ $handover->doc_num }}</a></td>
                            <td>{{ $handover->source_doc_num }}</td><td>{{ $dates->formatDate($handover->document_date) }}</td>
                            <td>@can('inventory.production_receipts.create')<a class="btn btn-falcon-success btn-sm" href="{{ route('admin.inventory.production-receipts.create', $handover) }}">{{ __('production_handover.warehouse_create') }}</a>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-center text-600 py-3">{{ __('No records found.') }}</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer">{{ $handovers->links() }}</div>
        </div>
    @endif
    <div class="card erp-datatable-card">
        <x-admin.crud-index-toolbar :title="$title">
            @if(!$warehouse)@can('production.runs.view')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.runs.index') }}">{{ __('production_execution.runs.title') }}</a>@endcan @endif
        </x-admin.crud-index-toolbar>
        <div class="card-body p-0"><div class="falcon-data-table"><div class="erp-datatable-wrapper"><div class="erp-datatable-scroll">
            <table id="production-handovers-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle" data-server-table data-url="{{ route($prefix.'.index') }}" data-order-column="0" data-order-direction="desc" data-columns='@json($columns)'>
                <thead class="bg-100 text-900"><tr><th>{{ __('Document Number') }}</th><th>{{ __('production_handover.date') }}</th><th>{{ __('Source') }}</th><th>{{ __('production_handover.store') }}</th><th>{{ __('Status') }}</th><th>{{ __('common.fields.actions') }}</th></tr></thead>
            </table>
        </div></div></div></div>
    </div>
</div>
@endsection
@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
@endpush
