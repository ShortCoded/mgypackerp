@foreach ($items as $item)
    @php
        $hasChildren = count($item['children']) > 0;
        $menuId = 'top-dropdown-menu-' . \Illuminate\Support\Str::slug($item['label']) . '-' . substr(md5($item['text'] . $loop->index), 0, 8);
    @endphp

    @if ($hasChildren)
        <div class="dropend">
            <a class="dropdown-item dropdown-toggle {{ $item['active'] ? 'active' : '' }}" id="{{ $menuId }}" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="{{ $item['open'] ? 'true' : 'false' }}">
                {{ $item['text'] }}
            </a>
            <div class="dropdown-menu border-0 shadow-sm" aria-labelledby="{{ $menuId }}">
                @include('layouts.partials.menu.top-dropdown-items', ['items' => $item['children']])
            </div>
        </div>
    @else
        <a class="dropdown-item link-600 fw-medium {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}">
            {{ $item['text'] }}
        </a>
    @endif
@endforeach
