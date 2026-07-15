@foreach ($items as $item)
    @php
        $hasChildren = count($item['children']) > 0;
        $menuId = 'vertical-menu-' . \Illuminate\Support\Str::slug($item['label']) . '-' . substr(md5($item['text'] . $loop->index), 0, 8);
    @endphp

    <li class="nav-item">
        @if ($hasChildren)
            <a class="nav-link dropdown-indicator {{ $item['active'] ? 'active' : '' }}" href="#{{ $menuId }}" role="button" data-bs-toggle="collapse" aria-expanded="{{ $item['open'] ? 'true' : 'false' }}" aria-controls="{{ $menuId }}">
                <div class="d-flex align-items-center">
                    <span class="nav-link-icon"><span class="{{ $item['icon_class'] }}"></span></span>
                    <span class="nav-link-text ps-1">{{ $item['text'] }}</span>
                </div>
            </a>
            <ul class="nav collapse {{ $item['open'] ? 'show' : '' }}" id="{{ $menuId }}">
                @include('layouts.partials.menu.vertical-items', ['items' => $item['children']])
            </ul>
        @else
            <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}">
                <div class="d-flex align-items-center">
                    <span class="nav-link-icon"><span class="{{ $item['icon_class'] }}"></span></span>
                    <span class="nav-link-text ps-1">{{ $item['text'] }}</span>
                </div>
            </a>
        @endif
    </li>
@endforeach
