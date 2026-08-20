@foreach ($items as $item)
    @php
        $hasChildren = count($item['children']) > 0;
        $itemPath = [...($menuPath ?? []), $item['key']];
        $menuId = 'top-menu-'.substr(hash('sha256', implode('|', $itemPath)), 0, 16);
    @endphp

    @if ($hasChildren)
        <li class="nav-item dropdown erp-top-nav-root">
            <a class="nav-link dropdown-toggle {{ $item['active'] ? 'active' : '' }}" id="{{ $menuId }}" href="#" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="{{ $item['open'] ? 'true' : 'false' }}">
                {{ $item['text'] }}
            </a>
            <div class="dropdown-menu dropdown-caret dropdown-menu-card erp-top-nav-menu mt-0" aria-labelledby="{{ $menuId }}">
                <div class="erp-top-nav-panel">
                    @include('layouts.partials.menu.top-dropdown-items', ['items' => $item['children'], 'menuPath' => $itemPath])
                </div>
            </div>
        </li>
    @else
        <li class="nav-item">
            <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}" @if($item['active']) aria-current="page" @endif>
                {{ $item['text'] }}
            </a>
        </li>
    @endif
@endforeach
