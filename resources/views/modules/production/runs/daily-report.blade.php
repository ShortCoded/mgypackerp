@extends('layouts.app')
@section('title', __('production_daily_report.title'))
@section('content')
@php
    $numbers = app(\Modules\Core\Services\NumericFormatService::class);
@endphp
<div class="card mb-3"><div class="card-header d-flex justify-content-between"><h5>{{ __('production_daily_report.title') }} — {{ $record->batch?->batch_number ?? $record->run_number }}</h5><a href="{{ route('admin.production.runs.show', $record) }}" class="btn btn-falcon-default btn-sm">{{ __('Back') }}</a></div><div class="card-body">
    <p>{{ __('production_daily_report.help') }}</p>
    <div class="d-flex gap-2">@foreach(['injection', 'cover'] as $sheet)<a class="btn btn-sm {{ $kind === $sheet ? 'btn-primary' : 'btn-outline-primary' }}" href="{{ route('admin.production.runs.daily-reports.create', [$record, 'kind' => $sheet]) }}">{{ __('production_execution.shift_evidence.'.$sheet) }}</a>@endforeach</div>
</div></div>
@if($errors->any())<div class="alert alert-danger">@foreach($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>@endif
<form method="POST" action="{{ route('admin.production.runs.daily-reports.store', $record) }}">@csrf
<x-forms.input type="hidden" name="_submission_token" :value="(string) \Illuminate\Support\Str::uuid()" />
<x-forms.input type="hidden" name="sheet_kind" :value="$kind" />
<div class="card mb-3"><div class="card-body row g-3">
    <div class="col-md-3"><x-forms.label :label="__('production_execution.shift_evidence.work_date')" /><x-forms.date-input name="work_date" :value="old('work_date', now()->toDateString())" required /></div>
    <div class="col-md-3"><x-forms.label :label="__('production_execution.fields.shift')" /><x-forms.select variant="local" name="hr_shift_id" required><option value="">—</option>@foreach($shifts as $shift)<option value="{{ $shift->id }}" @selected((string) old('hr_shift_id') === (string) $shift->id)>{{ $shift->name }}</option>@endforeach</x-forms.select></div>
    @if($kind === 'injection')
    <div class="col-md-3"><x-forms.label :label="__('production_execution.shift_evidence.working_hours')" /><x-forms.numeric-input name="working_hours" :value="old('working_hours')" :scale="4" min="0.0001" max="24" required /></div>
    @else
    <div class="col-md-3"><x-forms.label :label="__('production_daily_report.from_time')" /><x-forms.input type="time" name="started_time" :value="old('started_time')" required /></div>
    <div class="col-md-3"><x-forms.label :label="__('production_daily_report.to_time')" /><x-forms.input type="time" name="ended_time" :value="old('ended_time')" required /></div>
    @endif
    <div class="col-md-6"><x-forms.label :label="__('production_daily_report.technician_names')" /><x-forms.input name="technician_names" :value="old('technician_names')" /></div>
    <div class="col-12 text-muted small">{{ __('production_daily_report.crew_help') }} @if($kind === 'injection'){{ __('production_daily_report.derived_time_help') }}@endif</div>
</div></div>
@foreach($runs as $index => $run)
<div class="card mb-3" data-daily-report-line><div class="card-header"><h6>{{ $run->product?->name }} — {{ $run->unit?->name }}</h6><span class="text-muted">{{ $run->productionMachine?->code ?? $run->fixedAsset?->doc_num }} — {{ $run->run_number }}</span></div><div class="card-body row g-3">
    <x-forms.input type="hidden" name="lines[{{ $index }}][run_public_id]" :value="$run->public_id" />
    <div class="col-md-4"><x-forms.label :label="__('production_daily_report.quantity').' — '.$run->unit?->name" /><x-forms.numeric-input name="lines[{{ $index }}][quantity]" :value="old('lines.'.$index.'.quantity')" :scale="8" min="0" /><div class="small text-muted">{{ __('production_daily_report.line_help') }}</div></div>
    @if(app(\Modules\Production\Services\ProductionStageTransferService::class)->isManaged($run))
    @foreach(['rejected', 'rework', 'scrap'] as $outcome)
    <div class="col-md-4"><x-forms.label :label="__('production_stage_transfer.output_'.$outcome).' — '.$run->unit?->name" /><x-forms.numeric-input name="lines[{{ $index }}][{{ $outcome }}_quantity]" :value="old('lines.'.$index.'.'.$outcome.'_quantity')" :scale="8" min="0" /></div>
    @endforeach
    @endif
    @if(app(\Modules\Production\Services\ProductionStageTransferService::class)->requiresInput($run))
    <div class="col-md-4"><x-forms.label :label="__('production_stage_transfer.input_quantity').' — '.$run->product?->unit?->name" /><x-forms.numeric-input name="lines[{{ $index }}][stage_input_base_quantity]" :value="old('lines.'.$index.'.stage_input_base_quantity')" :scale="8" min="0" /><div class="small text-muted">{{ __('production_stage_transfer.input_help') }}</div></div>
    @endif
    <div class="col-md-8"><x-forms.label :label="__('production_execution.fields.notes')" /><x-forms.input name="lines[{{ $index }}][notes]" :value="old('lines.'.$index.'.notes')" /></div>
    <details class="col-12"><summary>{{ __('production_daily_report.line_overrides') }}</summary><div class="row g-3 mt-1">
        <div class="col-12 small text-muted">{{ __('production_daily_report.line_defaults_help') }}</div>
        <div class="col-md-6"><x-forms.label :label="__('production_daily_report.technician_names')" /><x-forms.input name="lines[{{ $index }}][technician_names]" :value="old('lines.'.$index.'.technician_names')" /></div>
        @if($kind === 'injection')
        <div class="col-md-3"><x-forms.label :label="__('production_execution.shift_evidence.working_hours')" /><x-forms.numeric-input name="lines[{{ $index }}][working_hours]" :value="old('lines.'.$index.'.working_hours')" :scale="4" min="0.0001" max="24" /></div>
        @else
        <div class="col-md-3"><x-forms.label :label="__('production_daily_report.from_time')" /><x-forms.input type="time" name="lines[{{ $index }}][started_time]" :value="old('lines.'.$index.'.started_time')" /></div>
        <div class="col-md-3"><x-forms.label :label="__('production_daily_report.to_time')" /><x-forms.input type="time" name="lines[{{ $index }}][ended_time]" :value="old('lines.'.$index.'.ended_time')" /></div>
        @endif
    </div></details>
    <details class="col-12"><summary>{{ __('production_daily_report.optional_fields') }}</summary><div class="row g-3 mt-1">
        @php
            $numericFields = $kind === 'injection' ? ['cavities', 'cycle_seconds', 'piece_weight_grams', 'average_piece_weight_grams', 'carton_count', 'actual_pieces', 'actual_waste'] : ['machine_speed', 'actual_pieces', 'carton_count', 'packing_ratio', 'material_used', 'actual_waste', 'intact_roll_waste'];
        @endphp
        @foreach($numericFields as $field)<div class="col-md-3"><x-forms.label :label="__('production_daily_report.fields.'.$field)" /><x-forms.numeric-input name="lines[{{ $index }}][{{ $field }}]" :value="old('lines.'.$index.'.'.$field)" :scale="8" min="0" /></div>@endforeach
        @php
            $textFields = $kind === 'injection' ? ['bag_type', 'bag_size', 'carton_type', 'carton_size', 'waste_unit'] : ['customer_name', 'cover_components', 'carton_type', 'carton_size', 'material_unit', 'material_name', 'roll_reference', 'waste_unit'];
        @endphp
        @foreach($textFields as $field)<div class="col-md-3"><x-forms.label :label="__('production_daily_report.fields.'.$field)" /><x-forms.input name="lines[{{ $index }}][{{ $field }}]" :value="old('lines.'.$index.'.'.$field)" /></div>@endforeach
    </div></details>
</div></div>
@endforeach
<div class="text-end mb-3"><button class="btn btn-primary">{{ __('production_daily_report.save') }}</button></div>
</form>
@endsection
