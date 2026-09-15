@extends('layouts.app')

@section('title', __('inventory.movements.title'))

@section('content')
    <div class="card erp-datatable-card production-mobile-workflow">
        <div class="card-header">
            <div class="row flex-between-center g-2">
                <div class="col-auto"><h5 class="mb-0">{{ __('inventory.movements.title') }}</h5></div>
                <div class="col-auto ms-auto">
                    @can('inventory.documents.view_trashed')<div class="btn-group btn-group-sm me-2"><a class="btn btn-falcon-default" href="{{ route('admin.inventory.documents.index') }}">{{ __('production_execution.actions.active') }}</a><a class="btn btn-falcon-default" href="{{ route('admin.inventory.documents.index', ['trash_filter' => 'trashed']) }}">{{ __('production_execution.actions.deleted') }}</a><a class="btn btn-falcon-default" href="{{ route('admin.inventory.documents.index', ['trash_filter' => 'all']) }}">{{ __('production_execution.actions.all') }}</a></div>@endcan
                    @can('inventory.documents.create')
                        <x-buttons.add-record :href="route('admin.inventory.documents.create')" permission="inventory.documents.create" />
                    @endcan
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="falcon-data-table">
                <div class="erp-datatable-wrapper">
                    <div class="erp-datatable-scroll">
                        <table id="inventory-movements-table" class="table table-sm table-hover mb-0 data-table erp-datatable align-middle"
                            data-server-table
                            data-url="{{ route('admin.inventory.documents.data', array_filter(['trash_filter' => request('trash_filter')])) }}"
                            data-table-name="inventory_documents"
                            data-order-column="2"
                            data-order-direction="desc"
                            data-columns='[{"data":"doc_num","name":"inventory_documents.doc_num"},{"data":"document_type","name":"inventory_documents.document_type"},{"data":"document_date","name":"inventory_documents.document_date"},{"data":"source_store","name":"source_store"},{"data":"destination_store","name":"destination_store"},{"data":"lines_count","name":"lines_count"},{"data":"status_label","name":"status_label"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'>
                            <thead class="bg-100 text-900">
                                <tr>
                                    <th class="all no-colvis dt-code">{{ __('inventory.movements.fields.document') }}</th>
                                    <th>{{ __('inventory.movements.fields.type') }}</th>
                                    <th>{{ __('inventory.movements.fields.date') }}</th>
                                    <th>{{ __('inventory.movements.fields.source_store') }}</th>
                                    <th>{{ __('inventory.movements.fields.destination_store') }}</th>
                                    <th>{{ __('inventory.movements.fields.lines_count') }}</th>
                                    <th>{{ __('inventory.movements.fields.status') }}</th>
                                    <th class="all no-colvis dt-actions"></th>
                                </tr>
                            </thead>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection

@push('styles')
    <link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">
@endpush
@push('scripts')
    <script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>
@endpush
