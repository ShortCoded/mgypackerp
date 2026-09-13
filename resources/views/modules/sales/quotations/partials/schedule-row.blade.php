@php($numbers = app(\Modules\Core\Services\NumericFormatService::class))

<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ $row['phase_name'] ?? __('common.empty_value') }}</div>
    @else
        <x-forms.input class="form-control" name="execution_schedule_lines[{{ $index }}][phase_name]" type="text" value="{{ $row['phase_name'] ?? '' }}" />
        <div class="invalid-feedback d-block" data-error-for="execution_schedule_lines.{{ $index }}.phase_name"></div>
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ $row['description'] ?? __('common.empty_value') }}</div>
    @else
        <x-forms.input class="form-control" name="execution_schedule_lines[{{ $index }}][description]" type="text" value="{{ $row['description'] ?? '' }}" />
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext text-center" dir="ltr">{{ $row['start_date'] ?? '' }}</div>
    @else
        <x-forms.date-input class="form-control text-center js-date-picker" name="execution_schedule_lines[{{ $index }}][start_date]" type="text" value="{{ $row['start_date'] ?? '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" />
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext text-center" dir="ltr">{{ $row['end_date'] ?? '' }}</div>
    @else
        <x-forms.date-input class="form-control text-center js-date-picker" name="execution_schedule_lines[{{ $index }}][end_date]" type="text" value="{{ $row['end_date'] ?? '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr" />
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext text-center" dir="ltr">{{ $numbers->format($row['duration_days'] ?? null) }}</div>
    @else
        <x-forms.numeric-input class="text-center" :name="'execution_schedule_lines['.$index.'][duration_days]'" :value="$row['duration_days'] ?? ''" :scale="0" min="0" step="1" />
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ $row['responsibility'] ?? __('common.empty_value') }}</div>
    @else
        <x-forms.input class="form-control" name="execution_schedule_lines[{{ $index }}][responsibility]" type="text" value="{{ $row['responsibility'] ?? '' }}" />
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ $row['notes'] ?? __('common.empty_value') }}</div>
    @else
        <x-forms.input class="form-control" name="execution_schedule_lines[{{ $index }}][notes]" type="text" value="{{ $row['notes'] ?? '' }}" />
    @endif
</td>
@unless ($isReadonly)
    <td class="text-center">
        <button class="btn btn-link text-danger p-0 js-quotation-remove-schedule" type="button"><span class="fas fa-trash-alt"></span></button>
    </td>
@endunless
