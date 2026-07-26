@props([
    'as' => 'input',
    'dir' => null,
    'empty' => __('common.empty_value'),
    'errorFor' => null,
    'for' => null,
    'href' => null,
    'inputClass' => '',
    'label' => null,
    'link' => false,
    'numeric' => false,
    'rel' => 'noopener noreferrer',
    'required' => false,
    'rows' => 4,
    'target' => '_blank',
    'value' => null,
])

@php
    $rawStringValue = trim((string) $value);
    $hasValue = $value !== null && $rawStringValue !== '';
    $stringValue = $numeric && $hasValue
        ? app(\Modules\Core\Services\NumericFormatService::class)->formatForInput($value)
        : $rawStringValue;
    $displayValue = $hasValue ? $stringValue : $empty;
    $effectiveDirection = $dir ?: ($numeric ? 'ltr' : null);
    $controlClass = trim('form-control '.$inputClass.' erp-view-field-control');
    $slotHtml = trim($slot->toHtml());
    $hasSlot = $slotHtml !== '';
    $linkHref = $href ?: ($hasValue ? $stringValue : null);
@endphp

<div {{ $attributes->class('erp-view-field') }}>
    @if ($label)
        <x-forms.label :for="$for" :label="$label" :required="$required" />
    @endif

    @if ($as === 'textarea')
        <textarea
            @if ($for) id="{{ $for }}" @endif
            class="{{ $controlClass }} erp-view-field-textarea"
            rows="{{ $rows }}"
            @if ($effectiveDirection) dir="{{ $effectiveDirection }}" @endif
            readonly
            disabled>{{ $displayValue }}</textarea>
    @elseif ($as === 'display' || $link || $hasSlot)
        <div
            @if ($for) id="{{ $for }}" @endif
            class="{{ $controlClass }} erp-view-field-display"
            @if ($effectiveDirection) dir="{{ $effectiveDirection }}" @endif>
            @if ($hasSlot)
                {!! $slotHtml !!}
            @elseif ($link && $hasValue && $linkHref)
                <a href="{{ $linkHref }}" target="{{ $target }}" rel="{{ $rel }}">{{ $displayValue }}</a>
            @else
                <span @class(['erp-view-empty-value text-600' => ! $hasValue])>{{ $displayValue }}</span>
            @endif
        </div>
    @else
        <input
            @if ($for) id="{{ $for }}" @endif
            type="text"
            class="{{ $controlClass }}"
            value="{{ $displayValue }}"
            @if ($effectiveDirection) dir="{{ $effectiveDirection }}" @endif
            readonly
            disabled>
    @endif

    @if ($errorFor)
        <div class="invalid-feedback d-block" data-error-for="{{ $errorFor }}"></div>
    @endif
</div>
