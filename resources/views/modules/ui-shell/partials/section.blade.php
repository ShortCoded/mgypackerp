<section class="erp-ui-shell-section mb-3" data-section-key="{{ $section['key'] }}">
    <div class="d-flex align-items-center justify-content-between mb-2">
        <h6 class="mb-0 text-700">{{ $definition->localized($section['title']) }}</h6>
    </div>
    <div class="row g-3 align-items-start">
        @foreach ($section['fields'] ?? [] as $field)
            @include('modules.ui-shell.partials.field', [
                'field' => $field,
                'namePrefix' => $namePrefix ?? '',
                'fieldIdPrefix' => $fieldIdPrefix ?? $section['key'],
            ])
        @endforeach
    </div>
</section>
