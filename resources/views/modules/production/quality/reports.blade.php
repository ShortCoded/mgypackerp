@extends('layouts.app')

@section('title', __('production_execution.quality.reports_menu'))

@section('content')
    <div class="production-mobile-workflow">
        <div class="card mb-3">
            <div class="card-header"><div class="row flex-between-center g-2"><div class="col"><h5 class="mb-1">{{ __('production_execution.quality.reports_menu') }}</h5><p class="text-600 mb-0">{{ __('production_execution.quality.comprehensive_reports_help') }}</p></div><div class="col-auto d-flex flex-wrap gap-2">@can('production.quality.view')<a class="btn btn-falcon-default btn-sm" href="{{ route('admin.production.quality.index') }}">{{ __('production_execution.quality.all_inspections') }}</a>@endcan @can('production.quality.reports.export')<a class="btn btn-outline-success btn-sm" href="{{ route('admin.production.quality.export', request()->query()) }}"><span class="fas fa-file-excel me-1"></span>{{ __('production_execution.actions.export_excel') }}</a>@endcan @can('production.quality.reports.print')<a class="btn btn-outline-secondary btn-sm" target="_blank" href="{{ route('admin.production.quality.print', request()->query()) }}"><span class="fas fa-file-pdf me-1"></span>{{ __('production_execution.actions.print_pdf') }}</a>@endcan</div></div></div>
            <div class="card-body">
                <form method="GET" class="row g-2 align-items-end">
                    <div class="col-sm-6 col-lg-2"><x-forms.label for="quality-report-from" :label="__('production_execution.report_filters.from')" /><x-forms.date-input id="quality-report-from" name="from" :value="request('from')" /></div>
                    <div class="col-sm-6 col-lg-2"><x-forms.label for="quality-report-to" :label="__('production_execution.report_filters.to')" /><x-forms.date-input id="quality-report-to" name="to" :value="request('to')" /></div>
                    <div class="col-sm-6 col-lg-2"><x-forms.label for="quality-report-subject" :label="__('production_execution.fields.inspection_subject')" /><x-forms.select id="quality-report-subject" name="subject_type"><option value="">{{ __('production_execution.quality.all_subjects') }}</option>@foreach(['production_run', 'inventory_stock', 'product'] as $subject)<option value="{{ $subject }}" @selected(request('subject_type') === $subject)>{{ __('production_execution.quality_subjects.'.$subject) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-6 col-lg-2"><x-forms.label for="quality-report-status" :label="__('production_execution.fields.status')" /><x-forms.select id="quality-report-status" name="status"><option value="">{{ __('production_execution.quality.all_statuses') }}</option>@foreach(['draft', 'received', 'in_progress', 'submitted', 'approved', 'rejected', 'closed'] as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ __('production_execution.statuses.'.$status) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-6 col-lg-2"><x-forms.label for="quality-report-result" :label="__('production_execution.fields.result')" /><x-forms.select id="quality-report-result" name="result"><option value="">{{ __('production_execution.quality.all_results') }}</option>@foreach(['pending', 'passed', 'failed', 'conditional'] as $result)<option value="{{ $result }}" @selected(request('result') === $result)>{{ __('production_execution.quality_results.'.$result) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-6 col-lg-2"><x-forms.label for="quality-report-disposition" :label="__('production_execution.fields.disposition')" /><x-forms.select id="quality-report-disposition" name="disposition"><option value="">{{ __('production_execution.quality.all_dispositions') }}</option>@foreach(['release', 'hold', 'rework', 'scrap', 'return'] as $disposition)<option value="{{ $disposition }}" @selected(request('disposition') === $disposition)>{{ __('production_execution.quality_dispositions.'.$disposition) }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-6 col-lg-3"><x-forms.label for="quality-report-product" :label="__('production_execution.fields.product')" /><x-forms.select variant="ajax" id="quality-report-product" name="product_id" :url="route('admin.production.quality.select2', ['lookup' => 'products'])" :placeholder="__('common.actions.select')">@foreach($products as $product)<option value="{{ $product->id }}" selected>{{ $product->doc_num }} — {{ $product->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-6 col-lg-3"><x-forms.label for="quality-report-store" :label="__('production_execution.fields.store')" /><x-forms.select variant="ajax" id="quality-report-store" name="branch_store_id" :url="route('admin.production.quality.select2', ['lookup' => 'stores'])" :placeholder="__('common.actions.select')">@foreach($stores as $store)<option value="{{ $store->id }}" selected>{{ $store->name }}</option>@endforeach</x-forms.select></div>
                    <div class="col-sm-6 col-lg-3 d-flex gap-2"><button class="btn btn-primary flex-grow-1" type="submit"><span class="fas fa-filter me-1"></span>{{ __('production_execution.report_filters.apply') }}</button><a class="btn btn-falcon-default" href="{{ route('admin.production.quality.reports.index') }}">{{ __('common.actions.reset') }}</a></div>
                </form>
            </div>
        </div>

        <div class="row g-3 mb-3">
            @foreach(['total', 'open', 'passed', 'failed', 'affected_quantity'] as $metric)
                <div class="col-6 col-lg"><div class="card h-100"><div class="card-body py-3"><div class="text-600 small">{{ __('production_execution.quality.kpis.'.$metric) }}</div><div class="fs-4 fw-semibold">{{ $numbers->format($summary[$metric]) }}</div></div></div></div>
            @endforeach
        </div>

        <div class="card erp-datatable-card mb-3">
            <div class="card-header"><h6 class="mb-0">{{ __('production_execution.quality.inspection_records') }}</h6></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.quality.data', [...request()->query(), 'scope' => 'all']) }}" data-order-column="1" data-order-direction="desc" data-columns='[{"data":"doc_num","name":"quality_inspections.doc_num"},{"data":"requested_at","name":"requested_at"},{"data":"subject","name":"subject"},{"data":"stage_name","name":"stage_name","defaultContent":"—"},{"data":"reports_count","name":"reports_count","searchable":false},{"data":"result","name":"result"},{"data":"disposition","name":"disposition"},{"data":"affected_base_quantity","name":"affected_base_quantity","defaultContent":"—"},{"data":"evidence_count","name":"evidence_count","searchable":false},{"data":"status","name":"status"},{"data":"actions","name":"actions","orderable":false,"searchable":false}]'>
                    <thead><tr><th>{{ __('production_execution.fields.document') }}</th><th>{{ __('production_execution.fields.requested_at') }}</th><th>{{ __('production_execution.fields.inspection_subject') }}</th><th>{{ __('production_execution.fields.stage') }}</th><th>{{ __('production_execution.fields.reports_count') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.disposition') }}</th><th>{{ __('production_execution.fields.affected_quantity') }}</th><th>{{ __('production_execution.fields.attachments') }}</th><th>{{ __('production_execution.fields.status') }}</th><th></th></tr></thead>
                </table>
            </div>
        </div>

        <div class="card erp-datatable-card">
            <div class="card-header"><h6 class="mb-0">{{ __('production_execution.quality.daily_reports') }}</h6></div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 data-table erp-datatable" data-server-table data-url="{{ route('admin.production.quality.reports.data', request()->query()) }}" data-order-column="1" data-order-direction="desc" data-columns='[{"data":"inspection_number","name":"inspection_number"},{"data":"reported_at","name":"reported_at"},{"data":"sequence","name":"sequence"},{"data":"subject","name":"subject"},{"data":"result","name":"result"},{"data":"observations","name":"observations"},{"data":"evidence_count","name":"evidence_count","searchable":false},{"data":"submitted_by_name","name":"submitted_by_name","defaultContent":"—"}]'>
                    <thead><tr><th>{{ __('production_execution.fields.inspection') }}</th><th>{{ __('production_execution.fields.reported_at') }}</th><th>{{ __('production_execution.fields.report_number') }}</th><th>{{ __('production_execution.fields.inspection_subject') }}</th><th>{{ __('production_execution.fields.result') }}</th><th>{{ __('production_execution.fields.observations') }}</th><th>{{ __('production_execution.fields.attachments') }}</th><th>{{ __('production_execution.fields.submitted_by') }}</th></tr></thead>
                </table>
            </div>
        </div>
    </div>
@endsection

@pushOnce('styles', 'production-quality-css')<link rel="stylesheet" href="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/css/modules/Production/execution.css') }}">@endPushOnce
@pushOnce('scripts', 'production-quality-js')<script src="{{ app(\Modules\Core\Services\AssetVersionService::class)->url('assets/js/modules/Production/execution.js') }}"></script>@endPushOnce
