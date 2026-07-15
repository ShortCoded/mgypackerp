@props([
    'href',
    'permission' => null,
    'label' => __('common.add_new_record'),
    'id' => 'btn_add_record',
    'icon' => 'fas fa-plus',
])

@if ($permission === null || auth()->user()?->can($permission))
    @php
        $shortcutTitle = __('common.shortcuts.add_new_record');
    @endphp

    <a {{ $attributes->class(['btn', 'btn-falcon-default', 'btn-sm'])->merge([
        'id' => $id,
        'href' => $href,
        'title' => $shortcutTitle,
        'aria-label' => $shortcutTitle,
    ]) }}>
        @if ($icon)
            <span class="{{ $icon }}" data-fa-transform="shrink-3 down-2"></span>
        @endif
        <span class="d-none d-sm-inline-block ms-1">{{ $label }}</span>
    </a>
@endif
