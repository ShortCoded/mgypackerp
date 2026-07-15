@php
    $roles = $user->relationLoaded('roles') ? $user->roles : collect();
@endphp

@if ($roles->isNotEmpty())
    <div class="d-flex flex-wrap gap-1">
        @foreach ($roles->take(3) as $role)
            <span class="badge rounded-pill badge-subtle-primary" title="{{ $role->doc_num ? $role->name . ' - ' . $role->doc_num : $role->name }}">
                {{ $role->name }}
            </span>
        @endforeach

        @if ($roles->count() > 3)
            <span class="badge rounded-pill badge-subtle-secondary" title="{{ $roles->skip(3)->pluck('name')->implode(', ') }}">
                +{{ $roles->count() - 3 }}
            </span>
        @endif
    </div>
@endif
