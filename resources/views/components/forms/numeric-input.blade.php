@props([
    'allowNegative' => null,
    'id' => null,
    'max' => null,
    'min' => null,
    'name',
    'required' => false,
    'scale' => null,
    'step' => null,
    'value' => null,
])

@php
    $numericFormatter = app(\Modules\Core\Services\NumericFormatService::class);
    $formattedValue = $numericFormatter->formatForInput($value);
@endphp

<input
    {{ $attributes->class('form-control') }}
    @if ($id) id="{{ $id }}" @endif
    name="{{ $name }}"
    type="text"
    inputmode="{{ $scale === 0 ? 'numeric' : 'decimal' }}"
    dir="ltr"
    value="{{ $formattedValue }}"
    data-numeric-input
    @if ($scale !== null) data-numeric-scale="{{ $scale }}" @endif
    @if ($allowNegative !== null) data-numeric-allow-negative="{{ $allowNegative ? 'true' : 'false' }}" @endif
    @if ($min !== null) min="{{ $min }}" data-numeric-min="{{ $min }}" @endif
    @if ($max !== null) max="{{ $max }}" data-numeric-max="{{ $max }}" @endif
    @if ($step !== null) step="{{ $step }}" @endif
    @required($required)>
