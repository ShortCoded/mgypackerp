@if (isset($breadcrumbs))
    <nav aria-label="{{ __('layout.breadcrumb') }}" class="mb-3">
        <ol class="breadcrumb" style="--falcon-breadcrumb-divider: '/';">
            @foreach ($breadcrumbs as $breadcrumb)
                @php
                    $label = (string) ($breadcrumb['label'] ?? '');
                    $label = filter_var($label, FILTER_VALIDATE_URL) ? __('layout.breadcrumb') : $label;
                @endphp
                <li class="breadcrumb-item {{ $breadcrumb['active'] ? 'active' : '' }}" @if ($breadcrumb['active']) aria-current="page" @endif>
                    @if (! $breadcrumb['active'] && ! empty($breadcrumb['url']))
                        <a href="{{ $breadcrumb['url'] }}">{{ $label }}</a>
                    @else
                        {{ $label }}
                    @endif
                </li>
            @endforeach
        </ol>
    </nav>
@elseif (View::hasSection('breadcrumb'))
    <nav aria-label="{{ __('layout.breadcrumb') }}" class="mb-3">
        <ol class="breadcrumb" style="--falcon-breadcrumb-divider: '/';">
            @yield('breadcrumb')
        </ol>
    </nav>
@endif
