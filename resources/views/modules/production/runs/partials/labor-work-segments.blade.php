<div data-labor-days>
    <div data-labor-day-rows>
        @foreach($labor['work_segments'] ?? [] as $dayIndex => $segment)
            <div class="d-flex gap-1 mb-1" data-labor-day>
                <x-forms.date-input class="form-control-sm" name="labor_details[{{ $index }}][work_segments][{{ $dayIndex }}][work_date]" :value="$segment['work_date']" :aria-label="__('production_execution.fields.work_date')" />
                <x-forms.numeric-input class="form-control-sm" name="labor_details[{{ $index }}][work_segments][{{ $dayIndex }}][actual_hours]" :scale="8" :value="$segment['actual_hours']" :aria-label="__('production_execution.fields.actual_hours')" />
                <button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-day aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button>
            </div>
        @endforeach
    </div>
    <button class="btn btn-sm btn-outline-secondary" type="button" data-add-labor-day>{{ __('production_execution.actions.add_work_day') }}</button>
    <template data-labor-day-template>
        <div class="d-flex gap-1 mb-1" data-labor-day>
            <x-forms.date-input class="form-control-sm" name="labor_details[{{ $index }}][work_segments][__DAY__][work_date]" :aria-label="__('production_execution.fields.work_date')" />
            <x-forms.numeric-input class="form-control-sm" name="labor_details[{{ $index }}][work_segments][__DAY__][actual_hours]" :scale="8" :aria-label="__('production_execution.fields.actual_hours')" />
            <button class="btn btn-sm btn-outline-danger" type="button" data-remove-labor-day aria-label="{{ __('common.actions.delete') }}"><span class="fas fa-times"></span></button>
        </div>
    </template>
</div>
