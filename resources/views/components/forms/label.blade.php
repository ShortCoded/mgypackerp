@props([
    'for' => null,
    'label',
    'required' => false,
])

<label @if ($for) for="{{ $for }}" @endif {{ $attributes->class('form-label') }}>
    {{ $label }}
    @if ($required)
        <span class="text-danger" aria-hidden="true">*</span>
    @endif
</label>
