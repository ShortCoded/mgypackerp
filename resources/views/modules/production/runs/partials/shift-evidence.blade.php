@if(\Illuminate\Support\Facades\Schema::hasTable('production_shift_entries'))
@can('production.runs.setup')
<details class="card mb-3"><summary class="card-header">{{ __('production_execution.shift_evidence.defaults_title') }}</summary>
<form method="POST" action="{{ route('admin.production.runs.shift-defaults', $record) }}">@csrf
<div class="card-body row g-2">
    <div class="col-md-3"><x-forms.label :label="__('production_execution.shift_evidence.shift_code')" /><x-forms.input name="shift_code" required /></div>
    <div class="col-md-3"><x-forms.label :label="__('production_execution.fields.shift')" /><x-forms.input name="shift_name" required /></div>
    <div class="col-md-3"><x-forms.label :label="__('production_execution.shift_evidence.shift_start')" /><x-forms.input type="time" name="starts_at" required /></div>
    <div class="col-md-3"><x-forms.label :label="__('production_execution.shift_evidence.shift_end')" /><x-forms.input type="time" name="ends_at" required /></div>
    <div class="col-12"><p class="form-text">{{ __('production_execution.shift_evidence.defaults_help') }}</p>@include('modules.production.runs.partials.shift-crew-rows')</div>
</div><div class="card-footer"><button class="btn btn-primary btn-sm">{{ __('production_execution.shift_evidence.save_defaults') }}</button></div>
</form></details>
@endcan
@if(in_array($record->status, ['running', 'held'], true))
@can('production.runs.progress')
<details class="card mb-3"><summary class="card-header">{{ __('production_execution.shift_evidence.daily_title') }}</summary>
<form method="POST" action="{{ route('admin.production.runs.shifts.store', $record) }}">@csrf
<div class="card-body row g-2">
    <div class="col-md-4"><x-forms.label :label="__('production_execution.fields.shift')" /><x-forms.select variant="local" name="production_shift_id" required><option value="">—</option>@foreach($productionShifts ?? [] as $shift)<option value="{{ $shift->id }}">{{ $shift->code }} — {{ $shift->name }}</option>@endforeach</x-forms.select></div>
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.work_date')" /><x-forms.input type="date" name="work_date" :value="now()->toDateString()" required /></div>
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.sheet_kind')" /><x-forms.select variant="local" name="sheet_fields[sheet_kind]" required><option value="">—</option>@foreach(['injection', 'cover'] as $kind)<option value="{{ $kind }}">{{ __('production_execution.shift_evidence.'.$kind) }}</option>@endforeach</x-forms.select></div>
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.actual_start')" /><x-forms.input type="datetime-local" name="started_at" step="1" :value="now()->format('Y-m-d\\TH:i:s')" required /></div>
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.actual_end')" /><x-forms.input type="datetime-local" name="ended_at" /></div>
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.downtime')" /><x-forms.numeric-input name="downtime_minutes" :scale="4" min="0" max="1440" step="1" /></div>
    <div class="col-12"><x-forms.label :label="__('production_execution.shift_evidence.primary_material')" /><x-forms.select variant="local" name="sheet_fields[primary_material_requirement_public_id]"><option value="">—</option>@foreach($record->requirements as $requirement)<option value="{{ $requirement->public_id }}">{{ $requirement->product?->name }} — {{ $requirement->unit?->name }}</option>@endforeach</x-forms.select></div>
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.cavities')" /><x-forms.numeric-input name="sheet_fields[cavities]" :scale="0" min="1" step="1" /></div>
    @foreach(\Modules\Production\Services\ProductionShiftEvidenceService::NumericFields as $field)
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.'.$field)" /><x-forms.numeric-input name="sheet_fields[{{ $field }}]" :scale="8" min="0.00000001" step="0.00000001" arrow-step="1" /></div>
    @endforeach
    @foreach(\Modules\Production\Services\ProductionShiftEvidenceService::TextFields as $field)
    <div class="col-md-4"><x-forms.label :label="__('production_execution.shift_evidence.'.$field)" /><x-forms.input name="sheet_fields[{{ $field }}]" /></div>
    @endforeach
    <div class="col-12"><x-forms.label :label="__('production_execution.fields.notes')" /><x-forms.textarea name="notes" /></div>
    <div class="col-12"><details><summary>{{ __('production_execution.shift_evidence.override_title') }}</summary><p class="form-text">{{ __('production_execution.shift_evidence.override_help') }}</p>@include('modules.production.runs.partials.shift-crew-rows')</details></div>
</div><div class="card-footer"><button class="btn btn-primary btn-sm">{{ __('production_execution.shift_evidence.record_shift') }}</button></div>
</form></details>
@endcan
@endif
@endif
