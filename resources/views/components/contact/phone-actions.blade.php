@props([
    'phone' => null,
    'class' => '',
])

@php
    $phoneValue = trim((string) $phone);
    $phoneDigits = preg_replace('/\D+/', '', $phoneValue) ?: '';
    $phoneTelValue = $phoneDigits !== '' ? ((str_starts_with($phoneValue, '+') ? '+' : '') . $phoneDigits) : '';
    $whatsappUrl = $phoneDigits !== '' ? 'https://wa.me/' . $phoneDigits : null;
    $phoneUrl = $phoneTelValue !== '' ? 'tel:' . $phoneTelValue : null;
    $popoverContent = '<div class="d-flex gap-2">';

    if ($whatsappUrl) {
        $popoverContent .= '<a class="btn btn-falcon-default btn-sm" target="_blank" rel="noopener" href="' . e($whatsappUrl) . '"><span class="fab fa-whatsapp text-success"></span><span class="ms-1">' . e(__('common.actions.whatsapp')) . '</span></a>';
    }

    if ($phoneUrl) {
        $popoverContent .= '<a class="btn btn-falcon-default btn-sm" href="' . e($phoneUrl) . '"><span class="fas fa-phone"></span><span class="ms-1">' . e(__('common.actions.call')) . '</span></a>';
    }

    $popoverContent .= '</div>';
@endphp

@if ($phoneDigits !== '')
    <button type="button"
            class="{{ trim('btn btn-link p-0 align-baseline js-user-phone-contact ' . $class) }}"
            data-whatsapp-url="{{ $whatsappUrl }}"
            data-phone-url="{{ $phoneUrl }}"
            data-bs-toggle="popover"
            data-bs-html="true"
            data-bs-placement="bottom"
            data-bs-content="{{ $popoverContent }}">
        {{ $phoneValue }}
    </button>
@endif
