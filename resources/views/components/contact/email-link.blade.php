@props([
    'email' => null,
    'class' => '',
])

@php
    $emailValue = trim((string) $email);
@endphp

@if ($emailValue !== '')
    <a href="mailto:{{ $emailValue }}" class="{{ $class }}">{{ $emailValue }}</a>
@endif
