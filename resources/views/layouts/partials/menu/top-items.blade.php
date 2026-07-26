@foreach ($items as $item)
    @php
        $hasChildren = count($item['children']) > 0;
        $itemPath = [...($menuPath ?? []), $item['label'].'-'.$loop->index];
        $menuId = 'top-menu-'.substr(hash('sha256', implode('|', $itemPath)), 0, 16);
    @endphp

    @if ($hasChildren)
        <li class="nav-item dropdown">
            <a class="nav-link dropdown-toggle {{ $item['active'] ? 'active' : '' }}" id="{{ $menuId }}" href="#" role="button" data-bs-toggle="dropdown" aria-haspopup="true" aria-expanded="{{ $item['open'] ? 'true' : 'false' }}">
                {{ $item['text'] }}
            </a>
            <div class="dropdown-menu dropdown-caret dropdown-menu-card border-0 mt-0" aria-labelledby="{{ $menuId }}">
                <div class="bg-white dark__bg-1000 rounded-3 py-2">
                    @include('layouts.partials.menu.top-dropdown-items', ['items' => $item['children'], 'menuPath' => $itemPath])
                </div>
            </div>
        </li>
    @else
        <li class="nav-item">
            <a class="nav-link {{ $item['active'] ? 'active' : '' }}" href="{{ $item['url'] }}">
                {{ $item['text'] }}
            </a>
        </li>
    @endif
@endforeach
