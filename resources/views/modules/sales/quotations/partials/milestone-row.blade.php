<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ $row['title'] ?? __('common.empty_value') }}</div>
    @else
        <input class="form-control" name="payment_milestones[{{ $index }}][title]" type="text" value="{{ $row['title'] ?? '' }}">
        <div class="invalid-feedback d-block" data-error-for="payment_milestones.{{ $index }}.title"></div>
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ $row['description'] ?? __('common.empty_value') }}</div>
    @else
        <input class="form-control" name="payment_milestones[{{ $index }}][description]" type="text" value="{{ $row['description'] ?? '' }}">
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext text-center" dir="ltr">{{ $row['percentage'] ?? '' }}</div>
    @else
        <input class="form-control text-center" name="payment_milestones[{{ $index }}][percentage]" type="number" min="0" max="100" step="0.0001" value="{{ $row['percentage'] ?? '' }}" dir="ltr">
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext text-center" dir="ltr">{{ $row['amount'] ?? '' }}</div>
    @else
        <input class="form-control text-center" name="payment_milestones[{{ $index }}][amount]" type="number" min="0" step="0.0001" value="{{ $row['amount'] ?? '' }}" dir="ltr">
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ isset($row['due_type']) && $row['due_type'] ? __('quotations.due_types.'.$row['due_type']) : __('common.empty_value') }}</div>
    @else
        <select class="form-select" name="payment_milestones[{{ $index }}][due_type]">
            <option value="">{{ __('common.empty_value') }}</option>
            @foreach (\Modules\Sales\Models\QuotationPaymentMilestone::DueTypes as $type)
                <option value="{{ $type }}" @selected(($row['due_type'] ?? null) === $type)>{{ __("quotations.due_types.{$type}") }}</option>
            @endforeach
        </select>
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext text-center" dir="ltr">{{ $row['due_date'] ?? '' }}</div>
    @else
        <input class="form-control text-center js-date-picker" name="payment_milestones[{{ $index }}][due_date]" type="text" value="{{ $row['due_date'] ?? '' }}" data-date-format="{{ $dates->jsDateFormat() }}" data-locale="{{ app()->getLocale() }}" autocomplete="off" dir="ltr">
    @endif
</td>
<td>
    @if ($isReadonly)
        <div class="form-control-plaintext">{{ $row['notes'] ?? __('common.empty_value') }}</div>
    @else
        <input class="form-control" name="payment_milestones[{{ $index }}][notes]" type="text" value="{{ $row['notes'] ?? '' }}">
    @endif
</td>
@unless ($isReadonly)
    <td class="text-center">
        <button class="btn btn-link text-danger p-0 js-quotation-remove-milestone" type="button"><span class="fas fa-trash-alt"></span></button>
    </td>
@endunless
