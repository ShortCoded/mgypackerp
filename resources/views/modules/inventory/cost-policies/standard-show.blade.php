@extends('layouts.app')
@section('title', __('inventory_standard_cost.title').' — '.$record->doc_num)
@section('content')
<div class="container-fluid py-3">
    <a class="btn btn-falcon-default btn-sm mb-3" href="{{ route('admin.inventory.standard-costs.index') }}">{{ __('Back') }}</a>
    <h4>{{ __('inventory_standard_cost.title') }} — {{ $record->doc_num }}</h4>
    <x-admin.report.actions-toolbar class="mb-3" :show-filters="false" :show-refresh="false" :export-options="[
        ['permission' => 'inventory.cost_policies.standard.export', 'url' => route('admin.inventory.standard-costs.output', ['kind' => $kind, 'uuid' => $record->public_uuid, 'format' => 'xlsx']), 'label' => __('reports.export_excel'), 'icon' => 'file-excel'],
        ['permission' => 'inventory.cost_policies.standard.export', 'url' => route('admin.inventory.standard-costs.output', ['kind' => $kind, 'uuid' => $record->public_uuid, 'format' => 'csv']), 'label' => __('reports.export_csv'), 'icon' => 'file-csv'],
        ['permission' => 'inventory.cost_policies.standard.print', 'url' => route('admin.inventory.standard-costs.output', ['kind' => $kind, 'uuid' => $record->public_uuid, 'format' => 'pdf']), 'label' => __('reports.export_pdf'), 'icon' => 'file-pdf', 'newTab' => true],
    ]" />
    @if($errors->any())<div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @foreach($sections as $section)
    <x-admin.report.table-card :title="$section['title']" :table-id="'standard-section-'.$loop->index" class="mb-3">
        <thead><tr>@foreach($section['headings'] as $heading)<th>{{ $heading }}</th>@endforeach</tr></thead><tbody>@foreach($section['rows'] as $row)<tr>@foreach($row as $value)<td class="text-nowrap" @if(preg_match('/^-?\d+\.\d{8}$/D', $value)) dir="ltr" @endif>{{ $value === '' ? '—' : $value }}</td>@endforeach</tr>@endforeach</tbody>
    </x-admin.report.table-card>
    @endforeach
    @if($record->rejection_reason)<div class="alert alert-secondary">{{ $record->rejection_reason }}</div>@endif
    @if($record->status === 'prepared' && (int) $record->prepared_by !== (int) auth()->id())
    @can('inventory.cost_policies.standard.approve')<div class="card"><div class="card-body row g-3">@foreach(['approve', 'reject'] as $decision)<div class="col-md-6"><form novalidate method="POST" action="{{ route('admin.inventory.standard-costs.decision', ['kind' => $kind, 'uuid' => $record->public_uuid, 'decision' => $decision]) }}">@csrf<x-forms.label :for="'std_'.$decision" :label="__('inventory_standard_cost.reference')" :required="true" /><x-forms.input :id="'std_'.$decision" name="reference" :value="old('reference')" /><button class="btn mt-2 {{ $decision === 'approve' ? 'btn-success' : 'btn-outline-danger' }}">{{ __('inventory_standard_cost.'.$decision) }}</button></form></div>@endforeach</div></div>@endcan
    @endif
</div>
@endsection
