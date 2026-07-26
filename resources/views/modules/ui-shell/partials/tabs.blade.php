@php($tabs = $screen['tabs'] ?? [])

<ul class="nav nav-tabs erp-ui-shell-tabs" id="erp-ui-shell-tabs" role="tablist">
    @foreach ($tabs as $tabIndex => $tab)
        <li class="nav-item" role="presentation">
            <button class="nav-link @if ($tabIndex === 0) active @endif"
                id="erp-ui-tab-{{ $tab['key'] }}"
                data-bs-toggle="tab"
                data-bs-target="#erp-ui-pane-{{ $tab['key'] }}"
                type="button"
                role="tab"
                aria-controls="erp-ui-pane-{{ $tab['key'] }}"
                aria-selected="{{ $tabIndex === 0 ? 'true' : 'false' }}">
                {{ $definition->localized($tab['title']) }}
            </button>
        </li>
    @endforeach
</ul>

<div class="tab-content border border-top-0 rounded-bottom p-3 erp-ui-shell-tab-content" id="erp-ui-shell-tab-content">
    @foreach ($tabs as $tabIndex => $tab)
        <div class="tab-pane fade @if ($tabIndex === 0) show active @endif"
            id="erp-ui-pane-{{ $tab['key'] }}"
            role="tabpanel"
            aria-labelledby="erp-ui-tab-{{ $tab['key'] }}"
            tabindex="0">
            @foreach ($tab['sections'] ?? [] as $section)
                @include('modules.ui-shell.partials.section', [
                    'section' => $section,
                    'namePrefix' => '',
                    'fieldIdPrefix' => $tab['key'],
                ])
            @endforeach

            @foreach ($tab['repeaters'] ?? [] as $repeater)
                @include('modules.ui-shell.partials.repeater', [
                    'repeater' => $repeater,
                    'namePrefix' => '',
                    'fieldIdPrefix' => $tab['key'],
                ])
            @endforeach
        </div>
    @endforeach
</div>
