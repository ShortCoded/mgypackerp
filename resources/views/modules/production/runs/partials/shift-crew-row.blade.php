<div class="row g-2 mb-2" data-shift-crew-row>
    <div class="col-md-5"><x-forms.select variant="ajax" name="crew[{{ $index }}][employee_id]" :url="route('admin.production.runs.select2.workers', ['identifier' => 'id'])" :placeholder="__('production_execution.fields.worker_name')"><option value="">—</option></x-forms.select></div>
    <div class="col-md-3"><x-forms.select variant="local" name="crew[{{ $index }}][role]"><option value="">—</option>@foreach(['supervisor', 'technician', 'operator', 'foreman'] as $role)<option value="{{ $role }}">{{ __('production_execution.shift_evidence.role_'.$role) }}</option>@endforeach</x-forms.select></div>
    <div class="col-md-3"><x-forms.numeric-input name="crew[{{ $index }}][planned_hours]" :scale="4" min="0" max="24" step="0.25" :placeholder="__('production_execution.fields.planned_hours')" /></div>
    <div class="col-md-1"><button class="btn btn-outline-danger btn-sm" type="button" data-remove-shift-worker aria-label="{{ __('common.actions.delete') }}">×</button></div>
</div>
