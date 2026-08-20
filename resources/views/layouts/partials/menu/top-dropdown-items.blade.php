@foreach ($items as $item)
    @php
        $hasChildren = count($item['children']) > 0;
        $itemPath = [...($menuPath ?? []), $item['key']];
        $menuId = 'top-dropdown-menu-'.substr(hash('sha256', implode('|', $itemPath)), 0, 16);
    @endphp

    @if ($hasChildren)
        <div class="dropend erp-top-nav-branch" data-menu-depth="{{ count($itemPath) - 1 }}">
            <a class="dropdown-item dropdown-toggle erp-top-nav-item {{ $item['active'] ? 'active' : '' }}" id="{{ $menuId }}" href="#" role="button" data-bs-toggle="dropdown" data-bs-display="static" aria-haspopup="true" aria-expanded="{{ $item['open'] ? 'true' : 'false' }}">
                {{ $item['text'] }}
            </a>
            <div class="dropdown-menu erp-top-nav-submenu" aria-labelledby="{{ $menuId }}">
                @include('layouts.partials.menu.top-dropdown-items', ['items' => $item['children'], 'menuPath' => $itemPath])
            </div>
        </div>
    @else
        <a class="dropdown-item erp-top-nav-item {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif>
            {{ $item['text'] }}
        </a>
    @endif
@endforeach
