@extends('layouts.app')

@section('title', __('production_execution.runs.title'))

@section('content')
    <div class="production-mobile-workflow">
    @can('production.runs.plan')
        <div class="card mb-3"><div class="card-header"><h5 class="mb-0">{{ __('production_execution.runs.plan') }}</h5></div><div class="card-body"><form method="POST" action="{{ route('admin.production.runs.store') }}" class="row g-3 align-items-end">@csrf
            <div class="col-md-4"><label class="form-label">{{ __('production_execution.fields.order_line') }}</label><x-forms.select class="form-select" name="production_order_line_id" data-route-line required><option value="">{{ __('common.placeholders.select') }}</option>@foreach($orders as $order)@foreach($order->lines as $line)<option value="{{ $line->id }}">{{ $order->doc_num }} / {{ $line->product?->name }} / {{ $line->quantity }}</option>@endforeach @endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('production_execution.fields.stage') }}</label><x-forms.select class="form-select" name="production_order_stage_snapshot_id" data-route-stage><option value="">{{ __('production_execution.product_stages.no_route') }}</option>@foreach($orders as $order)@foreach($order->lines as $line)@foreach($line->stageSnapshots as $stage)<option value="{{ $stage->id }}" data-line-id="{{ $line->id }}">{{ $stage->sequence }}. {{ $stage->stage_name }}</option>@endforeach @endforeach @endforeach</x-forms.select></div>
            <div class="col-md-2"><label class="form-label">{{ __('production_execution.fields.quantity') }}</label><x-forms.input class="form-control" name="planned_quantity" type="number" step="0.00000001" min="0.00000001" required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('production_execution.fields.fixed_asset') }}</label><x-forms.select class="form-select" name="fixed_asset_id"><option value="">—</option>@foreach($assets as $asset)<option value="{{ $asset->id }}">{{ $asset->doc_num }} — {{ $asset->asset_name }}</option>@endforeach</x-forms.select></div>
            <div class="col-md-3"><label class="form-label">{{ __('production_execution.fields.starts_at') }}</label><x-forms.date-input name="planned_start_at" enable-time required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('production_execution.fields.ends_at') }}</label><x-forms.date-input name="planned_end_at" enable-time required /></div>
            <div class="col-md-3"><label class="form-label">{{ __('production_execution.fields.labor_count') }}</label><x-forms.input class="form-control" name="planned_labor_count" type="number" min="0" /></div>
            <div class="col-md-9"><label class="form-label">{{ __('production_execution.fields.work_description') }}</label><x-forms.input class="form-control" name="work_description" /></div>
            <div class="col-12" data-production-labor-planning>
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <label class="form-label mb-0">{{ __('production_execution.labor.planned_details') }}</label>
                    <button class="btn btn-falcon-primary btn-sm" type="button" data-add-labor-row><span class="fas fa-plus me-1"></span>{{ __('production_execution.actions.add_worker') }}</button>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>{{ __('production_execution.fields.worker_name') }}</th><th>{{ __('production_execution.fields.worker_role') }}</th><th>{{ __('production_execution.fields.planned_hours') }}</th><th>{{ __('production_execution.fields.notes') }}</th><th></th></tr></thead>
                        <tbody data-labor-rows>
                            <tr data-labor-row>
                                <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][name]" /></td>
                                <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][role]" /></td>
                                <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][planned_hours]" type="number" min="0" step="0.25" inputmode="decimal" /></td>
                                <td><x-forms.input class="form-control form-control-sm" name="labor_details[0][notes]" /></td>
                                <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <template data-labor-row-template>
                    <tr data-labor-row>
                        <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][name]" type="text"></td>
                        <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][role]" type="text"></td>
                        <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][planned_hours]" type="number" min="0" step="0.25" inputmode="decimal"></td>
                        <td><input class="form-control form-control-sm" name="labor_details[__INDEX__][notes]" type="text"></td>
                        <td><button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-row aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button></td>
                    </tr>
                </template>
            </div>
            <div class="col-12 col-md-3 ms-md-auto"><button class="btn btn-primary w-100">{{ __('production_execution.actions.create_run') }}</button></div>
        </form></div></div>
    @endcan
    <div class="card erp-datatable-card"><div class="card-header"><h5 class="mb-0">{{ __('production_execution.runs.title') }}</h5></div><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.runs.data') }}" data-order-column="5" data-order-direction="desc" data-columns='[{"data":"run_number","name":"production_runs.run_number"},{"data":"order_number","name":"order_number"},{"data":"product_name","name":"product_name"},{"data":"stage_name","name":"stage_name","defaultContent":"—"},{"data":"asset_name","name":"asset_name","defaultContent":"—"},{"data":"planned_start_at","name":"planned_start_at"},{"data":"planned_base_quantity","name":"planned_base_quantity"},{"data":"good_base_quantity","name":"good_base_quantity"},{"data":"status","name":"status"}]'><thead><tr><th>{{ __('production_execution.fields.run') }}</th><th>{{ __('production_execution.fields.production_order') }}</th><th>{{ __('production_execution.fields.product') }}</th><th>{{ __('production_execution.fields.stage') }}</th><th>{{ __('production_execution.fields.fixed_asset') }}</th><th>{{ __('production_execution.fields.starts_at') }}</th><th>{{ __('production_execution.fields.planned_quantity') }}</th><th>{{ __('production_execution.fields.accepted_quantity') }}</th><th>{{ __('production_execution.fields.status') }}</th></tr></thead></table></div></div>
    </div>
@endsection

@push('styles')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endpush
@push('scripts')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endpush
